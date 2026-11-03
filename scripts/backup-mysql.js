'use strict';
require('dotenv').config();
const path = require('node:path');
const { createConfig } = require('../src/config');
const { openStore, COLLECTIONS } = require('../src/platform/store');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
const B = require('./backup-lib');

async function main() {
  const config = createConfig();
  if (!config.mysql) throw new Error('Configure the MySQL/MariaDB connection (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD) before backing up.');
  if (process.env.BACKUP_WRITES_PAUSED !== 'true') throw new Error('Stop all writers/jobs for the consistent database-and-file backup window, then explicitly confirm BACKUP_WRITES_PAUSED=true.');
  const key = await B.backupKey();
  const objectStorage = createSupabaseStorage(supabaseStorageConfig(process.env));
  const store = await openStore(config);
  try {
    // One repeatable-read transaction gives a consistent view of every table.
    const collections = await store.run(async tx => {
      const rows = [];
      for (const name of COLLECTIONS) rows.push({ name: 'adsp_' + name, documents: await tx.list(name) });
      return rows;
    });
    const files = await B.captureFiles(config.dataDir, collections, objectStorage);
    const snapshot = {
      version: 3, engine: 'mysql', database: config.mysql.database,
      exportedAt: new Date().toISOString(), collections, files,
      storage: { provider: objectStorage ? 'supabase-s3' : 'local', ...(objectStorage ? { bucket: objectStorage.bucket } : {}) },
      publicCopiesRequireReview: true
    };
    const directory = path.resolve(process.env.DB_BACKUP_DIR || path.join(config.dataDir, 'backups'));
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const file = path.join(directory, `${config.mysql.database.replace(/[^a-zA-Z0-9_-]/g, '_')}-${stamp}.json.enc`);
    const result = await B.writeBackup(file, snapshot, key);
    console.log(`Encrypted backup: ${file}\nDatabase: ${config.mysql.database}; tables: ${collections.length}; referenced private files: ${snapshot.files.length}; storage: ${snapshot.storage.provider}; bytes: ${result.bytes}\nSHA-256: ${result.sha256}\nAlso export the database in cPanel (phpMyAdmin → Export) when the school's retention rules require it. Transfer this archive off-site, verify the upload and resume the service. Keys are not in this archive.`);
  } finally { await store.close(); }
}
if (require.main === module) main().catch(error => { console.error('[backup] Failed. Check the approved backup window, keys, storage and database access.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { main };
