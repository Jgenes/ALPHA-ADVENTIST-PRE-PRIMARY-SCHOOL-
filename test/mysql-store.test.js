'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { createConfig } = require('../src/config');
const { MysqlUnit, COLLECTIONS, UNIQUE_KEYS, mysqlSchemaFile, mysqlSchemaStatements, mysqlConnectionOptions } = require('../src/platform/store');

// A deliberately small in-memory double for the handful of statements MysqlUnit
// issues. It enforces the same primary/revision/unique-key rules as MySQL so the
// adapter can be reviewed without a database server in the sandbox.
class FakeConnection {
  constructor() { this.tables = new Map(); this.calls = []; this.nextError = null; }
  table(name) { if (!this.tables.has(name)) this.tables.set(name, new Map()); return this.tables.get(name); }
  rows(name) { return [...this.table(name).values()].sort((a, b) => a.seq - b.seq); }
  duplicate(tableName, values, excludeId) {
    const collection = tableName.replace(/^adsp_/, '');
    for (const index of UNIQUE_KEYS[collection] || []) {
      if (index.some(field => values[field] == null || values[field] === '')) continue;
      for (const [id, row] of this.table(tableName)) {
        if (id === excludeId) continue;
        if (index.every(field => row.keys[field] === values[field])) return true;
      }
    }
    return false;
  }
  async execute(sql, params = []) {
    this.calls.push({ sql, params });
    if (this.nextError) { const error = this.nextError; this.nextError = null; throw error; }
    let match = sql.match(/^SELECT payload FROM (\w+) WHERE id = \?$/);
    if (match) {
      const row = this.table(match[1]).get(String(params[0]));
      return [row ? [{ payload: row.payload }] : [], []];
    }
    match = sql.match(/^SELECT payload FROM (\w+) ORDER BY seq$/);
    if (match) return [this.rows(match[1]).map(row => ({ payload: row.payload })), []];
    match = sql.match(/^INSERT INTO (\w+) \(([^)]+)\) VALUES \(([^)]+)\)$/);
    if (match) {
      const [, tableName, columnList] = match;
      const columns = columnList.split(', ').map(column => column.replace(/`/g, ''));
      const values = Object.fromEntries(columns.map((column, index) => [column, params[index]]));
      if (this.table(tableName).has(String(values.id)) || this.duplicate(tableName, values)) {
        const error = new Error('Duplicate entry'); error.code = 'ER_DUP_ENTRY'; error.errno = 1062; throw error;
      }
      const keys = Object.fromEntries(columns.filter(column => !['id', 'revision', 'payload'].includes(column)).map(column => [column, values[column]]));
      this.table(tableName).set(String(values.id), { seq: this.table(tableName).size + 1, revision: values.revision, payload: values.payload, keys });
      return [{ affectedRows: 1 }, []];
    }
    match = sql.match(/^UPDATE (\w+) SET (.+) WHERE id = \? AND revision = \?$/);
    if (match) {
      const [, tableName, assignmentList] = match;
      let revision, payload;
      const keys = {};
      assignmentList.split(', ').forEach((assignment, index) => {
        const column = assignment.replace(/ = \?$/, '').replace(/`/g, '');
        if (column === 'revision') revision = params[index];
        else if (column === 'payload') payload = params[index];
        else keys[column] = params[index];
      });
      const id = String(params[params.length - 2]);
      const expected = params[params.length - 1];
      const row = this.table(tableName).get(id);
      if (!row || row.revision !== expected) return [{ affectedRows: 0 }, []];
      if (this.duplicate(tableName, keys, id)) {
        const error = new Error('Duplicate entry'); error.code = 'ER_DUP_ENTRY'; error.errno = 1062; throw error;
      }
      this.table(tableName).set(id, { ...row, revision, payload, keys: { ...row.keys, ...keys } });
      return [{ affectedRows: 1 }, []];
    }
    match = sql.match(/^DELETE FROM (\w+) WHERE id = \?$/);
    if (match) {
      const deleted = this.table(match[1]).delete(String(params[0]));
      return [{ affectedRows: deleted ? 1 : 0 }, []];
    }
    throw new Error('Unexpected SQL in the in-memory MySQL double: ' + sql);
  }
}

test('MySQL payload store keeps revisions, unique business keys and append-only audit rows', async t => {
  const connection = new FakeConnection();
  const tx = new MysqlUnit(connection);
  t.after(() => assert.ok(connection.calls.length > 0));

  await t.test('get, insert and list use the adsp_ tables and a stable insertion order', async () => {
    assert.equal(await tx.get('users', 'USR-1'), null);
    const first = await tx.insert('users', { id: 'USR-1', username: 'alpha.admin', name: 'Synthetic Admin', roles: ['system_admin'] });
    assert.equal(first.revision, 1);
    await tx.insert('users', { id: 'USR-2', username: 'head.teacher', name: 'Synthetic Head', roles: ['head_teacher'] });
    const stored = await tx.get('users', 'USR-1');
    assert.equal(stored.username, 'alpha.admin');
    assert.deepEqual((await tx.list('users')).map(user => user.id), ['USR-1', 'USR-2']);
    assert.match(connection.calls[0].sql, /^SELECT payload FROM adsp_users WHERE id = \?$/);
    assert.equal(connection.table('adsp_users').get('USR-1').keys.username, 'alpha.admin');
  });

  await t.test('duplicate usernames and unknown collections are rejected', async () => {
    await assert.rejects(tx.insert('users', { id: 'USR-3', username: 'alpha.admin' }), error => error.status === 409 && error.code === 'VERSION_CONFLICT');
    await assert.rejects(tx.insert('users', { id: 'USR-1', username: 'other.username' }), error => error.status === 409);
    await assert.rejects(tx.insert('students', { id: 'X' }), /Unknown collection/);
    await assert.rejects(tx.get('__proto__', 'x'), /Unknown collection/);
  });

  await t.test('updates are compare-and-swap on the revision and keep key columns in sync', async () => {
    const current = await tx.get('users', 'USR-2');
    const updated = await tx.update('users', { ...current, name: 'Synthetic Head Teacher' }, current.revision);
    assert.equal(updated.revision, 2);
    assert.equal((await tx.get('users', 'USR-2')).name, 'Synthetic Head Teacher');
    await assert.rejects(tx.update('users', { ...current, name: 'Stale write' }, current.revision), /changed/);
    assert.equal((await tx.get('users', 'USR-2')).name, 'Synthetic Head Teacher');
    // Renaming an account to another account's username is caught by SQL, not
    // just by application validation.
    const latest = await tx.get('users', 'USR-2');
    await assert.rejects(tx.update('users', { ...latest, username: 'alpha.admin' }, latest.revision), error => error.status === 409);
    assert.equal((await tx.get('users', 'USR-2')).username, 'head.teacher');
  });

  await t.test('audit rows cannot be changed or deleted and duplicate sequences are refused', async () => {
    await tx.insert('audit_logs', { id: 'AUD-000000000001', sequence: 1, action: 'platform.initialise' });
    await assert.rejects(tx.update('audit_logs', { id: 'AUD-000000000001', sequence: 1 }, 1), /append-only/);
    await assert.rejects(tx.remove('audit_logs', 'AUD-000000000001'), /append-only/);
    await assert.rejects(tx.insert('audit_logs', { id: 'AUD-OTHER', sequence: 1 }), error => error.status === 409);
    await tx.remove('sessions', 'SESSION-1');
    assert.equal(await tx.get('sessions', 'SESSION-1'), null);
  });
});

test('MySQL schema covers every collection, index and audit trigger and matches the cPanel file', async () => {
  const statements = mysqlSchemaStatements();
  assert.equal(statements.filter(statement => statement.startsWith('CREATE TABLE')).length, COLLECTIONS.length);
  assert.equal(statements.filter(statement => statement.startsWith('CREATE TRIGGER')).length, 2);
  for (const name of COLLECTIONS) assert.ok(statements.some(statement => statement.includes(`CREATE TABLE IF NOT EXISTS adsp_${name} (`)), name);
  for (const [name, indexes] of Object.entries(UNIQUE_KEYS)) {
    for (const index of indexes) {
      assert.ok(mysqlSchemaFile().includes(`UNIQUE KEY uk_adsp_${name}_`), `missing unique key for ${name}`);
      for (const field of index) assert.ok(mysqlSchemaFile().includes(`\`${field}\` VARCHAR(191) NULL`), `missing mirrored column ${name}.${field}`);
    }
  }
  const file = mysqlSchemaFile();
  assert.match(file, /ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/);
  assert.match(file, /SIGNAL SQLSTATE '45000'/);
  assert.doesNotMatch(file, /mongodb|MongoDB/i);
  assert.equal(await fs.readFile(path.join(__dirname, '..', 'db', 'schema.mysql.sql'), 'utf8'), file, 'Run npm run db:schema after changing the store schema.');
});

test('MySQL configuration is explicit, defaults the cPanel database name and never silently falls back', async () => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-mysql-config-'));
  try {
    const productionBase = {
      NODE_ENV: 'production', DATA_DIR: dir, BASE_URL: 'https://school.example.test',
      STORAGE_ENCRYPTION_KEY: '1'.repeat(64), AUDIT_HMAC_KEY: '2'.repeat(64),
      FORM_RETENTION_DAYS: '90', ADMISSION_RETENTION_DAYS: '365', RETENTION_POLICY_APPROVED: 'true',
      OFFICE_ALERT_WEBHOOK_URL: 'https://alerts.example.test/receiver', OFFICE_ALERT_WEBHOOK_SECRET: 'synthetic-webhook-secret-at-least-32-characters'
    };
    assert.throws(() => createConfig({ ...productionBase, DB_HOST: 'localhost', DB_USER: 'alphadve_school' }), /DB_PASSWORD/);
    const config = createConfig({
      ...productionBase, DB_HOST: 'localhost', DB_USER: 'alphadve_alpha_school', DB_PASSWORD: 'synthetic-db-password',
      DB_AUTO_SCHEMA: 'false'
    });
    assert.equal(config.mysql.database, 'alphadve_alpha_school');
    assert.equal(config.mysql.host, 'localhost');
    assert.equal(config.mysql.port, 3306);
    assert.equal(config.mysql.user, 'alphadve_alpha_school');
    assert.equal(config.mysql.ssl, null);
    assert.equal(config.mysql.connectionLimit, 3);
    assert.equal(createConfig({ ...productionBase, DB_HOST: 'localhost', DB_USER: 'alpha', DB_PASSWORD: 'synthetic-db-password', DB_POOL_SIZE: '7' }).mysql.connectionLimit, 7);
    assert.equal(config.mysqlAutoSchema, false);
    const tls = createConfig({ ...productionBase, DB_HOST: 'db.example.test', DB_USER: 'alpha', DB_PASSWORD: 'synthetic-db-password', DB_SSL: 'true' });
    assert.deepEqual(tls.mysql.ssl, { rejectUnauthorized: true });
    assert.throws(() => createConfig({ ...productionBase, DB_HOST: 'localhost', DB_USER: 'alpha', DB_PASSWORD: 'x', DB_NAME: 'bad-name; DROP' }), /DB_NAME/);
    assert.throws(() => createConfig({ ...productionBase, DB_HOST: 'localhost', DB_USER: 'alpha', DB_PASSWORD: 'x', DB_PORT: '99999' }), /DB_PORT/);
    assert.throws(() => createConfig({ NODE_ENV: 'staging', PUBLIC_PREVIEW: 'true', BASE_URL: 'https://preview.example.test', DATA_DIR: dir, DB_HOST: 'localhost', DB_USER: 'alpha', DB_PASSWORD: 'x' }), /Public preview/);
    assert.equal(createConfig({ NODE_ENV: 'development', DATA_DIR: dir, BASE_URL: 'http://localhost:3000' }).mysql, null);
    const socketConfig = createConfig({ NODE_ENV: 'development', DATA_DIR: dir, BASE_URL: 'http://localhost:3000', DB_SOCKET: '/var/lib/mysql/mysql.sock', DB_USER: 'alpha', DB_PASSWORD: 'x' });
    assert.equal(socketConfig.mysql.socketPath, '/var/lib/mysql/mysql.sock');
    assert.equal(mysqlConnectionOptions(socketConfig.mysql).socketPath, '/var/lib/mysql/mysql.sock');
    assert.equal(mysqlConnectionOptions(config.mysql).host, 'localhost');
    assert.equal(mysqlConnectionOptions(config.mysql, { database: config.mysql.database }).database, 'alphadve_alpha_school');
    assert.equal(mysqlConnectionOptions(config.mysql).database, undefined, 'server-level scripts must connect before choosing a database');
    assert.equal(mysqlConnectionOptions(config.mysql).multipleStatements, false);
  } finally { await fs.rm(dir, { recursive: true, force: true }); }
});
