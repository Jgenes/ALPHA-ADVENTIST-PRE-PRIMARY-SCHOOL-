'use strict';
require('dotenv').config();
const fs = require('fs');
const crypto = require('crypto');
const { MongoClient } = require('mongodb');
const { EJSON } = require('bson');

function required(name, value) {
  if (!value) throw new Error(`${name} is required.`);
  return value;
}

function decryptBackup(filePath, keyHex) {
  const data = fs.readFileSync(filePath);
  if (data.length < 36 || data.subarray(0, 8).toString() !== 'ALPHAMDB') throw new Error('Unsupported or truncated backup file.');
  const iv = data.subarray(8, 20);
  const tag = data.subarray(20, 36);
  const decipher = crypto.createDecipheriv('aes-256-gcm', Buffer.from(keyHex, 'hex'), iv);
  decipher.setAuthTag(tag);
  return EJSON.parse(Buffer.concat([decipher.update(data.subarray(36)), decipher.final()]).toString('utf8'), { relaxed: false });
}

async function main() {
  const filePath = required('backup-file argument', process.argv[2]);
  const keyHex = required('MONGO_BACKUP_ENCRYPTION_KEY or MONGO_BACKUP_ENCRYPTION_KEY_FILE', process.env.MONGO_BACKUP_ENCRYPTION_KEY || (process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE && fs.readFileSync(process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE, 'utf8').trim()));
  const uri = required('MONGODB_RESTORE_URI', process.env.MONGODB_RESTORE_URI || process.env.MONGODB_URI || process.env.MONGO_URI);
  const targetName = required('MONGODB_RESTORE_DB_NAME', process.env.MONGODB_RESTORE_DB_NAME);
  if (!/^[a-f0-9]{64}$/i.test(keyHex)) throw new Error('MONGO_BACKUP_ENCRYPTION_KEY must be 64 hexadecimal characters.');
  if (process.env.NODE_ENV === 'production') throw new Error('Restore to a non-production database first; production restore requires an approved recovery procedure.');

  const backup = decryptBackup(filePath, keyHex);
  if (backup.database === targetName) throw new Error('Restore target must differ from the backup source database.');
  const client = new MongoClient(uri, { serverSelectionTimeoutMS: 10000, appName: 'AlphaAdventistRestore' });
  try {
    await client.connect();
    const database = client.db(targetName);
    const existing = await database.listCollections({}, { nameOnly: true }).toArray();
    for (const definition of existing) {
      if (await database.collection(definition.name).estimatedDocumentCount()) {
        throw new Error('Restore target is not empty; no existing records were changed.');
      }
    }

    let count = 0;
    for (const entry of backup.collections) {
      const options = Object.fromEntries(Object.entries(entry.options || {}).filter(([, value]) => value !== undefined));
      try { await database.createCollection(entry.name, options); }
      catch (error) { if (error.code !== 48) throw error; }
      if (entry.documents.length) {
        await database.collection(entry.name).insertMany(entry.documents, { ordered: true });
        count += entry.documents.length;
      }
      if (entry.indexes && entry.indexes.length) await database.collection(entry.name).createIndexes(entry.indexes);
    }
    console.log(`Restore completed to isolated database ${targetName}: ${backup.collections.length} collections, ${count} documents. Verify the restore before any production recovery.`);
  } finally {
    await client.close();
  }
}

main().catch(error => {
  console.error('[restore] failed; verify backup key, isolated target, and MongoDB access.', error.name, error.code || '');
  process.exitCode = 1;
});
