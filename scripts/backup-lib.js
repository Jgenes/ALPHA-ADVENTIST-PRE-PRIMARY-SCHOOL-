'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const MAX_ARCHIVE_BYTES = 512 * 1024 * 1024;
// AES-256-GCM envelope: 8-byte magic, 12-byte IV, 16-byte tag, then the JSON body.
// "ALPHAMYS" is the MySQL archive; "ALPHAMDB" is the retired MongoDB archive, kept
// read-only so an existing backup can still be converted during a cutover.
const MAGIC = 'ALPHAMYS';
const LEGACY_MAGIC = 'ALPHAMDB';
function keyBytes(hex) {
  if (!/^[a-f0-9]{64}$/i.test(hex || '')) throw new Error('Provide a separate 64-hex-character backup encryption key.');
  return Buffer.from(hex, 'hex');
}
async function backupKey(env = process.env) {
  if (env.MYSQL_BACKUP_ENCRYPTION_KEY) return keyBytes(env.MYSQL_BACKUP_ENCRYPTION_KEY);
  const file = env.MYSQL_BACKUP_ENCRYPTION_KEY_FILE || env.MONGO_BACKUP_ENCRYPTION_KEY_FILE;
  if (!file) throw new Error('The backup encryption key or protected key file is required.');
  const stat = await fs.stat(file);
  if (!stat.isFile() || stat.mode & 0o077) throw new Error('Key-file permissions must be 600 or stricter.');
  return keyBytes((await fs.readFile(file, 'utf8')).trim());
}
// Strict EJSON wrappers only appear in archives written before the MySQL migration.
// The application itself always stored ISO-8601 text, so plain JSON is lossless now.
function ejsonDate(value, label) {
  const candidates = typeof value === 'string' || typeof value === 'number' ? [value]
    : value && typeof value.$numberLong === 'string' ? [Number(value.$numberLong)]
      : value && typeof value === 'object' && typeof value.t === 'number' ? [value.t * 1000] : [];
  for (const candidate of candidates) {
    const date = new Date(candidate);
    if (Number.isFinite(date.getTime())) return date.toISOString();
  }
  throw new Error(`A legacy backup contains an unreadable ${label}; review the archive before importing.`);
}
function fromEJSON(value) {
  if (Array.isArray(value)) return value.map(fromEJSON);
  if (Buffer.isBuffer(value)) return value.toString('base64');
  if (!value || typeof value !== 'object') return value;
  const keys = Object.keys(value);
  if (keys.length === 1 && keys[0].startsWith('$')) {
    const [key] = keys;
    if (key === '$date') return ejsonDate(value[key], 'date');
    if (key === '$timestamp') return ejsonDate(value[key], 'timestamp');
    if (key === '$numberLong' || key === '$numberInt' || key === '$numberDecimal') {
      const number = Number(value[key]);
      if (!Number.isFinite(number)) throw new Error('A legacy backup contains an unreadable number; review the archive before importing.');
      return number;
    }
    if (key === '$oid') return String(value[key]);
    if (key === '$binary') return Buffer.from(value[key].base64 || value[key], 'base64').toString('base64');
    if (key === '$numberDouble') return Number(value[key]);
    if (key === '$undefined' || key === '$minKey' || key === '$maxKey') return null;
    throw new Error(`A legacy backup uses the unsupported EJSON type ${key}; review the archive before importing.`);
  }
  if (keys.some(key => key.startsWith('$'))) throw new Error('A legacy backup mixes EJSON type markers with record fields; review the archive before importing.');
  const out = {};
  for (const key of keys) out[key] = fromEJSON(value[key]);
  return out;
}
// A legacy document store row keyed by _id becomes the platform's own id field.
function normaliseDocument(document) {
  const { _id, ...rest } = document;
  const id = rest.id !== undefined ? String(rest.id) : _id !== undefined ? String(typeof _id === 'object' && _id !== null ? _id.$oid || _id.str || JSON.stringify(_id) : _id) : '';
  if (!/^[A-Za-z0-9_.:-]{1,191}$/.test(id)) throw new Error('A backup record has no usable id; review the archive before restoring.');
  return { ...rest, id };
}
function sealBackup(snapshot, key) {
  const body = Buffer.from(JSON.stringify(snapshot));
  if (body.length > MAX_ARCHIVE_BYTES) throw new Error('Use managed database/storage snapshots for archives larger than 512 MB.');
  const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(body), cipher.final()]);
  return Buffer.concat([Buffer.from(MAGIC), iv, cipher.getAuthTag(), encrypted]);
}
function openBackup(data, key, { allowLegacy = false } = {}) {
  if (data.length < 36 || data.length > MAX_ARCHIVE_BYTES + 36) throw new Error('Unsupported, oversized or truncated backup.');
  const magic = data.subarray(0, 8).toString();
  const legacy = magic === LEGACY_MAGIC;
  if (magic !== MAGIC && !legacy) throw new Error('Unsupported, oversized or truncated backup.');
  if (legacy && !allowLegacy) throw new Error('This archive uses the retired MongoDB format; convert it with npm run import:mongodb.');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, data.subarray(8, 20));
  decipher.setAuthTag(data.subarray(20, 36));
  const body = Buffer.concat([decipher.update(data.subarray(36)), decipher.final()]).toString('utf8');
  const snapshot = legacy ? fromEJSON(JSON.parse(body)) : JSON.parse(body);
  if (!snapshot.database || !Array.isArray(snapshot.collections)) throw new Error('Invalid backup manifest.');
  return { ...snapshot, legacy };
}
async function readBackup(file, key, options = {}) {
  const stat = await fs.stat(file);
  if (!stat.isFile() || stat.mode & 0o077 || stat.size > MAX_ARCHIVE_BYTES + 36) throw new Error('Backup must be a private, bounded file (600 or stricter).');
  return openBackup(await fs.readFile(file), key, options);
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
module.exports = { keyBytes, backupKey, sealBackup, openBackup, readBackup, fromEJSON, normaliseDocument, MAGIC, LEGACY_MAGIC, fileReferences, captureFiles, validateFiles, restoreFiles, writeBackup };
