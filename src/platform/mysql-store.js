'use strict';
const { HttpError } = require('../lib');
const { COLLECTIONS, table, fieldColumn, indexedFields, indexGroups, appendOnly } = require('./collections');

// MySQL stores each record as a JSON document in an InnoDB table, mirroring the
// document collections the platform was written against. Concurrency is unchanged:
// every write re-checks the row's revision, so a lost update is a 409 conflict rather
// than silent corruption, and uniqueness lives in real database indexes.
const ID_COLUMN = 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
const INDEX_COLUMN = 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
const clone = value => value == null ? null : structuredClone(value);
const conflict = () => new HttpError(409, 'This record changed. Refresh and try again.', 'VERSION_CONFLICT');
const quote = name => '`' + String(name).replace(/`/g, '``') + '`';
const decode = value => typeof value === 'string' ? JSON.parse(value) : value;
// JSON_EXTRACT is SQL NULL for an absent key but the text "null" for an explicit JSON
// null. Normalising both to NULL keeps "not linked yet" records unique-index-safe.
function extractExpression(field) {
  const path = `'$.${field}'`;
  return `(CASE WHEN JSON_EXTRACT(payload, ${path}) IS NULL OR JSON_TYPE(JSON_EXTRACT(payload, ${path})) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(payload, ${path})) END)`;
}
function createTableSql(name) {
  const target = quote(table(name));
  const columns = [
    'seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    `id ${ID_COLUMN} NOT NULL`,
    'revision INT UNSIGNED NOT NULL',
    'payload JSON NOT NULL'
  ];
  for (const field of indexedFields(name)) columns.push(`${fieldColumn(field)} ${INDEX_COLUMN} GENERATED ALWAYS AS (${extractExpression(field)}) VIRTUAL`);
  const keys = indexGroups(name).map(({ fields, unique }) => `${unique ? 'UNIQUE KEY' : 'KEY'} ${indexName(name, fields, unique)} (${fields.map(field => quote(fieldColumn(field))).join(', ')})`);
  return `CREATE TABLE IF NOT EXISTS ${target} (\n  ${[...columns, 'PRIMARY KEY (id)', 'UNIQUE KEY seq_unique (seq)', ...keys].join(',\n  ')}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`;
}
function indexName(name, fields, unique) {
  const base = `${table(name)}_${fields.map(field => field.toLowerCase()).join('_')}`;
  return (unique ? base + '_uq' : base).slice(0, 64);
}
// The audit chain is append-only at the application layer; the triggers make it
// append-only in the database, so a privileged operator cannot quietly rewrite it.
// A trigger cannot name a table in another database, so these statements always run
// on a connection that has already selected the platform schema.
const auditTriggers = () => COLLECTIONS.filter(appendOnly).flatMap(name => [
  { name: 'adsp_audit_no_update', sql: 'CREATE TRIGGER `adsp_audit_no_update` BEFORE UPDATE ON ' + quote(table(name)) + " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only'" },
  { name: 'adsp_audit_no_delete', sql: 'CREATE TRIGGER `adsp_audit_no_delete` BEFORE DELETE ON ' + quote(table(name)) + " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only'" }
]);
class MysqlUnit {
  constructor(connection) { this.connection = connection; }
  async rows(sql, parameters) { const [rows] = await this.connection.query(sql, parameters); return rows; }
  async get(name, id) {
    const rows = await this.rows(`SELECT payload FROM ${quote(table(name))} WHERE id = ? LIMIT 1`, [String(id)]);
    return rows.length ? decode(rows[0].payload) : null;
  }
  // A locking read of a single row, used by the audit chain head. Concurrent requests
  // then queue briefly on that one row and each sees the value the previous committed,
  // instead of failing with a conflict about a record the person never touched.
  async getForUpdate(name, id) {
    const rows = await this.rows(`SELECT payload FROM ${quote(table(name))} WHERE id = ? LIMIT 1 FOR UPDATE`, [String(id)]);
    return rows.length ? decode(rows[0].payload) : null;
  }
  async list(name) {
    const rows = await this.rows(`SELECT payload FROM ${quote(table(name))} ORDER BY seq`, []);
    return rows.map(row => decode(row.payload));
  }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    try { await this.rows(`INSERT INTO ${quote(table(name))} (id, revision, payload) VALUES (?, ?, ?)`, [value.id, 1, JSON.stringify(value)]); }
    catch (error) { if (error.code === 'ER_DUP_ENTRY') throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (appendOnly(name)) throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    // The revision predicate is the optimistic lock. It always changes, so affected
    // rows is a reliable match count; a blocked-then-resumed writer sees zero rows.
    const result = await this.rows(`UPDATE ${quote(table(name))} SET revision = ?, payload = ? WHERE id = ? AND revision = ?`, [value.revision, JSON.stringify(value), value.id, expected]);
    if (!result.affectedRows) throw conflict();
    return value;
  }
  async remove(name, id) {
    if (appendOnly(name)) throw new Error('Audit records are append-only.');
    await this.rows(`DELETE FROM ${quote(table(name))} WHERE id = ?`, [id]);
  }
}
function translateError(error) {
  // InnoDB picks a victim when two transactions deadlock. The loser's work is fully
  // rolled back, so the client must re-read and retry: the same answer the revision
  // check gives, without silently replaying a transaction that may have side effects.
  if (error.code === 'ER_LOCK_DEADLOCK' || error.code === 'ER_LOCK_WAIT_TIMEOUT') return conflict();
  if (error.code === 'ER_TRIGGER' || /append-only/i.test(error.sqlMessage || '')) return new HttpError(409, 'Audit records are append-only.');
  return error;
}
async function createSchema(connection, { requireAuditTriggers = true } = {}) {
  for (const name of COLLECTIONS) await connection.query(createTableSql(name));
  const [triggerRows] = await connection.query('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
  const present = new Set(triggerRows.map(row => row.name));
  for (const trigger of auditTriggers()) {
    if (present.has(trigger.name)) continue;
    try { await connection.query(trigger.sql); }
    catch (error) {
      // Managed providers sometimes deny trigger creation. The application still refuses
      // to audit-edit, but that guarantee is then only as strong as the app itself, so it
      // must be a deliberate decision rather than a warning nobody reads.
      if (!requireAuditTriggers) { console.warn('[mysql] audit append-only trigger unavailable (' + error.code + '); continuing because MYSQL_REQUIRE_AUDIT_TRIGGERS=false'); continue; }
      throw new Error('The audit append-only trigger could not be created (' + error.code + ': ' + error.sqlMessage + '). Grant the TRIGGER privilege, or set MYSQL_REQUIRE_AUDIT_TRIGGERS=false to accept application-level enforcement only.');
    }
  }
}
async function verifyCapabilities(pool, schemaName) {
  const [variables] = await pool.query('SELECT @@transaction_isolation AS isolation, @@version AS version, @@version_comment AS comment');
  const isolation = String(variables[0].isolation || '').toUpperCase();
  // READ UNCOMMITTED would let a transaction observe another writer's uncommitted
  // rows, which the revision checks and audit chain both depend on not happening.
  if (!['READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'].includes(isolation)) throw new Error(`MySQL transaction isolation must be READ-COMMITTED, REPEATABLE-READ or SERIALIZABLE (found ${isolation || 'unknown'}).`);
  const placeholders = COLLECTIONS.map(() => '?').join(', ');
  const [tables] = await pool.query(`SELECT TABLE_NAME AS name, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (${placeholders})`, [schemaName, ...COLLECTIONS.map(table)]);
  const engines = new Map(tables.map(row => [row.name, String(row.ENGINE || '').toUpperCase()]));
  for (const name of COLLECTIONS) {
    if (engines.has(name) && engines.get(name) !== 'INNODB') throw new Error(`${table(name)} must use the InnoDB engine; ${engines.get(name)} cannot provide transactions.`);
  }
  return { isolation, version: variables[0].version };
}
async function openMysqlStore(config, { driver } = {}) {
  const settings = config.mysql;
  if (!settings) throw new Error('MySQL is not configured.');
  const mysql = driver || require('mysql2/promise');
  const pool = mysql.createPool({ ...settings.options, connectionLimit: settings.connectionLimit, waitForConnections: true, queueLimit: 0 });
  const open = async () => {
    if (settings.createSchema) {
      const admin = await mysql.createConnection({ ...settings.options, database: undefined });
      try { await admin.query(`CREATE DATABASE IF NOT EXISTS ${quote(settings.database)} CHARACTER SET utf8mb4 COLLATE utf8mb4_bin`); }
      finally { await admin.end(); }
    }
    // A named database must already exist unless creation was explicitly approved:
    // a typo must fail loudly instead of writing the school's records into a new,
    // empty schema that nobody is backing up.
    await pool.query('SELECT 1 AS ok');
    const info = await verifyCapabilities(pool, settings.database);
    await createSchema(pool, { requireAuditTriggers: settings.requireAuditTriggers !== false });
    return info;
  };
  try {
    const info = await open();
    return {
      kind: 'mysql',
      schema: settings.database,
      isolation: info.isolation,
      async run(fn) {
        const connection = await pool.getConnection();
        try {
          await connection.beginTransaction();
          const result = await fn(new MysqlUnit(connection));
          await connection.commit();
          return result;
        } catch (error) {
          try { await connection.rollback(); } catch { /* The connection is being discarded. */ }
          throw translateError(error);
        } finally { connection.release(); }
      },
      async health() {
        const [rows] = await pool.query('SELECT 1 AS ok');
        if (!rows.length || rows[0].ok !== 1) throw new Error('Database health query returned no result.');
        return true;
      },
      async close() { await pool.end(); }
    };
  } catch (error) {
    try { await pool.end(); } catch { /* Report the startup failure instead. */ }
    throw new Error(`MySQL startup connection/check failed: ${error.message}`, { cause: error });
  }
}
module.exports = { openMysqlStore, MysqlUnit, createSchema, createTableSql, auditTriggers, indexName, extractExpression, decode, translateError };
