'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { HttpError } = require('../lib');

// Names are code-owned, never interpolated from a request. The prefix keeps the
// audited platform separate from legacy collections until an explicit migration.
const COLLECTIONS = ['users', 'sessions', 'login_limits', 'departments', 'staff_profiles', 'student_profiles', 'student_guardians', 'classes', 'class_teachers', 'attendance_records', 'student_results', 'learning_materials', 'family_messages', 'admission_documents', 'leave_types', 'leave_requests', 'leave_balances', 'contracts', 'documents', 'document_versions', 'workflow_definitions', 'workflow_instances', 'notices', 'notice_acknowledgements', 'cms_content', 'admissions', 'submissions', 'notifications', 'outbox', 'calendar', 'media', 'media_consents', 'privacy_requests', 'incidents', 'audit_logs', 'system_settings', 'download_grants'];

// Unique business keys enforced by SQL as well as by application validation.
// Each key field is mirrored into a real VARCHAR(191) column so the constraint
// works on MySQL 5.7/MariaDB 10.x as well as newer servers without JSON indexes.
const UNIQUE_KEYS = {
  users: [['username']],
  staff_profiles: [['staffId']],
  student_profiles: [['studentRef'], ['userId']],
  student_guardians: [['studentId', 'guardianUserId']],
  class_teachers: [['classId', 'teacherUserId']],
  attendance_records: [['studentId', 'date']],
  cms_content: [['kind', 'slug']],
  audit_logs: [['sequence']]
};

const clone = value => value == null ? null : structuredClone(value);
const conflict = () => new HttpError(409, 'This record changed. Refresh and try again.', 'VERSION_CONFLICT');
const isDuplicate = error => error && (error.code === 'ER_DUP_ENTRY' || error.errno === 1062);
function table(name) {
  if (!COLLECTIONS.includes(name)) throw new Error('Unknown collection.');
  return 'adsp_' + name;
}
function uniqueFields(name) { return [...new Set((UNIQUE_KEYS[name] || []).flat())]; }
function indexName(name, suffix) { return ('uk_' + table(name) + '_' + suffix).replace(/[^a-zA-Z0-9_]/g, '_').slice(0, 64); }
function createTableSql(name) {
  const columns = [
    'id VARCHAR(191) NOT NULL',
    'revision INT UNSIGNED NOT NULL',
    'seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'payload LONGTEXT NOT NULL',
    ...uniqueFields(name).map(field => `\`${field}\` VARCHAR(191) NULL`)
  ];
  const keys = ['PRIMARY KEY (id)', `UNIQUE KEY ${indexName(name, 'seq')} (seq)`];
  for (const index of UNIQUE_KEYS[name] || []) keys.push(`UNIQUE KEY ${indexName(name, index.join('_'))} (${index.map(field => `\`${field}\``).join(', ')})`);
  return `CREATE TABLE IF NOT EXISTS ${table(name)} (\n  ${[...columns, ...keys].join(',\n  ')}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`;
}
// Audit rows are append-only in the application, and MySQL/MariaDB repeats the
// guarantee so an operator or a direct SQL session cannot quietly rewrite them.
const AUDIT_TRIGGERS = [
  ['adsp_audit_logs_no_update', "CREATE TRIGGER adsp_audit_logs_no_update BEFORE UPDATE ON adsp_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only'"],
  ['adsp_audit_logs_no_delete', "CREATE TRIGGER adsp_audit_logs_no_delete BEFORE DELETE ON adsp_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only'"]
];
const MYSQL_SCHEMA = [
  ...COLLECTIONS.map(name => ({ name: table(name), sql: createTableSql(name) })),
  ...AUDIT_TRIGGERS.map(([name, sql]) => ({ name, sql, trigger: true }))
];
function mysqlSchemaStatements() { return MYSQL_SCHEMA.map(item => item.sql); }
function mysqlSchemaFile() {
  return [
    '-- Alpha Adventist Digital School — MySQL/MariaDB schema for cPanel.',
    '-- Import once in phpMyAdmin (SQL tab) or let the app create it on first start.',
    '-- The cPanel database may be named alphadve_alpha_school; table names stay adsp_*.',
    '-- Keep this file in sync with src/platform/store.js (npm run db:schema).',
    'SET NAMES utf8mb4;',
    'SET time_zone = "+00:00";',
    '',
    ...MYSQL_SCHEMA.map(item => item.sql + ';'),
    ''
  ].join('\n');
}

class SqliteUnit {
  constructor(db) { this.db = db; }
  async get(name, id) {
    const row = this.db.prepare(`SELECT payload FROM ${table(name)} WHERE id = ?`).get(String(id));
    return row ? JSON.parse(row.payload) : null;
  }
  async list(name) { return this.db.prepare(`SELECT payload FROM ${table(name)} ORDER BY rowid`).all().map(row => JSON.parse(row.payload)); }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    try { this.db.prepare(`INSERT INTO ${table(name)} (id, revision, payload) VALUES (?, ?, ?)`).run(value.id, 1, JSON.stringify(value)); }
    catch (error) { if (error.code === 'ERR_SQLITE_ERROR' && /UNIQUE/.test(error.message)) throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    const result = this.db.prepare(`UPDATE ${table(name)} SET revision = ?, payload = ? WHERE id = ? AND revision = ?`).run(value.revision, JSON.stringify(value), value.id, expected);
    if (!result.changes) throw conflict();
    return value;
  }
  async remove(name, id) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    this.db.prepare(`DELETE FROM ${table(name)} WHERE id = ?`).run(id);
  }
}

// Payload stays JSON so the richer workflow records keep their shape, while the
// small set of business keys is mirrored into indexed columns for uniqueness.
const keyValue = value => value === undefined || value === null || value === '' ? null : String(value).slice(0, 191);
class MysqlUnit {
  constructor(conn) { this.conn = conn; }
  async get(name, id) {
    const [rows] = await this.conn.execute(`SELECT payload FROM ${table(name)} WHERE id = ?`, [String(id)]);
    return rows.length ? JSON.parse(rows[0].payload) : null;
  }
  async list(name) {
    const [rows] = await this.conn.execute(`SELECT payload FROM ${table(name)} ORDER BY seq`);
    return rows.map(row => JSON.parse(row.payload));
  }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    const fields = uniqueFields(name);
    const columns = ['id', 'revision', 'payload', ...fields];
    const params = [value.id, 1, JSON.stringify(value), ...fields.map(field => keyValue(value[field]))];
    try {
      await this.conn.execute(`INSERT INTO ${table(name)} (${columns.join(', ')}) VALUES (${columns.map(() => '?').join(', ')})`, params);
    } catch (error) { if (isDuplicate(error)) throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    const fields = uniqueFields(name);
    const assignments = ['revision = ?', 'payload = ?', ...fields.map(field => `\`${field}\` = ?`)];
    const params = [value.revision, JSON.stringify(value), ...fields.map(field => keyValue(value[field])), value.id, expected];
    try {
      const [result] = await this.conn.execute(`UPDATE ${table(name)} SET ${assignments.join(', ')} WHERE id = ? AND revision = ?`, params);
      if (!result.affectedRows) throw conflict();
    } catch (error) { if (isDuplicate(error)) throw conflict(); throw error; }
    return value;
  }
  async remove(name, id) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    await this.conn.execute(`DELETE FROM ${table(name)} WHERE id = ?`, [String(id)]);
  }
}

const TRIGGER_PRIVILEGE_ERRORS = new Set(['ER_SPECIFIC_ACCESS_DENIED_ERROR', 'ER_BINLOG_CREATE_ROUTINE_NEED_SUPER', 'ER_TRG_NO_CREATION_CTX']);
async function ensureMysqlSchema(conn, warnings = []) {
  for (const item of MYSQL_SCHEMA) {
    if (!item.trigger) { await conn.query(item.sql); continue; }
    const [rows] = await conn.query('SELECT 1 AS present FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [item.name]);
    if (rows.length) continue;
    try { await conn.query(item.sql); }
    catch (error) {
      if (error.code === 'ER_TRG_ALREADY_EXISTS') continue;
      // Some shared hosts withhold TRIGGER (or run MySQL with binary logging and
      // log_bin_trust_function_creators=0). The application still refuses every
      // audit update/delete; the database guard is reported as degraded instead
      // of blocking the whole school site.
      if (TRIGGER_PRIVILEGE_ERRORS.has(error.code)) { warnings.push(item.name); continue; }
      throw error;
    }
  }
  return warnings;
}

// Shared by the application and the backup/restore scripts so no caller has to
// pass null TLS or empty socket options to the driver.
function mysqlConnectionOptions(mysqlConfig, overrides = {}) {
  const options = {
    user: mysqlConfig.user,
    password: mysqlConfig.password,
    charset: 'utf8mb4',
    connectTimeout: 15000,
    multipleStatements: false,
    // Dates are never stored as SQL types, but UTC avoids server-timezone drift.
    timezone: 'Z',
    ...overrides
  };
  if (mysqlConfig.socketPath) options.socketPath = mysqlConfig.socketPath;
  else { options.host = mysqlConfig.host; options.port = mysqlConfig.port || 3306; }
  if (mysqlConfig.ssl) options.ssl = mysqlConfig.ssl;
  return options;
}

async function openMysqlStore(mysqlConfig, options = {}) {
  const mysql = require('mysql2/promise');
  const pool = mysql.createPool(mysqlConnectionOptions(mysqlConfig, {
    database: mysqlConfig.database,
    waitForConnections: true,
    connectionLimit: mysqlConfig.connectionLimit || 5,
    queueLimit: 0,
    enableKeepAlive: true
  }));
  try {
    await pool.query('SELECT 1');
    const warnings = [];
    if (options.autoSchema !== false) {
      try { await ensureMysqlSchema(pool, warnings); }
      catch (error) {
        throw new Error(`MySQL schema setup failed (${error.code || error.message}). The database user needs CREATE, ALTER, INDEX and TRIGGER privileges on ${mysqlConfig.database}, or import db/schema.mysql.sql in phpMyAdmin and restart with DB_AUTO_SCHEMA=false.`, { cause: error });
      }
    }
    for (const name of warnings) console.warn(`[store] MySQL did not allow creating trigger ${name}; audit rows stay append-only in the application but the database-level guard is missing. Grant the cPanel user the TRIGGER privilege, or import db/schema.mysql.sql as a privileged user.`);
    // One connection at a time keeps the HMAC audit chain strictly ordered even
    // if two requests try to append concurrently. The unique key on the audit
    // sequence remains a second line of defence.
    let queue = Promise.resolve();
    function run(fn) {
      const pending = queue.then(async () => {
        const conn = await pool.getConnection();
        try {
          await conn.beginTransaction();
          const result = await fn(new MysqlUnit(conn));
          await conn.commit();
          return result;
        } catch (error) {
          try { await conn.rollback(); } catch { /* the connection is released below */ }
          throw error;
        } finally { conn.release(); }
      });
      queue = pending.catch(() => {});
      return pending;
    }
    return {
      kind: 'mysql',
      run,
      async health() { await queue; const [rows] = await pool.query('SELECT 1 AS ok'); return rows[0].ok === 1; },
      async close() { await queue; await pool.end(); }
    };
  } catch (error) {
    try { await pool.end(); } catch { /* already closed */ }
    if (/^MySQL schema setup failed/.test(error.message)) throw error;
    if (error.code === 'ER_NO_SUCH_TABLE') throw new Error(`MySQL database ${mysqlConfig.database} is missing the adsp_ tables. Import db/schema.mysql.sql in phpMyAdmin or enable DB_AUTO_SCHEMA.`, { cause: error });
    throw new Error(`MySQL startup connection/check failed: ${error.message}`, { cause: error });
  }
}

async function openStore(config) {
  if (config.mysql) return openMysqlStore(config.mysql, { autoSchema: config.mysqlAutoSchema !== false });
  const { DatabaseSync } = require('node:sqlite');
  const file = path.join(config.dataDir, 'adsp.sqlite');
  const db = new DatabaseSync(file);
  fs.chmodSync(file, 0o600);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA secure_delete = ON;');
  for (const name of COLLECTIONS) db.exec(`CREATE TABLE IF NOT EXISTS ${table(name)} (id TEXT PRIMARY KEY, revision INTEGER NOT NULL, payload TEXT NOT NULL CHECK(json_valid(payload)))`);
  db.exec(`CREATE UNIQUE INDEX IF NOT EXISTS adsp_username ON adsp_users(json_extract(payload, '$.username'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_staff_id ON adsp_staff_profiles(json_extract(payload, '$.staffId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_ref ON adsp_student_profiles(json_extract(payload, '$.studentRef'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_user ON adsp_student_profiles(json_extract(payload, '$.userId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_guardian ON adsp_student_guardians(json_extract(payload, '$.studentId'), json_extract(payload, '$.guardianUserId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_class_teacher ON adsp_class_teachers(json_extract(payload, '$.classId'), json_extract(payload, '$.teacherUserId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_attendance_student_date ON adsp_attendance_records(json_extract(payload, '$.studentId'), json_extract(payload, '$.date'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_content_slug ON adsp_cms_content(json_extract(payload, '$.kind'), json_extract(payload, '$.slug'));
    CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;
    CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;`);
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
module.exports = { openStore, COLLECTIONS, UNIQUE_KEYS, MysqlUnit, mysqlConnectionOptions, mysqlSchemaStatements, mysqlSchemaFile, MYSQL_SCHEMA };
