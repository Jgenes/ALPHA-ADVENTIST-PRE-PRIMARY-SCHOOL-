'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { promisify } = require('node:util');
const scrypt = promisify(crypto.scrypt);

class HttpError extends Error {
  constructor(status, message, code = 'REQUEST_FAILED') { super(message); this.status = status; this.code = code; }
}
function esc(s) {
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function paragraphs(list) { return (Array.isArray(list) ? list : []).map(p => `<p>${esc(p)}</p>`).join('\n'); }
function parseCookies(req) {
  const out = Object.create(null);
  for (const pair of String(req.headers.cookie || '').split(';')) {
    const i = pair.indexOf('=');
    if (i < 1) continue;
    try { out[pair.slice(0, i).trim()] = decodeURIComponent(pair.slice(i + 1).trim()); } catch { /* malformed cookie is ignored */ }
  }
  return out;
}
function cookieHeader(name, value, opts = {}) {
  const sameSite = opts.sameSite || 'Lax';
  if (!['Lax', 'Strict', 'None'].includes(sameSite) || (sameSite === 'None' || opts.partitioned) && !opts.secure) throw new Error('Invalid session-cookie policy.');
  return `${name}=${encodeURIComponent(value)}; Path=/; HttpOnly; SameSite=${sameSite}; Max-Age=${opts.maxAge ?? 28800}${opts.secure ? '; Secure' : ''}${opts.partitioned ? '; Partitioned' : ''}`;
}
function isStrongPassword(password) {
  return typeof password === 'string' && password.length >= 12 && password.length <= 128 && /[a-z]/.test(password) && /[A-Z]/.test(password) && /\d/.test(password) && /[^A-Za-z0-9]/.test(password);
}
async function passwordHash(password, salt = crypto.randomBytes(16).toString('hex')) {
  return { salt, hash: (await scrypt(String(password), salt, 64, { N: 32768, r: 8, p: 1, maxmem: 64 * 1024 * 1024 })).toString('hex'), algorithm: 'scrypt-N32768-r8-p1' };
}
async function verifyPassword(user, password) {
  if (typeof password !== 'string' || password.length > 128) return false;
  const candidate = await passwordHash(password, user.salt);
  return safeEqual(candidate.hash, user.hash);
}
function safeEqual(a, b) {
  const x = Buffer.from(String(a || '')); const y = Buffer.from(String(b || ''));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}
function encrypt(value, key) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(value), cipher.final()]);
  return Buffer.concat([iv, encrypted, cipher.getAuthTag()]);
}
function decrypt(buffer, key) {
  if (buffer.length < 28) throw new Error('Invalid encrypted data.');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, buffer.subarray(0, 12));
  decipher.setAuthTag(buffer.subarray(-16));
  return Buffer.concat([decipher.update(buffer.subarray(12, -16)), decipher.final()]);
}
function sha256(value) { return crypto.createHash('sha256').update(value).digest('hex'); }
function rid(prefix = '') { return prefix + crypto.randomUUID(); }
function slugify(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80); }
function text(value, max = 2000, required = false) {
  if (value !== undefined && value !== null && typeof value !== 'string') throw new HttpError(400, 'Expected a text field.');
  const out = String(value || '').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '').trim();
  if ((required && !out) || out.length > max) throw new HttpError(400, `Enter a value between ${required ? 1 : 0} and ${max} characters.`);
  return out;
}
function readBody(req, limit = 100000) {
  return new Promise((resolve, reject) => {
    let size = 0, oversized = Number(req.headers['content-length'] || 0) > limit;
    const chunks = [];
    req.on('error', reject);
    req.once('aborted', () => reject(new HttpError(400, 'Submission interrupted.')));
    req.on('data', chunk => {
      if (oversized) return;
      size += chunk.length;
      if (size > limit) { oversized = true; chunks.length = 0; reject(new HttpError(413, 'Submission too large.')); }
      else chunks.push(chunk);
    });
    req.once('end', () => { if (!oversized) resolve(Buffer.concat(chunks).toString('utf8')); });
    if (oversized) { req.resume(); reject(new HttpError(413, 'Submission too large.')); }
  });
}
async function parseForm(req, limit) {
  const raw = await readBody(req, limit);
  const type = req.headers['content-type'] || '';
  if (type.startsWith('application/json')) {
    try {
      const obj = JSON.parse(raw);
      if (!obj || typeof obj !== 'object' || Array.isArray(obj)) throw new Error();
      return obj;
    } catch { throw new HttpError(400, 'Invalid JSON request.'); }
  }
  if (type.startsWith('application/x-www-form-urlencoded')) return Object.fromEntries(new URLSearchParams(raw));
  throw new HttpError(415, 'Use JSON or a form-encoded request.');
}
const MIME = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.woff2': 'font/woff2' };
function serveStatic(req, res, urlPath, publicDir) {
  if (!['GET', 'HEAD'].includes(req.method)) return false;
  let decoded;
  try { decoded = decodeURIComponent(urlPath); } catch { return false; }
  if (decoded.includes('..') || decoded.includes('\\') || decoded.includes('\0')) return false;
  const file = path.resolve(publicDir, '.' + decoded);
  if (!file.startsWith(path.resolve(publicDir) + path.sep) || !MIME[path.extname(file)]) return false;
  let stat;
  try { stat = fs.statSync(file); } catch { return false; }
  if (!stat.isFile() || !fs.realpathSync(file).startsWith(fs.realpathSync(publicDir) + path.sep)) return false;
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)], 'Content-Length': stat.size, 'Cache-Control': 'public, max-age=3600', 'X-Content-Type-Options': 'nosniff' });
  if (req.method === 'HEAD') res.end(); else fs.createReadStream(file).pipe(res);
  return true;
}
module.exports = { HttpError, esc, paragraphs, parseCookies, cookieHeader, isStrongPassword, passwordHash, verifyPassword, safeEqual, encrypt, decrypt, sha256, rid, slugify, text, readBody, parseForm, serveStatic, MIME };
