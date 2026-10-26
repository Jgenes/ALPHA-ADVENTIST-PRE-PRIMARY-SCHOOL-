'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { HttpError } = require('../lib');
const { COLLECTIONS, table, UNIQUE_KEYS, indexedFields, indexGroups, appendOnly } = require('./collections');
const { openMysqlStore } = require('./mysql-store');
const clone = value => value == null ? null : structuredClone(value);
const conflict = () => new HttpError(409, 'This record changed. Refresh and try again.', 'VERSION_CONFLICT');
class SqliteUnit {
  constructor(db) { this.db = db; }
  async get(name, id) {
    const row = this.db.prepare(`SELECT payload FROM ${table(name)} WHERE id = ?`).get(String(id));
    return row ? JSON.parse(row.payload) : null;
  }
  // The whole store is serialised by one write queue, so a locking read is a plain read.
  async getForUpdate(name, id) { return this.get(name, id); }
  async list(name) { return this.db.prepare(`SELECT payload FROM ${table(name)} ORDER BY rowid`).all().map(row => JSON.parse(row.payload)); }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    try { this.db.prepare(`INSERT INTO ${table(name)} (id, revision, payload) VALUES (?, ?, ?)`).run(value.id, 1, JSON.stringify(value)); }
    catch (error) { if (error.code === 'ERR_SQLITE_ERROR' && /UNIQUE/.test(error.message)) throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (appendOnly(name)) throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    const result = this.db.prepare(`UPDATE ${table(name)} SET revision = ?, payload = ? WHERE id = ? AND revision = ?`).run(value.revision, JSON.stringify(value), value.id, expected);
    if (!result.changes) throw conflict();
    return value;
  }
  async remove(name, id) {
    if (appendOnly(name)) throw new Error('Audit records are append-only.');
    this.db.prepare(`DELETE FROM ${table(name)} WHERE id = ?`).run(id);
  }
}
// These mirror the generated columns and unique keys of the MySQL schema so that a
// constraint learned in development is already enforced in production.
const sqliteConstraints = name => {
  const statements = [];
  for (const { fields, unique } of indexGroups(name)) {
    const expression = fields.map(field => `json_extract(payload, '$.${field}')`).join(', ');
    const index = table(name) + '_' + fields.join('_').toLowerCase() + (unique ? '_uq' : '');
    statements.push(`CREATE ${unique ? 'UNIQUE ' : ''}INDEX IF NOT EXISTS ${index} ON ${table(name)}(${expression});`);
  }
  return statements;
};
async function openSqliteStore(config) {
  const { DatabaseSync } = require('node:sqlite');
  const file = path.join(config.dataDir, 'adsp.sqlite');
  const db = new DatabaseSync(file);
  fs.chmodSync(file, 0o600);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA secure_delete = ON;');
  for (const name of COLLECTIONS) db.exec(`CREATE TABLE IF NOT EXISTS ${table(name)} (id TEXT PRIMARY KEY, revision INTEGER NOT NULL, payload TEXT NOT NULL CHECK(json_valid(payload)))`);
  db.exec([...COLLECTIONS.flatMap(sqliteConstraints),
    `CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;`,
    `CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;`
  ].join('\n'));
  // All operations, including reads, share this queue: no reader can observe an
  // asynchronous transaction's uncommitted state on the SQLite connection.
  let queue = Promise.resolve();
  return {
    kind: 'sqlite',
    run(fn) {
      const pending = queue.then(async () => {
        db.exec('BEGIN IMMEDIATE');
        try { const result = await fn(new SqliteUnit(db)); db.exec('COMMIT'); return result; }
        catch (error) { db.exec('ROLLBACK'); throw error; }
      });
      queue = pending.catch(() => {});
      return pending;
    },
    async health() { await queue; return !!db.prepare('SELECT 1 AS ok').get().ok; },
    async close() { await queue; db.close(); }
  };
}
async function openStore(config) {
  // MySQL is the transactional production store. Without it, development and tests use
  // a private SQLite file in DATA_DIR; there is deliberately no JSON-file fallback.
  if (config.mysql) return openMysqlStore(config);
  return openSqliteStore(config);
}
module.exports = { openStore, openSqliteStore, COLLECTIONS, UNIQUE_KEYS, table, indexedFields };
