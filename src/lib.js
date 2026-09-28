'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const DB_PATH = path.join(__dirname, '..', 'data', 'db.json');
let db = JSON.parse(fs.readFileSync(DB_PATH, 'utf8'));

function saveDB() {
  const tmp = DB_PATH + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2));
  fs.renameSync(tmp, DB_PATH);
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
function newUser(username, name, role, password) {
  const salt = crypto.randomBytes(16).toString('hex');
  return { username, name, role, salt, hash: hashPassword(password, salt) };
}
function verifyUser(user, password) {
  const h = hashPassword(password, user.salt);
  const a = Buffer.from(h, 'hex'); const b = Buffer.from(user.hash, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/* ---------- rate limiting ---------- */
const buckets = new Map();
function rateLimit(key, max, windowMs) {
  const now = Date.now();
  const arr = (buckets.get(key) || []).filter(t => now - t < windowMs);
  arr.push(now);
  buckets.set(key, arr);
  if (buckets.size > 5000) buckets.clear();
  return arr.length <= max;
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
  db: getDB, saveDB, get data() { return db; },
  esc, paragraphs, parseCookies, cookieHeader,
  createSession, getSession, destroySession,
  newUser, verifyUser, rateLimit, readBody, parseForm, slugify, rid, serveStatic, MIME
};
