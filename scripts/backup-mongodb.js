'use strict';
require('dotenv').config();
const path = require('node:path');
const { MongoClient } = require('mongodb');
const B = require('./backup-lib');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
const defined = object => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined));
async function main() {
  const { MONGODB_URI: uri, MONGODB_DB_NAME: database, DATA_DIR: dataDir } = process.env;
  if (!uri || !database || !dataDir) throw new Error('Explicit MongoDB URI, database and DATA_DIR are required.');
  if (process.env.BACKUP_WRITES_PAUSED !== 'true') throw new Error('Stop all writers/jobs for the consistent database-and-file backup window, then explicitly confirm BACKUP_WRITES_PAUSED=true.');
  const key = await B.backupKey();
  const objectStorage = createSupabaseStorage(supabaseStorageConfig(process.env));
  const client = new MongoClient(uri, { serverSelectionTimeoutMS: 10000, appName: 'AlphaGovernedBackup' });
  try {
    await client.connect();
    const db = client.db(database), hello = await db.command({ hello: 1 });
    if (!hello.setName && hello.msg !== 'isdbgrid') throw new Error('A transaction-capable replica set is required.');
    const definitions = (await db.listCollections({}, { nameOnly: false }).toArray()).filter(item => !item.name.startsWith('system.') && item.type === 'collection');
    const collections = [];
    for (const entry of definitions) {
      const indexes = (await db.collection(entry.name).listIndexes().toArray()).filter(item => item.name !== '_id_').map(({ key, name, unique, sparse, expireAfterSeconds, partialFilterExpression, collation }) => defined({ key, name, unique, sparse, expireAfterSeconds, partialFilterExpression, collation }));
      const { validator, validationLevel, validationAction, collation } = entry.options || {};
      collections.push({ name: entry.name, indexes, options: defined({ validator, validationLevel, validationAction, collation }) });
    }
    const session = client.startSession();
    let snapshot;
    try {
      await session.withTransaction(async () => {
        for (const collection of collections) collection.documents = await db.collection(collection.name).find({}, { session }).toArray();
        const files = await B.captureFiles(path.resolve(dataDir), collections, objectStorage);
        snapshot = { version: 2, database, exportedAt: new Date(), collections, files, storage: { provider: objectStorage ? 'supabase-s3' : 'local', ...(objectStorage ? { bucket: objectStorage.bucket } : {}) }, publicCopiesRequireReview: true };
      }, { readConcern: { level: 'snapshot' }, writeConcern: { w: 'majority' } });
    } finally { await session.endSession(); }
    const directory = path.resolve(process.env.MONGO_BACKUP_DIR || path.join(dataDir, 'backups'));
    const file = path.join(directory, `${database.replace(/[^a-zA-Z0-9_-]/g, '_')}-${new Date().toISOString().replace(/[:.]/g, '-')}.ejson.enc`);
    const result = await B.writeBackup(file, snapshot, key);
    console.log(`Encrypted backup: ${file}\nCollections: ${collections.length}; referenced private files: ${snapshot.files.length}; storage: ${snapshot.storage.provider}; bytes: ${result.bytes}\nSHA-256: ${result.sha256}\nTransfer off-site, verify upload and retention, and resume the service. Keys are not in this archive.`);
  } finally { await client.close(); }
}
if (require.main === module) main().catch(error => { console.error('[backup] Failed. Check the approved backup window, keys, storage and database access.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { main };
