'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { createConfig } = require('../src/config');
const { openStore, COLLECTIONS, mysqlConnectionOptions } = require('../src/platform/store');

// A real rehearsal against a throwaway MySQL/MariaDB database. CI has no MySQL
// service, so it only runs when explicitly pointed at a scratch database whose
// name ends in _test. Never point this at the live alphadve_alpha_school database.
const env = process.env;
const configured = Boolean(env.TEST_DB_HOST && env.TEST_DB_NAME && env.TEST_DB_USER);
const scratch = configured && /_test$/.test(env.TEST_DB_NAME);
const skip = configured
  ? (scratch ? false : 'TEST_DB_NAME must end in _test so this rehearsal can never touch live school data.')
  : 'Set TEST_DB_HOST, TEST_DB_NAME (ending _test), TEST_DB_USER and TEST_DB_PASSWORD to run the real MySQL rehearsal.';

test('real MySQL/MariaDB store rehearsal: schema, revisions, uniqueness and audit triggers', { skip, timeout: 120000 }, async t => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-mysql-it-'));
  const config = createConfig({
    NODE_ENV: 'test', DATA_DIR: dir, BASE_URL: 'http://localhost:3000',
    DB_HOST: env.TEST_DB_HOST, DB_PORT: env.TEST_DB_PORT || '3306', DB_NAME: env.TEST_DB_NAME,
    DB_USER: env.TEST_DB_USER, DB_PASSWORD: env.TEST_DB_PASSWORD || '', DB_SOCKET: env.TEST_DB_SOCKET || ''
  });
  assert.equal(config.mysql.database, env.TEST_DB_NAME);
  const mysql = require('mysql2/promise');
  let store;
  t.after(async () => {
    if (store) await store.close().catch(() => {});
    const connection = await mysql.createConnection(mysqlConnectionOptions(config.mysql, { database: config.mysql.database }));
    try {
      await connection.query('DROP TRIGGER IF EXISTS adsp_audit_logs_no_update');
      await connection.query('DROP TRIGGER IF EXISTS adsp_audit_logs_no_delete');
      for (const name of COLLECTIONS) await connection.query(`DELETE FROM adsp_${name}`);
    } finally { await connection.end(); }
    // Recreate the audit triggers so the scratch database is reusable.
    const reopened = await openStore(config);
    await reopened.close();
    await fs.rm(dir, { recursive: true, force: true });
  });

  store = await openStore(config);
  assert.equal(store.kind, 'mysql');
  assert.equal(await store.health(), true);

  const username = 'rehearsal.' + crypto.randomBytes(4).toString('hex');
  const user = await store.run(tx => tx.insert('users', { id: 'USR-REHEARSAL-' + username, username, name: 'Synthetic Rehearsal', roles: ['teacher'] }));
  assert.equal(user.revision, 1);
  assert.equal((await store.run(tx => tx.get('users', user.id))).username, username);
  await assert.rejects(store.run(tx => tx.insert('users', { id: 'USR-DUPLICATE-' + username, username })), error => error.status === 409 && error.code === 'VERSION_CONFLICT');

  const updated = await store.run(tx => tx.update('users', { ...user, name: 'Synthetic Rehearsal Updated' }, user.revision));
  assert.equal(updated.revision, 2);
  await assert.rejects(store.run(tx => tx.update('users', { ...user, name: 'Stale write' }, user.revision)), error => error.status === 409);

  const sequence = Number(String(Date.now()).slice(-9));
  await store.run(tx => tx.insert('audit_logs', { id: 'AUD-REHEARSAL-' + sequence, sequence, action: 'rehearsal.integration' }));
  await assert.rejects(store.run(tx => tx.insert('audit_logs', { id: 'AUD-REHEARSAL-DUP', sequence, action: 'rehearsal.duplicate' })), error => error.status === 409);

  // The trigger blocks a direct SQL rewrite even when the application is bypassed.
  // A host that withholds TRIGGER (or MySQL 8 with binary logging and
  // log_bin_trust_function_creators=0) leaves only the application guard; report
  // that honestly instead of failing the school's rehearsal.
  const connection = await mysql.createConnection(mysqlConnectionOptions(config.mysql, { database: config.mysql.database }));
  try {
    const [[{ present }]] = await connection.query("SELECT COUNT(*) AS present FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('adsp_audit_logs_no_update', 'adsp_audit_logs_no_delete')");
    if (Number(present) === 2) {
      await assert.rejects(connection.query("UPDATE adsp_audit_logs SET payload = '{}' WHERE id = ?", ['AUD-REHEARSAL-' + sequence]), /append-only/);
      await assert.rejects(connection.query('DELETE FROM adsp_audit_logs WHERE id = ?', ['AUD-REHEARSAL-' + sequence]), /append-only/);
    } else t.diagnostic('Audit triggers are not installed on this database; only the application append-only guard was exercised. Grant TRIGGER or set log_bin_trust_function_creators=1, then repeat.');
  } finally { await connection.end(); }

  await store.run(tx => tx.remove('users', user.id));
  assert.equal(await store.run(tx => tx.get('users', user.id)), null);
});
