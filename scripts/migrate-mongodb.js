'use strict';
require('dotenv').config();
const fs = require('fs');
const crypto = require('crypto');
const { EJSON } = require('bson');

function verifyBackup(filePath, keyHex, dbName) {
  const stat = fs.statSync(filePath);
  if (!stat.isFile() || (stat.mode & 0o077) !== 0) throw new Error('Migration backup must be a private file with permissions 600 or stricter.');
  if (!/^[a-f0-9]{64}$/i.test(keyHex || '')) throw new Error('The backup encryption key must be 64 hexadecimal characters.');
  const archive = fs.readFileSync(filePath);
  if (archive.length < 36 || archive.subarray(0, 8).toString() !== 'ALPHAMDB') throw new Error('Migration backup format is invalid.');
  const decipher = crypto.createDecipheriv('aes-256-gcm', Buffer.from(keyHex, 'hex'), archive.subarray(8, 20));
  decipher.setAuthTag(archive.subarray(20, 36));
  const payload = EJSON.parse(Buffer.concat([decipher.update(archive.subarray(36)), decipher.final()]).toString('utf8'), { relaxed: false });
  if (payload.database !== dbName || !Array.isArray(payload.collections)) throw new Error('Backup database does not match the configured migration target.');
}

async function main() {
  if (process.env.MONGO_MIGRATION_APPROVED !== 'true') throw new Error('Set MONGO_MIGRATION_APPROVED=true after reviewing the migration plan.');
  const backupPath = process.env.MONGO_MIGRATION_BACKUP_FILE;
  if (!backupPath || !fs.statSync(backupPath).isFile()) throw new Error('Set MONGO_MIGRATION_BACKUP_FILE to a verified pre-migration backup.');
  if (process.env.NODE_ENV === 'production' && process.env.MONGO_MIGRATION_STAGE_VERIFIED !== 'true') {
    throw new Error('Production migration requires MONGO_MIGRATION_STAGE_VERIFIED=true.');
  }
  if (!process.env.MONGODB_URI && !process.env.MONGO_URI) throw new Error('MongoDB URI is required.');
  const dbName = process.env.MONGODB_DB_NAME || process.env.MONGO_DB_NAME;
  if (!dbName) throw new Error('Set an explicit MONGODB_DB_NAME for the migration target.');
  const keyHex = process.env.MONGO_BACKUP_ENCRYPTION_KEY || (process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE && fs.readFileSync(process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE, 'utf8').trim());
  verifyBackup(backupPath, keyHex, dbName);

  process.env.MONGO_MIGRATE_LEGACY = 'true';
  const L = require('../src/lib');
  try {
    await L.initDB();
    const state = L.data;
    const counts = ['users', 'news', 'events', 'submissions', 'announcements', 'gallery', 'courses']
      .map(name => `${name}=${Array.isArray(state[name]) ? state[name].length : 0}`);
    console.log(`Mongo collection migration completed: ${counts.join(', ')}`);
  } finally {
    await L.closeDB();
  }
}

main().catch(error => {
  console.error('[migration] failed; verify migration approvals, backup, environment, and MongoDB access.', error.name, error.code || '');
  process.exitCode = 1;
});
