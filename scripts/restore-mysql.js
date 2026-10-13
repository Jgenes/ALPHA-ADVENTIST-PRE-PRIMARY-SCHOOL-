'use strict';
require('dotenv').config();
const path = require('node:path');
const L = require('../src/lib');
const fs = require('node:fs/promises');
const { mysqlConfig, SCHEMA_PATTERN } = require('../src/platform/mysql-config');
const { createSchema } = require('../src/platform/mysql-store');
const B = require('./backup-lib');
const T = require('./mysql-transfer');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
function restoreSettings(env) {
  const url = env.MYSQL_RESTORE_URL;
  if (!url) throw new Error('An explicit MYSQL_RESTORE_URL for the isolated restore server is required.');
  return mysqlConfig({
    MYSQL_URL: url,
    MYSQL_DB_NAME: env.MYSQL_RESTORE_DB_NAME,
    MYSQL_SSL_MODE: env.MYSQL_SSL_MODE,
    MYSQL_TLS_CA_FILE: env.MYSQL_TLS_CA_FILE,
    MYSQL_TLS_SERVER_NAME: env.MYSQL_TLS_SERVER_NAME,
    MYSQL_POOL_SIZE: env.MYSQL_POOL_SIZE
  }, { production: false });
}
function validateRestore(snapshot, env) {
  const target = env.MYSQL_RESTORE_DB_NAME;
  if (env.NODE_ENV === 'production' || env.RESTORE_APPROVED !== 'true') throw new Error('Only an explicitly approved isolated restore is supported.');
  if (!target || !SCHEMA_PATTERN.test(target) || !target.endsWith('_restore_test') || target === snapshot.database || target === env.MYSQL_DB_NAME) throw new Error('Use a distinct schema ending in _restore_test.');
  if (!env.MYSQL_RESTORE_URL || !env.RESTORE_DATA_DIR) throw new Error('An explicit MYSQL_RESTORE_URL and an empty RESTORE_DATA_DIR are required.');
  if (env.DATA_DIR && path.resolve(env.DATA_DIR) === path.resolve(env.RESTORE_DATA_DIR)) throw new Error('Do not restore into active storage.');
  if (snapshot.storage?.provider && !['local', 'supabase-s3'].includes(snapshot.storage.provider)) throw new Error('Unsupported file-storage provider in backup.');
  if (snapshot.storage?.provider === 'supabase-s3' && (!env.SUPABASE_RESTORE_BUCKET || env.SUPABASE_RESTORE_BUCKET === snapshot.storage.bucket || env.SUPABASE_RESTORE_BUCKET === env.SUPABASE_STORAGE_BUCKET)) throw new Error('Use a separate empty Supabase restore bucket, distinct from source and live buckets.');
  B.validateFiles(snapshot.files);
  T.planTables(snapshot.collections);
  return target;
}
async function main() {
  const file = process.argv[2];
  if (!file) throw new Error('Supply the encrypted backup path.');
  // A MongoDB archive is refused inside readBackup: it must go through the cutover import.
  const snapshot = await B.readBackup(file, await B.backupKey());
  const target = validateRestore(snapshot, process.env);
  const settings = restoreSettings(process.env);
  const objectStorage = snapshot.storage?.provider === 'supabase-s3'
    ? createSupabaseStorage(supabaseStorageConfig({ ...process.env, SUPABASE_STORAGE_BUCKET: process.env.SUPABASE_RESTORE_BUCKET }))
    : null;
  const dataDir = path.resolve(process.env.RESTORE_DATA_DIR);
  try { if ((await fs.readdir(dataDir)).length) throw new Error('Restore storage is not empty.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  const mysql = require('mysql2/promise');
  // The schema itself is created by a separate, brief admin connection so that the
  // restore connection can run with the target selected, which MySQL requires for
  // the append-only audit triggers.
  const admin = await mysql.createConnection({ ...settings.options, database: undefined });
  try {
    const [existing] = await admin.query('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [target]);
    if (!existing.length) await admin.query('CREATE DATABASE ' + '`' + target + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
  } finally { await admin.end(); }
  const connection = await mysql.createConnection(settings.options);
  try {
    const [tables] = await connection.query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (tables.length) throw new Error('The target schema already has tables. Existing data is never overwritten; restore into a fresh schema.');
    await createSchema(connection);
    const { planned } = T.planTables(snapshot.collections);
    const counts = await T.writeTables(connection, { planned });
    await B.restoreFiles(dataDir, snapshot.files, objectStorage);
    console.log(`Isolated restore complete: ${Object.keys(counts).length} tables, ${Object.values(counts).reduce((total, value) => total + value, 0)} records; ${(snapshot.files || []).length} encrypted private files${objectStorage ? ' in Supabase bucket ' + objectStorage.bucket : ''}. Sessions/grants were invalidated. No public files were recreated.\nKeep external alerts disabled. Supply the separately escrowed app keys, verify audit/file integrity, reconcile consent withdrawals, and test workflows before an approved recovery cutover. This command never deploys to production.`);
  } finally { await connection.end(); }
}
if (require.main === module) main().catch(error => { console.error('[restore] Failed. Check approval, an empty isolated target, archive integrity and keys. A partially restored target must not be used.', L.safeErrorMessage(error), error.code || ''); process.exitCode = 1; });
module.exports = { validateRestore, restoreSettings, main };
