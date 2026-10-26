'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { BSON } = require('mongodb');
const EJSON = BSON.EJSON;
const MAX_ARCHIVE_BYTES = 512 * 1024 * 1024;
function keyBytes(hex) {
  if (!/^[a-f0-9]{64}$/i.test(hex || '')) throw new Error('Provide a separate 64-hex-character backup encryption key.');
  return Buffer.from(hex, 'hex');
}
async function backupKey(env = process.env) {
  if (env.MONGO_BACKUP_ENCRYPTION_KEY) return keyBytes(env.MONGO_BACKUP_ENCRYPTION_KEY);
  if (!env.MONGO_BACKUP_ENCRYPTION_KEY_FILE) throw new Error('The backup encryption key or protected key file is required.');
  const stat = await fs.stat(env.MONGO_BACKUP_ENCRYPTION_KEY_FILE);
  if (!stat.isFile() || stat.mode & 0o077) throw new Error('Key-file permissions must be 600 or stricter.');
  return keyBytes((await fs.readFile(env.MONGO_BACKUP_ENCRYPTION_KEY_FILE, 'utf8')).trim());
}
function sealBackup(snapshot, key) {
  const body = Buffer.from(EJSON.stringify(snapshot, { relaxed: false }));
  if (body.length > MAX_ARCHIVE_BYTES) throw new Error('Use managed database/storage snapshots for archives larger than 512 MB.');
  const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(body), cipher.final()]);
  return Buffer.concat([Buffer.from('ALPHAMDB'), iv, cipher.getAuthTag(), encrypted]);
}
function openBackup(data, key) {
  if (data.length < 36 || data.length > MAX_ARCHIVE_BYTES + 36 || data.subarray(0, 8).toString() !== 'ALPHAMDB') throw new Error('Unsupported, oversized or truncated backup.');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, data.subarray(8, 20));
  decipher.setAuthTag(data.subarray(20, 36));
  const snapshot = EJSON.parse(Buffer.concat([decipher.update(data.subarray(36)), decipher.final()]).toString('utf8'), { relaxed: true });
  if (!snapshot.database || !Array.isArray(snapshot.collections)) throw new Error('Invalid backup manifest.');
  return snapshot;
}
async function readBackup(file, key) {
  const stat = await fs.stat(file);
  if (!stat.isFile() || stat.mode & 0o077 || stat.size > MAX_ARCHIVE_BYTES + 36) throw new Error('Backup must be a private, bounded file (600 or stricter).');
  return openBackup(await fs.readFile(file), key);
}
function fileReferences(collections) {
  const keys = new Set();
  function visit(value) {
    if (!value || typeof value !== 'object' || Buffer.isBuffer(value)) return;
    if (typeof value.storageKey === 'string' && /^[a-f0-9]{48}$/.test(value.storageKey)) keys.add(value.storageKey);
    for (const child of Object.values(value)) visit(child);
  }
  for (const collection of collections) visit(collection.documents);
  return [...keys];
}
async function captureFiles(dataDir, collections, objectStorage = null) {
  const files = []; let size = 0;
  for (const key of fileReferences(collections)) {
    let bytes;
    if (objectStorage) bytes = await objectStorage.get('private', key);
    else {
      const name = path.join(dataDir, 'storage', 'private', key);
      const stat = await fs.lstat(name);
      if (!stat.isFile() || stat.isSymbolicLink()) throw new Error('Referenced private storage must be a regular file.');
      bytes = await fs.readFile(name);
    }
    size += bytes.length;
    if (size > MAX_ARCHIVE_BYTES / 2) throw new Error('Use managed storage snapshots for large libraries.');
    files.push({ key, size: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), content: bytes.toString('base64') });
  }
  return files;
}
function validateFiles(files = []) {
  const keys = new Set();
  for (const item of files) {
    if (!/^[a-f0-9]{48}$/.test(item.key) || keys.has(item.key)) throw new Error('Unsafe or duplicate storage key in backup.');
    keys.add(item.key);
    const bytes = Buffer.from(item.content, 'base64');
    if (bytes.length !== item.size || crypto.createHash('sha256').update(bytes).digest('hex') !== item.sha256) throw new Error('Stored file checksum mismatch.');
  }
  return files;
}
async function restoreFiles(dataDir, files = [], objectStorage = null) {
  validateFiles(files);
  if (objectStorage) {
    if (!await objectStorage.isEmpty()) throw new Error('Restore bucket must be empty.');
    for (const item of files) await objectStorage.put('private', item.key, Buffer.from(item.content, 'base64'));
    return;
  }
  const privateDir = path.join(dataDir, 'storage', 'private');
  await fs.mkdir(privateDir, { recursive: true, mode: 0o700 });
  if ((await fs.readdir(privateDir)).length) throw new Error('Restore storage must be empty.');
  for (const item of files) await fs.writeFile(path.join(privateDir, item.key), Buffer.from(item.content, 'base64'), { flag: 'wx', mode: 0o600 });
  // Never automatically recreate public copies of photographs after a restore.
  // Consent withdrawals since the snapshot must be reconciled before publishing.
}
async function writeBackup(file, snapshot, key) {
  const bytes = sealBackup(snapshot, key);
  await fs.mkdir(path.dirname(file), { recursive: true, mode: 0o700 });
  await fs.chmod(path.dirname(file), 0o700);
  const handle = await fs.open(file, 'wx', 0o600);
  try { await handle.writeFile(bytes); await handle.sync(); }
  finally { await handle.close(); }
  return { bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex') };
}
module.exports = { keyBytes, backupKey, sealBackup, openBackup, readBackup, fileReferences, captureFiles, validateFiles, restoreFiles, writeBackup };
