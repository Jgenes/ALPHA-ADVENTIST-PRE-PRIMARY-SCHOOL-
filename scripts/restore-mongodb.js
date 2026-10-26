'use strict';
require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs/promises');
const { MongoClient } = require('mongodb');
const B = require('./backup-lib');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
function validateRestore(snapshot, env) {
  const target = env.MONGODB_RESTORE_DB_NAME;
  if (env.NODE_ENV === 'production' || env.RESTORE_APPROVED !== 'true') throw new Error('Only an explicitly approved isolated restore is supported.');
  if (!target || !/^[a-zA-Z0-9_-]+_restore_test$/.test(target) || target === snapshot.database || target === env.MONGODB_DB_NAME) throw new Error('Use a distinct database ending in _restore_test.');
  if (!env.MONGODB_RESTORE_URI || !env.RESTORE_DATA_DIR) throw new Error('Explicit restore URI and empty RESTORE_DATA_DIR are required.');
  if (env.DATA_DIR && path.resolve(env.DATA_DIR) === path.resolve(env.RESTORE_DATA_DIR)) throw new Error('Do not restore into active storage.');
  if (snapshot.storage?.provider && !['local', 'supabase-s3'].includes(snapshot.storage.provider)) throw new Error('Unsupported file-storage provider in backup.');
  if (snapshot.storage?.provider === 'supabase-s3' && (!env.SUPABASE_RESTORE_BUCKET || env.SUPABASE_RESTORE_BUCKET === snapshot.storage.bucket || env.SUPABASE_RESTORE_BUCKET === env.SUPABASE_STORAGE_BUCKET)) throw new Error('Use a separate empty Supabase restore bucket, distinct from source and live buckets.');
  B.validateFiles(snapshot.files);
  const names = new Set();
  for (const collection of snapshot.collections) {
    if (!/^[a-zA-Z0-9_-]+$/.test(collection.name) || names.has(collection.name) || !Array.isArray(collection.documents)) throw new Error('Invalid or duplicate backup collection.');
    names.add(collection.name);
  }
  return target;
}
async function main() {
  const file = process.argv[2];
  if (!file) throw new Error('Supply the encrypted backup path.');
  const snapshot = await B.readBackup(file, await B.backupKey());
  const target = validateRestore(snapshot, process.env);
  const objectStorage = snapshot.storage?.provider === 'supabase-s3'
    ? createSupabaseStorage(supabaseStorageConfig({ ...process.env, SUPABASE_STORAGE_BUCKET: process.env.SUPABASE_RESTORE_BUCKET }))
    : null;
  const dataDir = path.resolve(process.env.RESTORE_DATA_DIR);
  try { if ((await fs.readdir(dataDir)).length) throw new Error('Restore storage is not empty.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  const client = new MongoClient(process.env.MONGODB_RESTORE_URI, { serverSelectionTimeoutMS: 10000, appName: 'AlphaIsolatedRestore' });
  try {
    await client.connect();
    const db = client.db(target);
    if ((await db.listCollections({}, { nameOnly: true }).toArray()).length) throw new Error('Target database must have no collections. Existing data is never overwritten.');
    for (const entry of snapshot.collections) {
      await db.createCollection(entry.name, entry.options || {});
      // Restored sessions and download grants must never resurrect old access.
      const records = ['adsp_sessions', 'adsp_download_grants', 'sessions'].includes(entry.name) ? [] : entry.documents;
      if (records.length) await db.collection(entry.name).insertMany(records, { ordered: true });
      if (entry.indexes?.length) await db.collection(entry.name).createIndexes(entry.indexes);
      if (await db.collection(entry.name).countDocuments() !== records.length) throw new Error('Restore count verification failed.');
    }
    await B.restoreFiles(dataDir, snapshot.files, objectStorage);
    console.log(`Isolated restore complete: ${snapshot.collections.length} collections; ${(snapshot.files || []).length} encrypted private files${objectStorage ? ' in Supabase bucket ' + objectStorage.bucket : ''}. Sessions/grants were invalidated. No public files were recreated.\nKeep external alerts disabled. Supply the separately escrowed app keys, verify audit/file integrity, reconcile consent withdrawals, and test workflows before an approved recovery cutover. This command never deploys to production.`);
  } finally { await client.close(); }
}
if (require.main === module) main().catch(error => { console.error('[restore] Failed. Check approval, an empty isolated target, archive integrity and keys. A partially restored target must not be used.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { validateRestore, main };
