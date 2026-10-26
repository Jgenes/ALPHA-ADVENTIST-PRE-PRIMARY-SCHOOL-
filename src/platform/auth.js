'use strict';
const crypto = require('node:crypto');
const net = require('node:net');
const L = require('../lib');
const Access = require('./access');
const { audit } = require('./audit');
const { HttpError } = L;

function requestIp(req, config) {
  if (config.proxyHops) {
    // Walk from the trusted proxy end, not the client-controlled left edge.
    const chain = String(req.headers['x-forwarded-for'] || '').split(',').map(value => value.trim()).filter(Boolean);
    const ip = chain[chain.length - config.proxyHops];
    if (ip && net.isIP(ip)) return ip;
  }
  return req.socket.remoteAddress || 'unknown';
}
function safeUser(user) {
  if (!user) return null;
  return { id: user.id, username: user.username, name: user.name, roles: user.roles, departmentId: user.departmentId || '', permissions: Access.permissions(user), active: user.active, auditScopes: user.auditScopes || [], revision: user.revision };
}
function secureCookie(req, config) {
  return config.production || !!req.socket.encrypted || (config.proxyHops > 0 && String(req.headers['x-forwarded-proto'] || '').split(',').pop().trim() === 'https');
}
function sessionCookieOptions(req, config) {
  const hostname = String(req.headers.host || '').split(':')[0].toLowerCase();
  // The HTTPS Arena preview is embedded cross-site. Partition its cookie to
  // that top-level site; production continues to use SameSite=Lax + Secure.
  const embeddedPreview = !config.production && config.environment === 'development' && /^\d{1,5}-[a-z0-9-]+\.e2b\.app$/.test(hostname);
  return { secure: embeddedPreview || secureCookie(req, config), sameSite: embeddedPreview ? 'None' : 'Lax', partitioned: embeddedPreview };
}
function setCookie(req, res, token, config, maxAge) {
  res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', token, { ...sessionCookieOptions(req, config), maxAge: maxAge ?? config.sessionAbsoluteMs / 1000 }));
}

async function createUserRecord(username, name, roles, password) {
  if (!/^[a-z0-9][a-z0-9._-]{2,63}$/.test(username)) throw new HttpError(400, 'Use a 3–64 character lowercase username (letters, numbers, dots, hyphens).');
  if (!L.isStrongPassword(password)) throw new HttpError(400, 'Use 12–128 characters with uppercase, lowercase, number and symbol.');
  return { id: L.rid('USR-'), username, name: L.text(name, 120, true), roles: Access.validRoles(roles), ...(await L.passwordHash(password)), active: true, authVersion: 1, mustChangePassword: true, createdAt: new Date().toISOString() };
}
async function limit(tx, key, max, windowMs, now = Date.now()) {
  const id = L.sha256(key);
  let record = await tx.get('login_limits', id);
  if (!record || record.expiresAt <= now) {
    const next = { id, count: 1, expiresAt: now + windowMs };
    if (record) await tx.update('login_limits', { ...record, ...next }); else await tx.insert('login_limits', next);
    return true;
  }
  if (record.count >= max) return false;
  record.count++;
  await tx.update('login_limits', record);
  return true;
}
async function rotate(tx, previous, user, config) {
  if (previous) await tx.remove('sessions', previous.id);
  const token = crypto.randomBytes(32).toString('hex');
  const now = Date.now();
  const session = await tx.insert('sessions', {
    id: L.sha256(token), userId: user?.id || '', authVersion: user?.authVersion || 0,
    csrf: crypto.randomBytes(32).toString('hex'), createdAt: now, lastSeen: now,
    expiresAt: now + (user ? config.sessionAbsoluteMs : 15 * 60 * 1000)
  });
  return { token, session };
}
async function liveActor(tx, actor, config) {
  const session = actor.session && await tx.get('sessions', actor.session.id);
  const user = actor.user && await tx.get('users', actor.user.id);
  if (!session || !user?.active || session.userId !== user.id || session.authVersion !== user.authVersion || session.expiresAt <= Date.now() || Date.now() - session.lastSeen > config.sessionIdleMs) throw new HttpError(403, 'Please sign in again.', 'AUTH_REQUIRED');
  return { ...actor, user, session };
}
function requireReady(actor) {
  if (!actor.user) throw new HttpError(403, 'Please sign in.', 'AUTH_REQUIRED');
  if (actor.user.mustChangePassword) throw new HttpError(403, 'Change your initial password before continuing.', 'PASSWORD_REQUIRED');
}
function requireCsrf(actor, req, body) {
  if (!actor.session || !L.safeEqual(req.headers['x-csrf-token'] || body._csrf, actor.session.csrf)) throw new HttpError(403, 'Your security token expired. Refresh and try again.', 'CSRF_FAILED');
}
class Auth {
  constructor(store, config) { this.store = store; this.config = config; }
  async actor(req, res, create = false) {
    const config = this.config;
    const raw = L.parseCookies(req).alpha_sid;
    const actor = { ip: requestIp(req, config), userAgent: req.headers['user-agent'] || '' };
    const result = await this.store.run(async tx => {
      let session = raw && /^[a-f0-9]{64}$/.test(raw) ? await tx.get('sessions', L.sha256(raw)) : null;
      let user = session?.userId ? await tx.get('users', session.userId) : null;
      if (session && (session.expiresAt <= Date.now() || Date.now() - session.lastSeen > config.sessionIdleMs || session.userId && (!user || !user.active || session.authVersion !== user.authVersion))) {
        await tx.remove('sessions', session.id); session = null; user = null;
      }
      if (!session && create) {
        if (!await limit(tx, 'new-session:' + actor.ip, 60, 15 * 60 * 1000)) return { error: new HttpError(429, 'Too many new sign-in sessions. Please wait before trying again.', 'RATE_LIMIT') };
        return { ...(await rotate(tx, null, null, config)), user: null };
      }
      if (session && Date.now() - session.lastSeen > 30000) session = await tx.update('sessions', { ...session, lastSeen: Date.now() });
      return { session, user };
    });
    if (result.error) throw result.error;
    if (result.token) setCookie(req, res, result.token, config);
    return { ...actor, session: result.session, user: result.user };
  }
  info(actor) { return { user: safeUser(actor.user), csrf: actor.session?.csrf || '', needsPasswordChange: !!actor.user?.mustChangePassword }; }
  async login(actor, body, req, res) {
    const username = L.text(body.username, 64).toLowerCase();
    const config = this.config;
    const result = await this.store.run(async tx => {
      const sourceAllowed = await limit(tx, 'login:ip:' + actor.ip, 30, 10 * 60 * 1000);
      const accountAllowed = await limit(tx, 'login:user:' + username, 6, 10 * 60 * 1000);
      if (!sourceAllowed || !accountAllowed) {
        await audit(tx, config, actor, 'auth.locked', 'user', L.sha256(username).slice(0, 16), 'denied');
        return { error: new HttpError(429, 'Too many sign-in attempts. Try again in 10 minutes.', 'RATE_LIMIT') };
      }
      const user = (await tx.list('users')).find(item => item.username === username);
      // Do comparable expensive work for unknown accounts; no account enumeration.
      const candidate = user || { salt: 'unknown-user-timing-pad', hash: '0'.repeat(128) };
      const passwordValid = await L.verifyPassword(candidate, body.password || '');
      if (!user || !user.active || !passwordValid) {
        await audit(tx, config, actor, 'auth.login', 'user', user?.id || L.sha256(username).slice(0, 16), 'failure');
        return { error: new HttpError(401, 'Sign-in details are incorrect.', 'LOGIN_FAILED') };
      }
      await tx.remove('login_limits', L.sha256('login:user:' + username));
      const rotated = await rotate(tx, actor.session, user, config);
      await audit(tx, config, { ...actor, user, session: rotated.session }, 'auth.login', 'user', user.id);
      return { ...rotated, user };
    });
    if (result.error) throw result.error;
    setCookie(req, res, result.token, config);
    return this.info(result);
  }
  async logout(actor, req, res) {
    await this.store.run(async tx => {
      if (actor.session) await tx.remove('sessions', actor.session.id);
      await audit(tx, this.config, actor, 'auth.logout', 'user', actor.user?.id || 'anonymous');
    });
    setCookie(req, res, '', this.config, 0);
    res.setHeader('Clear-Site-Data', '"cache"');
    return { ok: true };
  }
  async password(actor, body, req, res) {
    if (!actor.user) throw new HttpError(403, 'Please sign in.', 'AUTH_REQUIRED');
    if (!L.isStrongPassword(body.password) || body.password !== body.confirmPassword) throw new HttpError(400, 'Use a strong matching new password.');
    const result = await this.store.run(async tx => {
      actor = await liveActor(tx, actor, this.config);
      if (!await limit(tx, 'password:' + actor.user.id, 6, 10 * 60 * 1000)) return { error: new HttpError(429, 'Try again in 10 minutes.') };
      const user = await tx.get('users', actor.user.id);
      if (!await L.verifyPassword(user, body.currentPassword || '') || await L.verifyPassword(user, body.password)) return { error: new HttpError(400, 'Check your current password and choose a different new password.') };
      // Preserve roles, employee linkage and all other account properties.
      const updated = await tx.update('users', { ...user, ...(await L.passwordHash(body.password)), mustChangePassword: false, authVersion: user.authVersion + 1 });
      for (const session of await tx.list('sessions')) if (session.userId === user.id) await tx.remove('sessions', session.id);
      const rotated = await rotate(tx, null, updated, this.config);
      await audit(tx, this.config, actor, 'auth.password_change', 'user', user.id);
      return { ...rotated, user: updated };
    });
    if (result.error) throw result.error;
    setCookie(req, res, result.token, this.config);
    return this.info(result);
  }
}
module.exports = { Auth, requestIp, safeUser, createUserRecord, limit, requireReady, requireCsrf, liveActor, sessionCookieOptions };
