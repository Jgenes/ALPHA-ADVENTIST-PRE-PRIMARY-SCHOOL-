'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { createConfig } = require('../src/config');
const { createApplication } = require('../server');
const { createUserRecord } = require('../src/platform/auth');
const { verifyAudit, audit } = require('../src/platform/audit');
const { openStore } = require('../src/platform/store');
const { COLLECTIONS, table, indexGroups } = require('../src/platform/collections');
const { mysqlConfig } = require('../src/platform/mysql-config');
const { createTableSql, auditTriggers, decode, translateError, MysqlUnit } = require('../src/platform/mysql-store');
const { Agent } = require('./helpers');

test('MySQL connection settings are explicit and fail closed', () => {
  assert.equal(mysqlConfig({}), null);
  assert.equal(mysqlConfig({ NODE_ENV: 'test' }), null);
  const parsed = mysqlConfig({ MYSQL_URL: 'mysql://alpha%20user:s%30cret@db.internal:3307/alpha_school', MYSQL_SSL_MODE: 'required' });
  assert.deepEqual(
    { host: parsed.options.host, port: parsed.options.port, user: parsed.options.user, password: parsed.options.password, database: parsed.database, sslMode: parsed.sslMode },
    { host: 'db.internal', port: 3307, user: 'alpha user', password: 's0cret', database: 'alpha_school', sslMode: 'required' }
  );
  assert.equal(parsed.options.multipleStatements, false);
  assert.equal(parsed.options.charset, 'utf8mb4');
  assert.equal(parsed.connectionLimit, 15);
  // Individual settings win over the connection string so a managed host can supply
  // the secret separately from the address.
  const discrete = mysqlConfig({ MYSQL_URL: 'mysql://alpha:old@db.internal:3306/alpha_school', MYSQL_USER: 'school', MYSQL_PORT: '3399' });
  assert.equal(discrete.options.user, 'school');
  assert.equal(discrete.options.port, 3399);
  assert.throws(() => mysqlConfig({ MYSQL_HOST: 'db.internal', MYSQL_USER: 'alpha' }), /missing: MYSQL_DB_NAME/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://db.internal/alpha_school' }), /missing: MYSQL_USER/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'https://wrong.example' }), /mysql:\/\/ scheme/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'not a url' }), /full mysql:\/\/ connection string/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school', MYSQL_SSL_MODE: 'sometimes' }), /MYSQL_SSL_MODE must be one of/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school', MYSQL_POOL_SIZE: '0' }), /MYSQL_POOL_SIZE/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school', MYSQL_DB_NAME: 'other_school' }), /disagrees/);
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/bad-name!' }), /1–63 characters/);
  assert.throws(() => mysqlConfig({ MYSQL_HOST: 'db.internal', MYSQL_USER: 'alpha', MYSQL_DB_NAME: 'school', MYSQL_PASSWORD_FILE: path.join(os.tmpdir(), 'missing-password-file') }), /does not exist/);
  // Development may stay unencrypted; a real deployment may not.
  assert.equal(mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school' }, { production: false }).sslMode, 'disabled');
  assert.equal(mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school' }, { production: true }).sslMode, 'verify-full');
  assert.throws(() => mysqlConfig({ MYSQL_URL: 'mysql://alpha@db.internal/alpha_school', MYSQL_SSL_MODE: 'disabled' }, { production: true }), /must be encrypted/);
  const socket = mysqlConfig({ MYSQL_SOCKET: '/tmp/mysql.sock', MYSQL_USER: 'alpha', MYSQL_DB_NAME: 'alpha_school' });
  assert.equal(socket.options.socketPath, '/tmp/mysql.sock');
  assert.throws(() => mysqlConfig({ MYSQL_SOCKET: '/tmp/mysql.sock', MYSQL_USER: 'alpha', MYSQL_DB_NAME: 'alpha_school', MYSQL_SSL_MODE: 'required' }), /cannot be combined/);
  const secretFile = path.join(os.tmpdir(), 'alpha-mysql-password-' + crypto.randomBytes(6).toString('hex'));
  return fs.writeFile(secretFile, 'from-a-file\n', { mode: 0o600 }).then(async () => {
    try {
      assert.equal(mysqlConfig({ MYSQL_HOST: 'db.internal', MYSQL_USER: 'alpha', MYSQL_DB_NAME: 'alpha_school', MYSQL_PASSWORD_FILE: secretFile }).options.password, 'from-a-file');
      await fs.chmod(secretFile, 0o644);
      assert.throws(() => mysqlConfig({ MYSQL_HOST: 'db.internal', MYSQL_USER: 'alpha', MYSQL_DB_NAME: 'alpha_school', MYSQL_PASSWORD_FILE: secretFile }), /private readable file/);
    } finally { await fs.rm(secretFile, { force: true }); }
  });
});

test('MySQL schema keeps the platform constraints in the database', () => {
  const users = createTableSql('users');
  assert.match(users, /^CREATE TABLE IF NOT EXISTS `adsp_users`/);
  assert.match(users, /payload JSON NOT NULL/);
  assert.match(users, /ENGINE=InnoDB/);
  assert.match(users, /UNIQUE KEY adsp_users_username_uq \(`idx_username`\)/);
  assert.match(users, /idx_username VARCHAR\(191\) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS/);
  assert.match(users, /id VARCHAR\(191\) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL/);
  assert.match(users, /PRIMARY KEY \(id\)/);
  // A JSON null must not become the text "null", or two unlinked records would collide.
  assert.match(createTableSql('student_profiles'), /JSON_TYPE\(JSON_EXTRACT\(payload, '\$\.userId'\)\) = 'NULL' THEN NULL/);
  for (const name of COLLECTIONS) {
    const sql = createTableSql(name);
    assert.equal(sql.includes('`' + table(name) + '`'), true, name);
    for (const { fields } of indexGroups(name)) {
      for (const field of fields) assert.equal(sql.includes('JSON_EXTRACT(payload, \'$.' + field + '\')'), true, name + ':' + field);
    }
    // Every statement is one self-contained CREATE TABLE; no string concatenation of
    // request data can reach it.
    assert.equal(/;/.test(sql), false, name);
  }
  const triggers = auditTriggers();
  assert.equal(triggers.length, 2);
  assert.deepEqual(triggers.map(item => item.name), ['adsp_audit_no_update', 'adsp_audit_no_delete']);
  for (const trigger of triggers) assert.match(trigger.sql, /adsp_audit_logs/);
  assert.equal(createTableSql('attendance_records').includes('UNIQUE KEY adsp_attendance_records_studentid_date_uq'), true);
  assert.equal(createTableSql('sessions').includes('KEY adsp_sessions_expiresat'), true);
  assert.equal(createTableSql('sessions').includes('UNIQUE KEY adsp_sessions'), false);
});

test('MySQL driver errors map onto the platform conflict answer', () => {
  const deadlock = translateError(Object.assign(new Error('Deadlock found when trying to get lock'), { code: 'ER_LOCK_DEADLOCK' }));
  assert.equal(deadlock.status, 409);
  assert.equal(deadlock.code, 'VERSION_CONFLICT');
  assert.equal(translateError(Object.assign(new Error('timeout'), { code: 'ER_LOCK_WAIT_TIMEOUT' })).status, 409);
  const trigger = translateError(Object.assign(new Error('Trigger limits exceeded'), { code: 'ER_TRIGGER', sqlMessage: 'Audit records are append-only' }));
  assert.equal(trigger.status, 409);
  assert.match(trigger.message, /append-only/);
  const other = new Error('access denied');
  assert.equal(translateError(other), other);
  assert.deepEqual(decode('{"a":1}'), { a: 1 });
  assert.deepEqual(decode({ a: 1 }), { a: 1 });
  assert.equal(decode(null), null);
});

// A stub connection proves the SQL each store operation issues, without a server.
function fakeConnection(log, results = {}) {
  return {
    async query(sql, parameters) {
      log.push({ sql, parameters });
      const key = Object.keys(results).find(name => sql.includes(name));
      if (key) {
        const value = results[key];
        if (value instanceof Error) throw value;
        return [value];
      }
      return sql.trim().startsWith('SELECT') ? [[]] : [{ affectedRows: 1 }];
    }
  };
}
test('the MySQL unit reads and writes documents with the revision guard', async () => {
  const log = [];
  const unit = new MysqlUnit(fakeConnection(log, {
    'WHERE id = ? LIMIT 1': [{ payload: '{"id":"u1","revision":3,"username":"head"}' }],
    'ORDER BY seq': [{ payload: { id: 'u1', revision: 3 } }, { payload: '{"id":"u2","revision":1}' }]
  }));
  assert.deepEqual(await unit.get('users', 'u1'), { id: 'u1', revision: 3, username: 'head' });
  assert.deepEqual(await unit.list('users'), [{ id: 'u1', revision: 3 }, { id: 'u2', revision: 1 }]);
  const inserted = await unit.insert('users', { id: 'u9', username: 'new' });
  assert.equal(inserted.revision, 1);
  assert.deepEqual(log.at(-1).parameters, ['u9', 1, JSON.stringify(inserted)]);
  const updated = await unit.update('users', { id: 'u9', username: 'new', revision: 4 }, 4);
  assert.equal(updated.revision, 5);
  assert.match(log.at(-1).sql, /WHERE id = \? AND revision = \?/);
  assert.deepEqual(log.at(-1).parameters, [5, JSON.stringify(updated), 'u9', 4]);
  await unit.remove('users', 'u9');
  assert.match(log.at(-1).sql, /^DELETE FROM `adsp_users` WHERE id = \?$/);
  // The append-only rule is enforced before SQL, and by database triggers.
  await assert.rejects(unit.update('audit_logs', { id: 'AUD-1', revision: 1 }), /append-only/);
  await assert.rejects(unit.remove('audit_logs', 'AUD-1'), /append-only/);
  const rejecting = new MysqlUnit(fakeConnection(log, { 'INSERT INTO': Object.assign(new Error('Duplicate entry'), { code: 'ER_DUP_ENTRY' }) }));
  await assert.rejects(rejecting.insert('users', { id: 'u9', username: 'new' }), error => error.code === 'VERSION_CONFLICT' && error.status === 409);
  const stale = new MysqlUnit(fakeConnection(log, { 'UPDATE `adsp_users`': [{ affectedRows: 0 }] }));
  await assert.rejects(stale.update('users', { id: 'u9', revision: 2 }, 2), error => error.code === 'VERSION_CONFLICT');
});

const schemaName = 'adsp_test_' + crypto.randomBytes(5).toString('hex');
const targetUrl = () => {
  const base = new URL(process.env.MYSQL_URL);
  base.pathname = '/' + schemaName;
  base.search = '';
  return base.toString();
};
test('the platform runs on a real MySQL schema', {
  skip: process.env.MYSQL_URL ? false : 'Set MYSQL_URL (plus MYSQL_USER/MYSQL_PASSWORD if the URL omits them) to exercise the MySQL store end to end.',
  timeout: 120000
}, async t => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-mysql-'));
  const config = createConfig({
    NODE_ENV: 'test', DATA_DIR: dir, BASE_URL: 'https://school.example.test', PORT: '0',
    MYSQL_URL: targetUrl(), MYSQL_DB_NAME: schemaName, MYSQL_CREATE_SCHEMA: 'true', MYSQL_SSL_MODE: 'disabled', MYSQL_POOL_SIZE: '6'
  });
  const app = await createApplication(config, { jobs: false });
  const mysql = require('mysql2/promise');
  t.after(async () => {
    await app.close();
    const admin = await mysql.createConnection({ ...config.mysql.options, database: undefined });
    try { await admin.query('DROP DATABASE IF EXISTS `' + schemaName + '`'); } finally { await admin.end(); }
    await fs.rm(dir, { recursive: true, force: true });
  });
  await new Promise(resolve => app.server.listen(0, '0.0.0.0', resolve));
  const base = `http://127.0.0.1:${app.server.address().port}`;
  assert.equal(app.store.kind, 'mysql');
  assert.equal(app.store.schema, schemaName);

  await t.test('tables, generated columns and audit triggers exist in the schema', async () => {
    const connection = await mysql.createConnection(config.mysql.options);
    try {
      const [tables] = await connection.query('SELECT TABLE_NAME AS name, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [schemaName]);
      assert.deepEqual(tables.map(row => row.name), COLLECTIONS.map(table).sort());
      assert.deepEqual([...new Set(tables.map(row => String(row.ENGINE).toUpperCase()))], ['INNODB']);
      const [columns] = await connection.query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [schemaName, 'adsp_users']);
      assert.deepEqual(columns.map(row => row.name).sort(), ['id', 'idx_username', 'payload', 'revision', 'seq']);
      const [indexes] = await connection.query('SELECT INDEX_NAME AS name, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [schemaName, 'adsp_users', 'idx_username']);
      assert.equal(indexes[0].name, 'adsp_users_username_uq');
      assert.equal(indexes[0].NON_UNIQUE, 0);
      const [triggers] = await connection.query('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [schemaName, 'adsp_audit_logs']);
      assert.deepEqual(triggers.map(row => row.name).sort(), ['adsp_audit_no_delete', 'adsp_audit_no_update']);
    } finally { await connection.end(); }
  });

  await t.test('records survive a separate connection, and stale writes conflict', async () => {
    const record = await app.store.run(tx => tx.insert('departments', { id: 'music', name: 'Music' }));
    assert.equal(record.revision, 1);
    // A second store instance is a second connection pool: anything it reads back was
    // committed to disk, not served from this process's memory.
    const other = await openStore(config);
    try {
      assert.equal(other.kind, 'mysql');
      assert.deepEqual(await other.run(tx => tx.get('departments', 'music')), { id: 'music', name: 'Music', revision: 1 });
      await assert.rejects(other.run(tx => tx.update('departments', { id: 'music', name: 'Wrong', revision: 99 }, 99)), error => error.code === 'VERSION_CONFLICT');
      assert.deepEqual(await other.run(tx => tx.update('departments', { id: 'music', name: 'Band' }, 1)), { id: 'music', name: 'Band', revision: 2 });
      assert.equal((await other.run(tx => tx.list('departments'))).some(item => item.name === 'Wrong'), false);
    } finally { await other.close(); }
  });

  await t.test('database uniqueness constraints reject a second same-named account', async () => {
    const password = crypto.randomBytes(24).toString('base64url') + 'aA1!';
    const first = await createUserRecord('duplicate.name', 'First Synthetic', ['teacher'], password);
    const second = await createUserRecord('duplicate.name', 'Second Synthetic', ['teacher'], password);
    await app.store.run(tx => tx.insert('users', first));
    await assert.rejects(app.store.run(tx => tx.insert('users', second)), error => error.code === 'VERSION_CONFLICT');
  });

  await t.test('a failed transaction leaves nothing behind', async () => {
    await assert.rejects(app.store.run(async tx => {
      await tx.insert('departments', { id: 'aborted', name: 'Aborted' });
      throw new Error('deliberate rollback');
    }), /deliberate rollback/);
    assert.equal(await app.store.run(tx => tx.get('departments', 'aborted')), null);
  });

  await t.test('the audit chain is append-only in the database itself', async () => {
    const before = await app.store.run(tx => tx.list('audit_logs'));
    await app.store.run(tx => audit(tx, config, { userId: 'synthetic' }, 'mysql.probe', 'system', 'probe'));
    const after = await app.store.run(tx => tx.list('audit_logs'));
    assert.equal(after.length, before.length + 1);
    assert.equal(verifyAudit(after, config.auditKey, await app.store.run(tx => tx.get('system_settings', 'audit-head'))), true);
    const connection = await mysql.createConnection(config.mysql.options);
    try {
      await assert.rejects(connection.query('UPDATE adsp_audit_logs SET revision = revision + 1 WHERE id = ' + JSON.stringify(after.at(-1).id)), /append-only/);
      await assert.rejects(connection.query('DELETE FROM adsp_audit_logs WHERE id = ' + JSON.stringify(after.at(-1).id)), /append-only/);
    } finally { await connection.end(); }
  });

  await t.test('concurrent audited writes extend the chain instead of colliding', async () => {
    const before = await app.store.run(tx => tx.get('system_settings', 'audit-head'));
    // Four transactions at once, on a pool of six: the audit head is the one row every
    // writer touches, so it is the classic place for a spurious conflict.
    await Promise.all([1, 2, 3, 4].map(index => app.store.run(async tx => {
      await tx.insert('departments', { id: 'concurrent-' + index, name: 'Concurrent ' + index });
      await audit(tx, config, { userId: 'concurrent' }, 'mysql.concurrent', 'department', 'concurrent-' + index);
    })));
    const after = await app.store.run(tx => tx.get('system_settings', 'audit-head'));
    assert.equal(after.sequence, before.sequence + 4);
    const rows = await app.store.run(tx => tx.list('audit_logs'));
    assert.equal(rows.filter(row => row.action === 'mysql.concurrent').length, 4);
    assert.equal(verifyAudit(rows, config.auditKey, after), true);
    assert.deepEqual((await app.store.run(tx => tx.list('departments'))).filter(item => item.id.startsWith('concurrent-')).map(item => item.id).sort(), ['concurrent-1', 'concurrent-2', 'concurrent-3', 'concurrent-4']);
  });

  await t.test('the retention and delivery job runs on MySQL', async () => {
    const { maintenance } = require('../src/platform/jobs');
    // An enquiry that has outlived its retention period must be gone afterwards.
    await app.store.run(async tx => {
      await tx.insert('submissions', { id: 'EXPIRED-1', reference: 'EXPIRED-1', kind: 'contact', type: 'Contact enquiry', data: { note: 'Synthetic expired enquiry' }, status: 'NEW', createdAt: '2020-01-01T00:00:00.000Z', expiresAt: Date.parse('2020-01-02T00:00:00.000Z') });
      await tx.insert('sessions', { id: 'stale-session', userId: 'whoever', expiresAt: Date.now() - 1000 });
    });
    await maintenance(app.platform);
    assert.equal(await app.store.run(tx => tx.get('submissions', 'EXPIRED-1')), null);
    assert.equal(await app.store.run(tx => tx.get('sessions', 'stale-session')), null);
    assert.notEqual(await app.store.run(tx => tx.get('system_settings', 'maintenance')), null);
  });

  await t.test('sign-in, records and optimistic updates work over HTTP on MySQL', async () => {
    const password = crypto.randomBytes(24).toString('base64url') + 'aA1!';
    const admin = await createUserRecord('mysql.admin', 'Synthetic MySQL Admin', ['system_admin'], password);
    admin.mustChangePassword = false;
    await app.store.run(async tx => { await tx.insert('users', admin); await audit(tx, config, { userId: 'bootstrap' }, 'user.create', 'user', admin.id, 'success', { roles: admin.roles }); });
    const agent = new Agent(base);
    // No session yet, so no CSRF token and no authority.
    assert.equal((await agent.request('GET', '/api/system')).status, 403);
    await agent.login({ username: 'mysql.admin' }, password);
    const status = await agent.ok('GET', '/api/system');
    assert.equal(status.database, 'mysql');
    assert.equal(status.environment, 'test');
    const dashboard = await agent.ok('GET', '/api/dashboard');
    assert.equal(dashboard.database, 'mysql');
    assert.equal(dashboard.accountsTotal, (await app.store.run(tx => tx.list('users'))).filter(item => item.active).length);
    const created = await agent.ok('POST', '/api/users', { username: 'mysql.teacher', name: 'Synthetic MySQL Teacher', roles: ['supporting_staff'], password });
    assert.equal(await app.store.run(tx => tx.get('users', created.id)).then(record => record.username), 'mysql.teacher');
    // A stale revision is rejected by the database predicate, not by a race-free guess.
    const stale = await agent.request('PATCH', '/api/users/' + created.id, { revision: 99, active: true });
    assert.equal(stale.status, 409);
    assert.equal(stale.json.code, 'VERSION_CONFLICT');
    assert.equal((await app.store.run(tx => tx.get('users', created.id))).revision, created.revision);
    const updated = await agent.ok('PATCH', '/api/users/' + created.id, { revision: created.revision, active: true });
    assert.equal(updated.revision, created.revision + 1);
    assert.equal(updated.active, true);
    const second = new Agent(base);
    await second.login({ username: 'mysql.teacher' }, password);
    assert.equal((await second.ok('GET', '/api/auth/session')).user.username, 'mysql.teacher');
    const auditRows = await app.store.run(tx => tx.list('audit_logs'));
    assert.ok(auditRows.some(row => row.action === 'user.create' && row.resourceId === created.id));
    assert.equal(verifyAudit(auditRows, config.auditKey, await app.store.run(tx => tx.get('system_settings', 'audit-head'))), true);
    await agent.ok('POST', '/api/auth/logout', {});
    assert.equal((await agent.request('GET', '/api/dashboard')).status, 403);
  });
});
