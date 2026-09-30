'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SEED_DB_PATH = path.join(__dirname, '..', 'data', 'db.json');
const DB_PATH = process.env.DATA_DIR
  ? path.join(process.env.DATA_DIR, 'db.json')
  : SEED_DB_PATH;
if (DB_PATH !== SEED_DB_PATH && !fs.existsSync(DB_PATH)) {
  fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });
  const initialDB = JSON.parse(fs.readFileSync(SEED_DB_PATH, 'utf8'));
  // A fresh persistent volume should use the configured ADMIN_PASSWORD, not
  // carry the development admin account from the bundled seed database.
  initialDB.users = [];
  fs.writeFileSync(DB_PATH, JSON.stringify(initialDB, null, 2));
}
let db = JSON.parse(fs.readFileSync(DB_PATH, 'utf8'));
if (!Array.isArray(db.admissionApplications)) db.admissionApplications = [];
let mongoClient = null;
let mongoState = null;
let mongoDatabase = null;
let mongoLegacyMode = false;
let saveQueue = Promise.resolve();
const MIGRATION_ID = 'legacy-platform-state-to-collections-v1';
const STATE_ARRAYS = ['hero', 'pillars', 'courses', 'news', 'events', 'announcements', 'gallery', 'faqs', 'submissions', 'admissionApplications', 'users', 'auditLogs'];
const STATE_OBJECTS = ['settings', 'statements', 'leadership', 'academics'];
const ADMISSION_TRANSITIONS = {
  SUBMITTED: ['DOCUMENTS_REQUIRED', 'UNDER_REVIEW'],
  DOCUMENTS_REQUIRED: ['UNDER_REVIEW', 'DECLINED'],
  UNDER_REVIEW: ['DOCUMENTS_REQUIRED', 'ASSESSMENT', 'ACCEPTED', 'WAITLISTED', 'DECLINED'],
  ASSESSMENT: ['ACCEPTED', 'WAITLISTED', 'DECLINED'],
  ACCEPTED: ['ENROLLED', 'DECLINED'],
  WAITLISTED: ['ACCEPTED', 'DECLINED'],
  DECLINED: [],
  ENROLLED: []
};
const COLLECTION_VALIDATORS = {
  users: { $jsonSchema: { bsonType: 'object', required: ['username', 'name', 'role', 'salt', 'hash'], properties: { username: { bsonType: 'string', minLength: 1 }, name: { bsonType: 'string', minLength: 1 }, role: { enum: ['super', 'admin', 'editor', 'contributor'] }, salt: { bsonType: 'string' }, hash: { bsonType: 'string' } } } },
  news: { $jsonSchema: { bsonType: 'object', required: ['slug', 'title'], properties: { slug: { bsonType: 'string', minLength: 1 }, title: { bsonType: 'string', minLength: 1 } } } },
  submissions: { $jsonSchema: { bsonType: 'object', required: ['type', 'data', 'at'], properties: { reference: { bsonType: 'string' }, type: { bsonType: 'string', minLength: 1 }, data: { bsonType: 'object' }, at: { bsonType: ['int', 'long', 'double', 'decimal'] }, status: { enum: ['new', 'under_review', 'closed'] } } } },
  admissionApplications: { $jsonSchema: { bsonType: 'object', required: ['applicationReference', 'applicant', 'guardians', 'classApplyingFor', 'boardingStatus', 'status', 'createdAt', 'updatedAt'], properties: { applicationReference: { bsonType: 'string', pattern: '^ALPHA-[0-9]{4}-[0-9]{6,}$' }, applicant: { bsonType: 'object', required: ['fullName'], properties: { fullName: { bsonType: 'string', minLength: 1 } } }, guardians: { bsonType: 'array', minItems: 1 }, classApplyingFor: { bsonType: 'string', minLength: 1 }, boardingStatus: { enum: ['DAY', 'BOARDING'] }, status: { enum: ['SUBMITTED', 'DOCUMENTS_REQUIRED', 'UNDER_REVIEW', 'ASSESSMENT', 'ACCEPTED', 'WAITLISTED', 'DECLINED', 'ENROLLED'] }, createdAt: { bsonType: 'date' }, updatedAt: { bsonType: 'date' } } } },
  auditLogs: { $jsonSchema: { bsonType: 'object', required: ['userId', 'action', 'resourceType', 'result', 'createdAt'], properties: { userId: { bsonType: 'string' }, action: { bsonType: 'string', minLength: 1 }, resourceType: { bsonType: 'string', minLength: 1 }, resourceId: { bsonType: 'string' }, result: { enum: ['success', 'failure', 'denied'] }, createdAt: { bsonType: 'date' } } } }
};

async function createStateCollections(existingCollections) {
  for (const name of [...STATE_ARRAYS, ...STATE_OBJECTS]) {
    if (existingCollections.has(name)) continue;
    const validator = COLLECTION_VALIDATORS[name];
    await mongoDatabase.createCollection(name, validator ? { validator, validationLevel: 'strict', validationAction: 'error' } : {});
  }
}

function collectionDocument(name, item, index) {
  const document = Object.assign({}, item);
  const identifier = document.id || document._id || (name === 'users' ? document.username
    : name === 'news' || name === 'events' ? document.slug
      : name === 'submissions' ? document.reference
        : null) || `${name}-${index + 1}`;
  document._id = String(identifier);
  document.position = index;
  return document;
}

async function writeArrayCollection(name, items) {
  const collection = mongoDatabase.collection(name);
  const documents = items.map((item, index) => collectionDocument(name, item, index));
  if (documents.length) {
    await collection.bulkWrite(documents.map(document => ({ replaceOne: { filter: { _id: document._id }, replacement: document, upsert: true } })), { ordered: true });
  }
  await collection.deleteMany({ _id: { $nin: documents.map(document => document._id) } });
}

async function writeStateCollection(name) {
  if (STATE_ARRAYS.includes(name)) return writeArrayCollection(name, db[name] || []);
  if (STATE_OBJECTS.includes(name)) {
    await mongoDatabase.collection(name).replaceOne({ _id: 'primary' }, Object.assign({ _id: 'primary' }, db[name] || {}), { upsert: true });
  }
}

async function migrateLegacyState(state, existingCollections) {
  const migrations = mongoDatabase.collection('schemaMigrations');
  let migration = await migrations.findOne({ _id: MIGRATION_ID });
  if (migration && migration.status === 'complete') return;
  if (!migration) {
    migration = { _id: MIGRATION_ID, status: 'running', preexistingCollections: [...existingCollections], startedAt: new Date() };
    try { await migrations.insertOne(migration); }
    catch (error) {
      if (error.code !== 11000) throw error;
      migration = await migrations.findOne({ _id: MIGRATION_ID });
    }
  }
  const preservedCollections = new Set(migration.preexistingCollections || []);
  for (const name of STATE_ARRAYS) {
    if (!preservedCollections.has(name)) await writeArrayCollection(name, Array.isArray(state[name]) ? state[name] : []);
  }
  for (const name of STATE_OBJECTS) {
    if (!preservedCollections.has(name) && state[name]) {
      await mongoDatabase.collection(name).replaceOne({ _id: 'primary' }, Object.assign({ _id: 'primary' }, state[name]), { upsert: true });
    }
  }
  await migrations.updateOne({ _id: MIGRATION_ID }, { $set: { status: 'complete', completedAt: new Date() } });
}

async function loadNormalizedState(legacyState) {
  const loaded = Object.assign({}, legacyState);
  for (const name of STATE_ARRAYS) {
    loaded[name] = (await mongoDatabase.collection(name).find({}).sort({ position: 1, _id: 1 }).toArray()).map(document => {
      delete document._id;
      delete document.position;
      return document;
    });
  }
  for (const name of STATE_OBJECTS) {
    const document = await mongoDatabase.collection(name).findOne({ _id: 'primary' });
    if (document) {
      delete document._id;
      loaded[name] = document;
    }
  }
  return loaded;
}

async function initDB() {
  const uri = process.env.MONGODB_URI || process.env.MONGO_URI;
  const configuredDBName = process.env.MONGODB_DB_NAME || process.env.MONGO_DB_NAME;
  if (!uri) {
    if (process.env.NODE_ENV === 'production' || process.env.NODE_ENV === 'staging') throw new Error('MongoDB URI is required outside development.');
    return;
  }
  if ((process.env.NODE_ENV === 'production' || process.env.NODE_ENV === 'staging') && !configuredDBName) throw new Error('Set an isolated MongoDB database name for this environment.');
  if (process.env.NODE_ENV === 'production' || process.env.NODE_ENV === 'staging') mfaKey();
  const { MongoClient } = require('mongodb');
  try {
    let lastConnectionError;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      const candidate = new MongoClient(uri, { serverSelectionTimeoutMS: 5000, maxPoolSize: 20, minPoolSize: 1, appName: 'AlphaAdventistSchool', retryWrites: true });
      try {
        await candidate.connect();
        mongoClient = candidate;
        break;
      } catch (error) {
        lastConnectionError = error;
        await candidate.close().catch(() => {});
        if (attempt < 2) await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
      }
    }
    if (!mongoClient) throw lastConnectionError;
    mongoDatabase = mongoClient.db(configuredDBName || undefined);
    mongoState = mongoDatabase.collection('platform_state');
    const stateCollectionNames = new Set([...STATE_ARRAYS, ...STATE_OBJECTS]);
    const existingCollections = new Set((await mongoDatabase.listCollections({}, { nameOnly: true }).toArray())
      .map(collection => collection.name).filter(name => stateCollectionNames.has(name)));
    const stored = await mongoState.findOne({ _id: 'primary' });
    const migration = await mongoDatabase.collection('schemaMigrations').findOne({ _id: MIGRATION_ID });
    if (!stored && existingCollections.size && !migration) throw new Error('Existing Mongo collections have no migration snapshot; refusing to merge bundled seed data.');
    if (migration && migration.status !== 'complete' && process.env.MONGO_MIGRATE_LEGACY !== 'true') {
      throw new Error('A Mongo migration is incomplete; validate and resume it explicitly.');
    }
    if (!migration && existingCollections.size && process.env.MONGO_MIGRATE_LEGACY !== 'true') {
      throw new Error('Mongo collections exist without a migration marker; validate them in staging before startup.');
    }
    if (stored && stored.data && !migration && process.env.MONGO_MIGRATE_LEGACY !== 'true') {
      db = stored.data;
      mongoLegacyMode = true;
      return;
    }
    let legacyState = stored && stored.data ? stored.data : db;
    if (!stored) {
      legacyState = Object.assign({}, db);
      if (!existingCollections.size) legacyState.users = [];
      await mongoState.insertOne({ _id: 'primary', data: legacyState, createdAt: new Date() });
    }
    await createStateCollections(existingCollections);
    await migrateLegacyState(legacyState, existingCollections);
    db = await loadNormalizedState(legacyState);
    mongoLegacyMode = false;
    await mongoDatabase.collection('users').createIndex({ username: 1 }, { unique: true, name: 'username_unique' });
    await mongoDatabase.collection('news').createIndex({ slug: 1 }, { unique: true, name: 'news_slug_unique' });
    await mongoDatabase.collection('events').createIndex({ slug: 1 }, { unique: true, sparse: true, name: 'events_slug_unique' });
    await mongoDatabase.collection('submissions').createIndex({ reference: 1 }, { unique: true, sparse: true, name: 'submission_reference_unique' });
    await mongoDatabase.collection('submissions').createIndex({ status: 1, at: -1 }, { name: 'submission_status_date' });
    await mongoDatabase.collection('admissionApplications').createIndex({ applicationReference: 1 }, { unique: true, name: 'application_reference_unique' });
    await mongoDatabase.collection('admissionApplications').createIndex({ status: 1, createdAt: -1 }, { name: 'application_status_date' });
    await mongoDatabase.collection('auditLogs').createIndex({ createdAt: -1 }, { name: 'audit_created_at' });
    await mongoDatabase.collection('auditLogs').createIndex({ userId: 1, action: 1, createdAt: -1 }, { name: 'audit_user_action_date' });
  } catch (error) {
    if (mongoClient) await mongoClient.close();
    mongoClient = null;
    mongoState = null;
    mongoDatabase = null;
    mongoLegacyMode = false;
    throw error;
  }
}

async function saveDB(names) {
  if (mongoDatabase) {
    const selected = (Array.isArray(names) ? names : [names]).filter(name => STATE_ARRAYS.includes(name) || STATE_OBJECTS.includes(name));
    const pending = saveQueue.then(async () => {
      if (mongoLegacyMode) {
        await mongoState.replaceOne({ _id: 'primary' }, { _id: 'primary', data: db, updatedAt: new Date() }, { upsert: true });
        return;
      }
      for (const name of new Set(selected)) await writeStateCollection(name);
    });
    saveQueue = pending.catch(() => {});
    return pending;
  }
  const tmp = DB_PATH + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2));
  fs.renameSync(tmp, DB_PATH);
}
async function writeAuditEvent(event) {
  const record = Object.assign({ createdAt: new Date() }, event);
  if (mongoDatabase) return mongoDatabase.collection('auditLogs').insertOne(record);
  db.auditLogs = db.auditLogs || [];
  db.auditLogs.push(record);
  await saveDB('auditLogs');
}
async function nextAdmissionReference(date = new Date()) {
  const year = date.getFullYear();
  let sequence;
  if (mongoDatabase) {
    const counter = await mongoDatabase.collection('sequences').findOneAndUpdate(
      { _id: `admission:${year}` }, { $inc: { value: 1 } },
      { upsert: true, returnDocument: 'after', includeResultMetadata: false }
    );
    sequence = counter.value;
  } else {
    const prefix = `ALPHA-${year}-`;
    sequence = db.admissionApplications.reduce((maximum, application) => {
      if (!application.applicationReference || !application.applicationReference.startsWith(prefix)) return maximum;
      return Math.max(maximum, Number(application.applicationReference.slice(prefix.length)) || 0);
    }, 0) + 1;
  }
  return `ALPHA-${year}-${String(sequence).padStart(6, '0')}`;
}
async function saveAdmissionApplication(application) {
  if (mongoDatabase) {
    const document = Object.assign({}, application, { _id: application.applicationReference, position: db.admissionApplications.length });
    await mongoDatabase.collection('admissionApplications').insertOne(document);
    db.admissionApplications.push(application);
    return;
  }
  db.admissionApplications.push(application);
  await saveDB('admissionApplications');
}
async function updateAdmissionStatus(reference, currentStatuses, nextStatus) {
  const validSources = currentStatuses.filter(status => (ADMISSION_TRANSITIONS[status] || []).includes(nextStatus));
  if (!validSources.length) return false;
  const now = new Date();
  if (mongoDatabase) {
    const result = await mongoDatabase.collection('admissionApplications').updateOne(
      { applicationReference: reference, status: { $in: validSources } },
      { $set: { status: nextStatus, updatedAt: now } }
    );
    if (!result.matchedCount) return false;
  }
  const application = db.admissionApplications.find(item => item.applicationReference === reference);
  if (!application || !validSources.includes(application.status)) return false;
  application.status = nextStatus;
  application.updatedAt = now;
  if (!mongoDatabase) await saveDB('admissionApplications');
  return true;
}
async function closeDB() {
  await saveQueue;
  if (mongoClient) await mongoClient.close();
  mongoClient = null;
  mongoState = null;
  mongoDatabase = null;
  mongoLegacyMode = false;
}
function getDB() { return db; }

/* ---------- escaping ---------- */
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// allow simple paragraph lists only
function paragraphs(list) {
  return (Array.isArray(list) ? list : []).map(p => `<p>${esc(p)}</p>`).join('\n');
}

/* ---------- cookies / sessions ---------- */
function parseCookies(req) {
  const out = {};
  const h = req.headers.cookie;
  if (!h) return out;
  h.split(';').forEach(pair => {
    const i = pair.indexOf('=');
    if (i > -1) out[pair.slice(0, i).trim()] = decodeURIComponent(pair.slice(i + 1).trim());
  });
  return out;
}
function cookieHeader(name, value, opts = {}) {
  let c = `${name}=${encodeURIComponent(value)}; Path=/; SameSite=Lax; Max-Age=${opts.maxAge != null ? opts.maxAge : 60 * 60 * 8}`;
  if (opts.httpOnly !== false) c += '; HttpOnly';
  if (opts.secure) c += '; Secure';
  return c;
}

const sessions = new Map(); // token -> {username, role, csrf, created}
function createSession(user) {
  const token = crypto.randomBytes(24).toString('hex');
  sessions.set(token, { username: user.username, role: user.role, csrf: crypto.randomBytes(16).toString('hex'), created: Date.now() });
  return token;
}
function getSession(req) {
  const cookies = parseCookies(req);
  const token = cookies.alpha_sid;
  if (!token) return null;
  const s = sessions.get(token);
  if (!s) return null;
  if (Date.now() - s.created > 1000 * 60 * 60 * 10) { sessions.delete(token); return null; }
  return { token, ...s };
}
function destroySession(req) {
  const cookies = parseCookies(req);
  if (cookies.alpha_sid) sessions.delete(cookies.alpha_sid);
}

/* ---------- password hashing ---------- */
function hashPassword(password, salt) {
  return crypto.scryptSync(String(password), salt, 64).toString('hex');
}
function isStrongPassword(password) {
  const value = String(password || '');
  return value.length >= 12 && /[a-z]/.test(value) && /[A-Z]/.test(value) && /\d/.test(value) && /[^A-Za-z0-9]/.test(value);
}
function newUser(username, name, role, password) {
  if (!isStrongPassword(password)) throw new Error('Password must be at least 12 characters and include upper-case, lower-case, numeric, and symbol characters.');
  const salt = crypto.randomBytes(16).toString('hex');
  return { username, name, role, salt, hash: hashPassword(password, salt) };
}
function verifyUser(user, password) {
  const h = hashPassword(password, user.salt);
  const a = Buffer.from(h, 'hex'); const b = Buffer.from(user.hash, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function encodeBase32(input) {
  let bits = 0;
  let value = 0;
  let output = '';
  for (const byte of input) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      output += BASE32[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
  }
  if (bits) output += BASE32[(value << (5 - bits)) & 31];
  return output;
}
function decodeBase32(input) {
  let bits = 0;
  let value = 0;
  const output = [];
  for (const character of String(input).toUpperCase().replace(/=+$/, '')) {
    const index = BASE32.indexOf(character);
    if (index < 0) throw new Error('Invalid MFA secret.');
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      output.push((value >>> (bits - 8)) & 255);
      bits -= 8;
    }
  }
  return Buffer.from(output);
}
function mfaKey() {
  const key = process.env.MFA_ENCRYPTION_KEY || (process.env.MFA_ENCRYPTION_KEY_FILE && fs.readFileSync(process.env.MFA_ENCRYPTION_KEY_FILE, 'utf8').trim()) || '';
  if (!/^[a-f0-9]{64}$/i.test(key)) throw new Error('MFA_ENCRYPTION_KEY must be 64 hexadecimal characters.');
  return Buffer.from(key, 'hex');
}
function validateMfaEncryptionKey() { mfaKey(); return true; }
function createTotpSecret() { return encodeBase32(crypto.randomBytes(20)); }
function totp(secret, time = Date.now()) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(time / 30000)));
  const digest = crypto.createHmac('sha1', decodeBase32(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  const code = (digest.readUInt32BE(offset) & 0x7fffffff) % 1000000;
  return String(code).padStart(6, '0');
}
function verifyTotp(secret, code, time = Date.now()) {
  const supplied = String(code || '');
  if (!/^\d{6}$/.test(supplied)) return false;
  const candidate = Buffer.from(supplied);
  for (const drift of [-30000, 0, 30000]) {
    if (crypto.timingSafeEqual(candidate, Buffer.from(totp(secret, time + drift)))) return true;
  }
  return false;
}
function encryptMfaSecret(secret) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', mfaKey(), iv);
  const ciphertext = Buffer.concat([cipher.update(secret, 'utf8'), cipher.final()]);
  return `${iv.toString('hex')}.${cipher.getAuthTag().toString('hex')}.${ciphertext.toString('hex')}`;
}
function decryptMfaSecret(encrypted) {
  const [ivHex, tagHex, ciphertextHex] = String(encrypted || '').split('.');
  const decipher = crypto.createDecipheriv('aes-256-gcm', mfaKey(), Buffer.from(ivHex, 'hex'));
  decipher.setAuthTag(Buffer.from(tagHex, 'hex'));
  return Buffer.concat([decipher.update(Buffer.from(ciphertextHex, 'hex')), decipher.final()]).toString('utf8');
}

/* ---------- rate limiting ---------- */
const buckets = new Map();
function rateLimit(key, max, windowMs) {
  const now = Date.now();
  let entry = buckets.get(key);
  if (!entry && buckets.size >= 5000) {
    for (const [bucketKey, bucket] of buckets) {
      bucket.times = bucket.times.filter(time => now - time < bucket.windowMs);
      if (!bucket.times.length) buckets.delete(bucketKey);
    }
    if (buckets.size >= 5000) return false;
    entry = { times: [], windowMs };
  }
  if (!entry) entry = { times: [], windowMs };
  entry.windowMs = windowMs;
  entry.times = entry.times.filter(time => now - time < windowMs);
  entry.times.push(now);
  buckets.set(key, entry);
  return entry.times.length <= max;
}

/* ---------- body parsing ---------- */
function readBody(req, limit = 200000) {
  return new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', ch => {
      size += ch.length;
      if (size > limit) { reject(new Error('too_large')); req.destroy(); return; }
      chunks.push(ch);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}
async function parseForm(req) {
  const type = req.headers['content-type'] || '';
  const raw = await readBody(req);
  if (type.includes('application/json')) {
    try { return JSON.parse(raw || '{}'); } catch (e) { return {}; }
  }
  const out = {};
  new URLSearchParams(raw).forEach((v, k) => { out[k] = v; });
  return out;
}

/* ---------- misc ---------- */
function slugify(s) {
  return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
}
function rid() { return crypto.randomBytes(6).toString('hex'); }

/* ---------- static serving ---------- */
const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ico': 'image/x-icon', '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml', '.webmanifest': 'application/manifest+json', '.map': 'application/json'
};
function serveStatic(req, res, urlPath, publicDir) {
  const clean = path.normalize(urlPath).replace(/^(\.\.[\/\\])+/, '');
  const file = path.join(publicDir, clean);
  if (!file.startsWith(publicDir)) return false;
  let st;
  try { st = fs.statSync(file); } catch (e) { return false; }
  if (!st.isFile()) return false;
  const ext = path.extname(file).toLowerCase();
  const isAsset = ['/img/', '/fonts/', '/css/', '/js/'].some(p => urlPath.startsWith(p));
  const cacheControl = ext === '.css' || ext === '.js'
    ? 'no-cache'
    : isAsset ? 'public, max-age=2592000' : 'public, max-age=3600';
  res.writeHead(200, {
    'Content-Type': MIME[ext] || 'application/octet-stream',
    'Content-Length': st.size,
    'Cache-Control': cacheControl,
    'X-Content-Type-Options': 'nosniff'
  });
  fs.createReadStream(file).pipe(res);
  return true;
}

module.exports = {
  db: getDB, initDB, saveDB, writeAuditEvent, nextAdmissionReference, saveAdmissionApplication, updateAdmissionStatus, ADMISSION_TRANSITIONS, closeDB, get data() { return db; },
  esc, paragraphs, parseCookies, cookieHeader,
  createSession, getSession, destroySession,
  newUser, verifyUser, isStrongPassword, validateMfaEncryptionKey, createTotpSecret, totp, verifyTotp, encryptMfaSecret, decryptMfaSecret,
  rateLimit, readBody, parseForm, slugify, rid, serveStatic, MIME
};
