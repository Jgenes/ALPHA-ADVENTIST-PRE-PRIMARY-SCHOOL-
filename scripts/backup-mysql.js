'use strict';
require('dotenv').config();
const path = require('node:path');
const { mysqlConfig } = require('../src/platform/mysql-config');
const { COLLECTIONS, table } = require('../src/platform/collections');
const B = require('./backup-lib');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
// A consistent database-and-file archive. Every table is read inside one REPEATABLE
// READ transaction, so the snapshot cannot straddle two states of the school records.
async function main() {
  const env = process.env;
  const production = ['production', 'staging'].includes(env.NODE_ENV);
  const settings = mysqlConfig(env, { production });
  if (!settings) throw new Error('Explicit MySQL connection settings (MYSQL_URL or MYSQL_HOST/MYSQL_USER/MYSQL_DB_NAME) and DATA_DIR are required.');
  if (!env.DATA_DIR) throw new Error('DATA_DIR is required so the private files referenced by records can be included.');
  if (env.BACKUP_WRITES_PAUSED !== 'true') throw new Error('Stop all writers/jobs for the consistent database-and-file backup window, then explicitly confirm BACKUP_WRITES_PAUSED=true.');
  const key = await B.backupKey();
  const objectStorage = createSupabaseStorage(supabaseStorageConfig(env));
  const mysql = require('mysql2/promise');
  const connection = await mysql.createConnection(settings.options);
  try {
    await connection.query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    await connection.query('START TRANSACTION WITH CONSISTENT SNAPSHOT READ ONLY');
    const [found] = await connection.query('SELECT TABLE_NAME AS name, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    const present = found.map(row => row.name);
    // A consistent snapshot only means something on a transactional engine.
    const wrongEngine = found.filter(row => String(row.ENGINE).toUpperCase() !== 'INNODB');
    if (wrongEngine.length) throw new Error(`These tables are not InnoDB and cannot be snapshotted consistently: ${wrongEngine.map(row => row.name + '=' + row.ENGINE).join(', ')}.`);
    const expected = COLLECTIONS.map(table);
    const missing = expected.filter(name => !present.includes(name));
    if (missing.length) throw new Error(`The schema is incomplete; missing tables: ${missing.join(', ')}. Refusing to write a partial backup.`);
    const unexpected = present.filter(name => !expected.includes(name));
    if (unexpected.length) throw new Error(`Unexpected tables in this schema: ${unexpected.join(', ')}. Review them; a backup must not smuggle unknown records.`);
    const collections = [];
    for (const name of expected) {
      const [definition] = await connection.query('SHOW CREATE TABLE `' + name + '`');
      const [rows] = await connection.query('SELECT payload FROM `' + name + '` ORDER BY seq');
      collections.push({ name, ddl: definition[0]['Create Table'], documents: rows.map(row => typeof row.payload === 'string' ? JSON.parse(row.payload) : row.payload) });
    }
    const files = await B.captureFiles(path.resolve(env.DATA_DIR), collections, objectStorage);
    const snapshot = { version: 3, dialect: 'mysql', database: settings.database, exportedAt: new Date().toISOString(), isolation: 'REPEATABLE READ', collections, files, storage: { provider: objectStorage ? 'supabase-s3' : 'local', ...(objectStorage ? { bucket: objectStorage.bucket } : {}) }, publicCopiesRequireReview: true };
    await connection.query('COMMIT');
    const directory = path.resolve(env.MYSQL_BACKUP_DIR || path.join(env.DATA_DIR, 'backups'));
    const file = path.join(directory, `${settings.database.replace(/[^a-zA-Z0-9_-]/g, '_')}-${new Date().toISOString().replace(/[:.]/g, '-')}.json.enc`);
    const result = await B.writeBackup(file, snapshot, key);
    console.log(`Encrypted backup: ${file}\nTables: ${collections.length}; referenced private files: ${snapshot.files.length}; storage: ${snapshot.storage.provider}; bytes: ${result.bytes}\nSHA-256: ${result.sha256}\nTransfer off-site, verify upload and retention, and resume the service. Keys are not in this archive.`);
  } finally {
    try { await connection.query('ROLLBACK'); } catch { /* The connection is closing. */ }
    await connection.end();
  }
}
if (require.main === module) main().catch(error => { console.error('[backup] Failed. Check the approved backup window, keys, storage and database access.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { main };
