'use strict';
require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs/promises');
const { createConfig } = require('../src/config');
const { openStore, mysqlConnectionOptions, COLLECTIONS } = require('../src/platform/store');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
const B = require('./backup-lib');

// Restored sessions, login limits and download grants must never resurrect old
// access. They are dropped from every restore, whatever the archive contains.
const DISCARDED = new Set(['adsp_sessions', 'adsp_login_limits', 'adsp_download_grants']);

function validateRestore(snapshot, env) {
  const target = env.DB_RESTORE_NAME;
  if (env.NODE_ENV === 'production' || env.RESTORE_APPROVED !== 'true') throw new Error('Only an explicitly approved isolated restore is supported.');
  if (!target || !/^[a-zA-Z0-9_-]+_restore_test$/.test(target) || target === snapshot.database || target === env.DB_NAME) throw new Error('Use a distinct MySQL database ending in _restore_test.');
  if (!env.RESTORE_DATA_DIR) throw new Error('An empty RESTORE_DATA_DIR is required.');
  if (env.DATA_DIR && path.resolve(env.DATA_DIR) === path.resolve(env.RESTORE_DATA_DIR)) throw new Error('Do not restore into active storage.');
  if (snapshot.storage?.provider && !['local', 'supabase-s3'].includes(snapshot.storage.provider)) throw new Error('Unsupported file-storage provider in backup.');
  if (snapshot.storage?.provider === 'supabase-s3' && (!env.SUPABASE_RESTORE_BUCKET || env.SUPABASE_RESTORE_BUCKET === snapshot.storage.bucket || env.SUPABASE_RESTORE_BUCKET === env.SUPABASE_STORAGE_BUCKET)) throw new Error('Use a separate empty Supabase restore bucket, distinct from source and live buckets.');
  B.validateFiles(snapshot.files);
  const names = new Set();
  for (const collection of snapshot.collections) {
    if (!/^adsp_[a-z_]+$/.test(collection.name) || names.has(collection.name) || !Array.isArray(collection.documents)) throw new Error('Invalid or duplicate backup collection.');
    if (!COLLECTIONS.includes(collection.name.slice('adsp_'.length))) throw new Error('Unknown collection in backup: ' + collection.name);
    names.add(collection.name);
  }
  return target;
}

async function main() {
  const file = process.argv[2];
  if (!file) throw new Error('Supply the encrypted backup path.');
  const snapshot = await B.readBackup(file, await B.backupKey());
  const target = validateRestore(snapshot, process.env);
  const base = createConfig();
  if (!base.mysql) throw new Error('Configure the live MySQL connection so the isolated restore target can be reached.');
  const objectStorage = snapshot.storage?.provider === 'supabase-s3'
    ? createSupabaseStorage(supabaseStorageConfig({ ...process.env, SUPABASE_STORAGE_BUCKET: process.env.SUPABASE_RESTORE_BUCKET }))
    : null;
  const dataDir = path.resolve(process.env.RESTORE_DATA_DIR);
  try { if ((await fs.readdir(dataDir)).length) throw new Error('Restore storage is not empty.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  const mysql = require('mysql2/promise');
  const connection = await mysql.createConnection(mysqlConnectionOptions(base.mysql));
  try {
    const [rows] = await connection.query('SELECT COUNT(*) AS tables FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [target]);
    if (Number(rows[0].tables)) throw new Error('Target database must be empty. Create it in cPanel/phpMyAdmin and restore only into the empty `_restore_test` database. Existing data is never overwritten.');
  } finally { await connection.end(); }
  const store = await openStore({ ...base, mysql: { ...base.mysql, database: target }, mysqlAutoSchema: true });
  try {
    await store.run(async tx => {
      for (const entry of snapshot.collections) {
        const name = entry.name.slice('adsp_'.length);
        const records = DISCARDED.has(entry.name) ? [] : entry.documents;
        for (const record of records) await tx.insert(name, record);
        if ((await tx.list(name)).length !== records.length) throw new Error('Restore count verification failed for ' + entry.name);
      }
    });
    await B.restoreFiles(dataDir, snapshot.files, objectStorage);
    console.log(`Isolated restore complete: ${snapshot.collections.length} tables in ${target}; ${(snapshot.files || []).length} encrypted private files${objectStorage ? ' in Supabase bucket ' + objectStorage.bucket : ''}. Sessions, login limits and download grants were invalidated. No public files were recreated.\nKeep external alerts disabled. Supply the separately escrowed app keys, verify audit/file integrity, reconcile consent withdrawals and test workflows before an approved recovery cutover. This command never deploys to production.`);
  } finally { await store.close(); }
}
if (require.main === module) main().catch(error => { console.error('[restore] Failed. Check approval, an empty isolated database, archive integrity and keys. A partially restored target must not be used.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { validateRestore, main, DISCARDED };
