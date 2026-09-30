'use strict';
/* Alpha Adventist Pre & Primary School — official website platform
  Node.js server: SSR public site + persistent CMS + role-based admin. */
require('dotenv').config();
const http = require('http');
const path = require('path');
const crypto = require('crypto');
const L = require('./src/lib');
const X = require('./src/layout');
const { esc } = L;

const PUBLIC_DIR = path.join(__dirname, 'public');
const PORT = process.env.PORT || 3000;
const BASE_URL = process.env.BASE_URL || 'https://alphaadventist.ac.tz';

/* seed a default super-admin after the configured database is ready */
async function seedUsers() {
  const db = L.data;
  if (!db.users.length) {
    if (!L.isStrongPassword(process.env.ADMIN_PASSWORD)) throw new Error('Set a strong ADMIN_PASSWORD before first startup.');
    L.validateMfaEncryptionKey();
    const username = clean(process.env.ADMIN_USERNAME || 'admin');
    const name = clean(process.env.ADMIN_NAME || 'School Administrator');
    const user = L.newUser(username, name, 'super', process.env.ADMIN_PASSWORD);
    user.mustSetupMfa = true;
    db.users.push(user);
    await L.saveDB('users');
    console.log('[cms] initial super-admin created from environment configuration.');
    return;
  }
  if (db.users.some(user => ['super', 'admin'].includes(user.role) && !user.mfaEnabled)) L.validateMfaEncryptionKey();
  let changed = false;
  db.users.forEach(user => {
    if (user.role === 'super' && !user.mustChangePassword) { user.mustChangePassword = true; changed = true; }
    if (['super', 'admin'].includes(user.role) && !user.mfaEnabled && !user.mustSetupMfa) { user.mustSetupMfa = true; changed = true; }
  });
  if (changed) await L.saveDB('users');
}

/* ---------- helpers ---------- */
function send(res, code, html, headers = {}) {
  const buf = Buffer.from(html);
  res.writeHead(code, Object.assign({
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Length': buf.length,
    'Cache-Control': 'no-cache',
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'SAMEORIGIN',
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=()'
  }, headers));
  res.end(buf);
}
function json(res, code, obj) {
  const buf = Buffer.from(JSON.stringify(obj));
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': buf.length, 'Cache-Control': 'no-store' });
  res.end(buf);
}
function redirect(res, to) {
  res.writeHead(303, { Location: to, 'Cache-Control': 'no-store' });
  res.end();
}
function ipOf(req) {
  if (process.env.TRUST_PROXY === 'true') {
    const forwarded = (req.headers['x-forwarded-for'] || '').split(',')[0].trim();
    if (forwarded) return forwarded;
  }
  return req.socket.remoteAddress || 'unknown';
}
function secure(req) {
  return !!req.socket.encrypted || (process.env.TRUST_PROXY === 'true' && req.headers['x-forwarded-proto'] === 'https');
}
function getOrAnonSession(req, res) {
  let s = L.getSession(req);
  if (!s) {
    const token = crypto.randomBytes(24).toString('hex');
    const rec = { csrf: crypto.randomBytes(16).toString('hex'), created: Date.now() };
    // store anonymous session
    L.data; // noop
    anonSessions.set(token, rec);
    s = { token, ...rec };
    res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', token, { secure: secure(req) }));
  }
  return s;
}
const anonSessions = new Map();
const mfaSetupSessions = new Map();
function sessionFor(req) {
  const cookies = L.parseCookies(req);
  const token = cookies.alpha_sid;
  if (!token) return null;
  const main = L.getSession(req);
  if (main) return main;
  const a = anonSessions.get(token);
  if (a) return { token, ...a };
  return null;
}

/* ---------- page registry ---------- */
const pages = {
  '/': require('./src/pages/home'),
  '/about': require('./src/pages/about'),
  '/academics': require('./src/pages/academics'),
  '/admissions': require('./src/pages/admissions'),
  '/computer-learning': require('./src/pages/computer'),
  '/school-life': require('./src/pages/schoollife'),
  '/faith': require('./src/pages/faith'),
  '/parents': require('./src/pages/parents'),
  '/students': require('./src/pages/students'),
  '/gallery': require('./src/pages/gallery'),
  '/contact': require('./src/pages/contact'),
  '/privacy': require('./src/pages/privacy')
};

function sitemap() {
  const urls = Object.keys(pages).concat(['/news']).concat(L.data.news.map(n => '/news/' + n.slug));
  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map(u => `  <url><loc>${BASE_URL}${u}</loc><changefreq>${u === '/' ? 'weekly' : 'monthly'}</changefreq></url>`).join('\n')}
</urlset>`;
}
const ROBOTS = `User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api/\n\nSitemap: ${BASE_URL}/sitemap.xml\n`;
const MANIFEST = JSON.stringify({
  name: 'Alpha Adventist Pre & Primary School',
  short_name: 'Alpha Adventist',
  start_url: '/',
  display: 'standalone',
  background_color: '#0A1E59',
  theme_color: '#0A1E59',
  description: 'Official digital platform of Alpha Adventist Pre & Primary School, Kigoma, Tanzania.',
  icons: [
    { src: '/img/icon-192.png', sizes: '192x192', type: 'image/png' },
    { src: '/img/icon-512.png', sizes: '512x512', type: 'image/png' }
  ]
});

/* ---------- public form endpoints ---------- */
const FORM_RULES = {
  '/api/contact': { type: 'Contact enquiry', required: ['name', 'phone', 'message', 'privacy_consent'] },
  '/api/visit': { type: 'School visit request', required: ['name', 'phone', 'privacy_consent'] },
  '/api/apply': { type: 'Admission application', required: ['child_name', 'guardian_name', 'phone', 'level', 'arrangement', 'privacy_consent'] },
  '/api/computer-class': { type: 'Computer class enquiry', required: ['name', 'phone', 'privacy_consent'] },
  '/api/computer-interest': { type: 'Community training interest', required: ['name', 'phone', 'privacy_consent'] }
};
function clean(v) {
  return String(v == null ? '' : v).replace(/[\u0000-\u001f<>]/g, '').trim().slice(0, 2000);
}
async function handlePublicForm(req, res, route) {
  const rule = FORM_RULES[route];
  if (!L.rateLimit('form:' + ipOf(req), 8, 60 * 60 * 1000)) return json(res, 429, { ok: false, message: 'Too many submissions — please try again later or call the school office.' });
  let data;
  try { data = await L.parseForm(req); } catch (e) { return json(res, 413, { ok: false, message: 'Submission too large.' }); }
  if (data.website_url) return json(res, 200, { ok: true, message: 'Thank you — the school office will contact you.' }); // honeypot: pretend success
  const missing = rule.required.filter(k => !clean(data[k]));
  if (missing.length) return json(res, 400, { ok: false, message: 'Please complete the required fields.' });
  if (!['on', 'yes', 'true'].includes(String(data.privacy_consent).toLowerCase())) return json(res, 400, { ok: false, message: 'Please confirm the privacy notice before submitting.' });
  if (route === '/api/apply') {
    const levels = [...L.data.academics.prePrimary, ...L.data.academics.primary];
    const email = clean(data.email);
    if (!levels.includes(clean(data.level)) || !['Day', 'Boarding'].includes(clean(data.arrangement))) return json(res, 400, { ok: false, message: 'Please select a valid class and school arrangement.' });
    if (!/^\+?[0-9 ()-]{7,20}$/.test(clean(data.phone)) || (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) return json(res, 400, { ok: false, message: 'Please enter a valid telephone number and email address.' });
    const now = new Date();
    const reference = await L.nextAdmissionReference(now);
    const application = {
      applicationReference: reference,
      applicant: { fullName: clean(data.child_name) },
      guardians: [{ fullName: clean(data.guardian_name), phone: clean(data.phone), email: email || null, relationship: 'Parent/Guardian', isPrimary: true }],
      programme: clean(data.level).startsWith('KG') ? 'PRE_PRIMARY' : 'PRIMARY',
      classApplyingFor: clean(data.level),
      boardingStatus: clean(data.arrangement).toUpperCase(),
      documents: [],
      status: 'SUBMITTED',
      assignedOfficer: null,
      notes: data.message ? [{ text: clean(data.message), visibility: 'OFFICE', createdAt: now }] : [],
      consent: { purpose: 'admissions_review', policyVersion: 'privacy-2026-09-30-v1', acceptedAt: now },
      submittedAt: now,
      createdAt: now,
      updatedAt: now
    };
    await L.saveAdmissionApplication(application);
    await auditEvent(req, 'admission.submit', 'admissionApplication', reference, 'success');
    await sendOfficeAlert(reference, rule.type);
    return json(res, 200, { ok: true, reference, message: 'Your application request has been received. The admissions office will contact you with the next steps.' });
  }
  const out = {};
  Object.keys(data).forEach(k => { out[k] = clean(data[k]); });
  delete out.privacy_consent;
  delete out.website_url;
  const reference = `ALPHA-${new Date().getFullYear()}-${crypto.randomBytes(6).toString('hex').toUpperCase()}`;
  L.data.submissions.push({ reference, type: rule.type, data: out, consent: { purpose: 'respond_to_enquiry', policyVersion: 'privacy-2026-09-30-v1', acceptedAt: Date.now() }, at: Date.now(), status: 'new' });
  await L.saveDB('submissions');
  await auditEvent(req, 'submission.create', 'submission', reference, 'success', { type: rule.type });
  await sendOfficeAlert(reference, rule.type);
  const msgs = {
    'Contact enquiry': 'Thank you — your message has reached the school office. We respond during working hours on school days.',
    'School visit request': 'Asante! Your visit request has been received. The school office will confirm a convenient day with you.',
    'Admission application': 'Your application request has been received. The admissions office will contact you with the next steps.',
    'Computer class enquiry': 'Received! The school office will contact you about computer class placement.',
    'Community training interest': 'Your interest has been registered. You will be contacted when approved registration opens.'
  };
  json(res, 200, { ok: true, reference, message: msgs[rule.type] });
}

async function sendOfficeAlert(reference, type) {
  const endpoint = process.env.OFFICE_ALERT_WEBHOOK_URL;
  if (!endpoint) return;
  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ school: 'Alpha Adventist Pre & Primary School', reference, type, receivedAt: new Date().toISOString() }),
      signal: AbortSignal.timeout(5000)
    });
    if (!response.ok) console.error(`[alerts] office webhook returned HTTP ${response.status} for ${reference}`);
  } catch (error) {
    console.error(`[alerts] office webhook delivery failed for ${reference}`);
  }
}

async function purgeExpiredSubmissions() {
  if (!process.env.FORM_RETENTION_DAYS) return 0;
  const days = Number(process.env.FORM_RETENTION_DAYS);
  if (!Number.isSafeInteger(days) || days < 1 || days > 36500) throw new Error('FORM_RETENTION_DAYS must be between 1 and 36500.');
  const cutoff = Date.now() - days * 24 * 60 * 60 * 1000;
  const previousCount = L.data.submissions.length;
  L.data.submissions = L.data.submissions.filter(submission => Number(submission.at) >= cutoff);
  const removed = previousCount - L.data.submissions.length;
  if (removed) {
    await L.saveDB('submissions');
    await L.writeAuditEvent({ userId: 'system', action: 'submissions.retention_purge', resourceType: 'submission', resourceId: '', result: 'success', metadata: { count: removed } });
  }
  return removed;
}

/* ---------- admin ---------- */
const A = require('./src/pages/admin');
const ROLE_PAGES = {
  '/admin/news': ['super', 'admin', 'editor', 'contributor'],
  '/admin/announcements': ['super', 'admin', 'editor', 'contributor'],
  '/admin/gallery': ['super', 'admin', 'editor'],
  '/admin/courses': ['super', 'admin', 'editor'],
  '/admin/submissions': ['super', 'admin', 'editor'],
  '/admin/admissions': ['super', 'admin'],
  '/admin/hero': ['super', 'admin'],
  '/admin/statements': ['super', 'admin'],
  '/admin/leadership': ['super', 'admin'],
  '/admin/settings': ['super', 'admin'],
  '/admin/users': ['super'],
  '/admin/password': ['super', 'admin', 'editor', 'contributor'],
  '/admin/mfa': ['super', 'admin']
};
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
function can(role, route) {
  const base = '/' + route.split('/').slice(1, 3).join('/');
  const roles = ROLE_PAGES[base];
  return !!roles && roles.includes(role);
}
function adminCtx(req, res) {
  const s = sessionFor(req);
  const user = s && s.username ? L.data.users.find(u => u.username === s.username) : null;
  return {
    db: L.data, path: req.url, session: user ? { username: user.username, role: user.role, csrf: s.csrf, mustChangePassword: !!user.mustChangePassword, mustSetupMfa: !!user.mustSetupMfa } : null
  };
}
function requireAuth(req, res, route) {
  const ctx = adminCtx(req, res);
  if (!ctx.session) return { login: true, ctx };
  if (!can(ctx.session.role, route)) {
    send(res, 403, X.page(ctx, '<section class="sec"><div class="container"><h1>Not permitted</h1><p class="muted">Your role does not allow access to this area of the CMS.</p></div></section>', { title: 'Not permitted' }));
    return { blocked: true };
  }
  if (ctx.session.mustChangePassword && route !== '/admin/password') {
    redirect(res, '/admin/password');
    return { blocked: true };
  }
  if (ctx.session.mustSetupMfa && !ctx.session.mustChangePassword && route !== '/admin/mfa') {
    redirect(res, '/admin/mfa');
    return { blocked: true };
  }
  return { ctx };
}
function checkCsrf(req, body, ctx) {
  return body._csrf && ctx.session && body._csrf === ctx.session.csrf;
}

async function auditEvent(req, action, resourceType, resourceId, result, metadata = {}, actor = null) {
  const session = sessionFor(req);
  try {
    await L.writeAuditEvent({
      userId: actor || session && session.username || 'anonymous', action, resourceType, resourceId,
      result, ipAddress: ipOf(req), userAgent: String(req.headers['user-agent'] || '').slice(0, 500), metadata
    });
  } catch (error) {
    console.error(`[audit] failed to record ${action}`);
  }
}

async function saveAdminChange(req, route, collection, resourceId = '') {
  await L.saveDB(collection);
  await auditEvent(req, route, collection, String(resourceId || ''), 'success');
}

async function handleAdmin(req, res, url) {
  const route = url.pathname;
  /* login */
  if (route === '/admin/login' && req.method === 'POST') {
    if (!L.rateLimit('login:ip:' + ipOf(req), 30, 10 * 60 * 1000)) return send(res, 429, A.loginPage({ publicCsrf: '' }, 'Too many attempts. Please wait a few minutes and try again.'));
    const s = sessionFor(req) || getOrAnonSession(req, res);
    const body = await L.parseForm(req);
    if (!body._csrf || body._csrf !== s.csrf) return send(res, 403, A.loginPage({ publicCsrf: s.csrf }, 'Session expired — please try again.'));
    const attemptedUsername = clean(body.username).toLowerCase().slice(0, 100) || 'unknown';
    if (!L.rateLimit('login:user:' + attemptedUsername, 6, 10 * 60 * 1000)) return send(res, 429, A.loginPage({ publicCsrf: s.csrf }, 'Too many attempts for this account. Please wait a few minutes and try again.'));
    const user = L.data.users.find(u => u.username.toLowerCase() === attemptedUsername);
    if (!user || String(body.password || '').length > 1024 || !L.verifyUser(user, body.password || '')) {
      await auditEvent(req, 'auth.login', 'user', attemptedUsername, 'failure', {}, attemptedUsername);
      return send(res, 401, A.loginPage({ publicCsrf: s.csrf }, 'Incorrect username or password.'));
    }
    if (user.mfaEnabled) {
      const allowed = L.rateLimit('login:mfa:' + attemptedUsername, 6, 10 * 60 * 1000);
      let validCode = false;
      try { validCode = allowed && L.verifyTotp(L.decryptMfaSecret(user.mfaSecretEnc), body.mfa_code); } catch (error) {}
      if (!validCode) {
        await auditEvent(req, 'auth.mfa', 'user', attemptedUsername, 'failure', {}, attemptedUsername);
        return send(res, 401, A.loginPage({ publicCsrf: s.csrf }, 'Authenticator code is invalid or expired.'));
      }
    }
    const token = crypto.randomBytes(24).toString('hex');
    const rec = { username: user.username, role: user.role, csrf: crypto.randomBytes(16).toString('hex'), created: Date.now(), mustChangePassword: !!user.mustChangePassword, mustSetupMfa: !!user.mustSetupMfa };
    anonSessions.delete(s.token);
    promotedSessions.set(token, rec);
    res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', token, { secure: secure(req), maxAge: 60 * 60 * 10 }));
    await auditEvent(req, 'auth.login', 'user', user.username, 'success', {}, user.username);
    return redirect(res, user.mustChangePassword ? '/admin/password' : user.mustSetupMfa ? '/admin/mfa' : '/admin');
  }
  if (route === '/admin/logout' && req.method === 'POST') {
    const session = sessionFor(req);
    const body = await L.parseForm(req);
    if (!session || !body._csrf || body._csrf !== session.csrf) return send(res, 403, 'Security check failed');
    await auditEvent(req, 'auth.logout', 'user', session.username || 'anonymous', 'success');
    const cookies = L.parseCookies(req);
    promotedSessions.delete(cookies.alpha_sid);
    anonSessions.delete(cookies.alpha_sid);
    mfaSetupSessions.delete(cookies.alpha_sid);
    res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', '', { maxAge: 0 }));
    return redirect(res, '/admin');
  }
  if (route === '/admin' || route === '/admin/') {
    const s = sessionFor(req);
    if (!s || !s.username) {
      const ses = s || getOrAnonSession(req, res);
      return send(res, 200, A.loginPage({ publicCsrf: ses.csrf }, url.searchParams.get('error') || ''));
    }
    const user = L.data.users.find(item => item.username === s.username);
    if (user && user.mustChangePassword) return redirect(res, '/admin/password');
    if (user && user.mustSetupMfa) return redirect(res, '/admin/mfa');
    const ctx = { db: L.data, path: route, session: { username: s.username, role: s.role, csrf: s.csrf } };
    return send(res, 200, A.dashboard(ctx));
  }
  /* authenticated GET pages */
  if (req.method === 'GET') {
    const { login, blocked, ctx } = requireAuth(req, res, route);
    if (login) return redirect(res, '/admin');
    if (blocked) return;
    const msg = url.searchParams.get('msg') || '';
    if (route === '/admin/password') return send(res, 200, A.passwordPage(ctx, msg));
    if (route === '/admin/mfa') {
      const user = L.data.users.find(item => item.username === ctx.session.username);
      if (!user || !user.mustSetupMfa) return redirect(res, '/admin');
      const session = sessionFor(req);
      let secret = mfaSetupSessions.get(session.token);
      if (!secret) { secret = L.createTotpSecret(); mfaSetupSessions.set(session.token, secret); }
      return send(res, 200, A.mfaSetupPage(ctx, secret, msg));
    }
    if (route === '/admin/news') return send(res, 200, A.newsPage(ctx, url.searchParams.get('edit') ? L.data.news.find(n => n.slug === url.searchParams.get('edit')) : null, msg));
    if (route === '/admin/announcements') return send(res, 200, A.announcementsPage(ctx, msg));
    if (route === '/admin/gallery') { const e = url.searchParams.get('edit'); return send(res, 200, A.galleryPage(ctx, e != null ? parseInt(e, 10) : null, msg)); }
    if (route === '/admin/courses') return send(res, 200, A.coursesPage(ctx, msg));
    if (route === '/admin/hero') return send(res, 200, A.heroPage(ctx, msg));
    if (route === '/admin/statements') return send(res, 200, A.statementsPage(ctx, msg));
    if (route === '/admin/leadership') return send(res, 200, A.leadershipPage(ctx, msg));
    if (route === '/admin/settings') return send(res, 200, A.settingsPage(ctx, msg));
    if (route === '/admin/submissions') return send(res, 200, A.submissionsPage(ctx));
    if (route === '/admin/admissions') return send(res, 200, A.admissionsPage(ctx, msg, ADMISSION_TRANSITIONS));
    if (route === '/admin/users') return send(res, 200, A.usersPage(ctx, msg));
    return redirect(res, '/admin');
  }
  /* POST actions */
  if (req.method === 'POST') {
    const { login, blocked, ctx } = requireAuth(req, res, route);
    if (login) return redirect(res, '/admin');
    if (blocked) return;
    const body = await L.parseForm(req);
    if (!checkCsrf(req, body, ctx)) return send(res, 403, X.page(ctx, '<section class="sec"><div class="container"><h1>Security check failed</h1><p>Please return to the CMS and try again.</p></div></section>', { title: 'CSRF' }));
    const db = L.data;
    const back = b => redirect(res, b + '?msg=' + encodeURIComponent('Saved'));

    if (route === '/admin/admissions/status') {
      const reference = clean(body.applicationReference);
      const application = db.admissionApplications.find(item => item.applicationReference === reference);
      const nextStatus = clean(body.status);
      if (!application || !ADMISSION_TRANSITIONS[application.status] || !ADMISSION_TRANSITIONS[application.status].includes(nextStatus)) {
        return redirect(res, '/admin/admissions?msg=' + encodeURIComponent('Invalid or unavailable status transition'));
      }
      const updated = await L.updateAdmissionStatus(reference, [application.status], nextStatus);
      if (!updated) return redirect(res, '/admin/admissions?msg=' + encodeURIComponent('Application changed in another session; refresh and try again'));
      await auditEvent(req, 'admission.status_change', 'admissionApplication', reference, 'success', { from: application.status, to: nextStatus });
      return redirect(res, '/admin/admissions?msg=' + encodeURIComponent('Application status updated'));
    }

    if (route === '/admin/password') {
      const userIndex = db.users.findIndex(user => user.username === ctx.session.username);
      const user = db.users[userIndex];
      if (!user || !L.verifyUser(user, body.current_password || '')) return send(res, 400, A.passwordPage(ctx, 'Current password is incorrect.'));
      if (!L.isStrongPassword(body.new_password) || body.new_password !== body.confirm_password) return send(res, 400, A.passwordPage(ctx, 'Use a strong new password and enter it the same way twice.'));
      const updated = L.newUser(user.username, user.name, user.role, body.new_password);
      updated.mustChangePassword = false;
      updated.mustSetupMfa = !!user.mustSetupMfa;
      db.users[userIndex] = updated;
      await L.saveDB('users');
      await auditEvent(req, 'auth.password_change', 'user', user.username, 'success');
      for (const [token, session] of promotedSessions) if (session.username === user.username) promotedSessions.delete(token);
      const token = crypto.randomBytes(24).toString('hex');
      promotedSessions.set(token, { username: user.username, role: user.role, csrf: crypto.randomBytes(16).toString('hex'), created: Date.now(), mustSetupMfa: updated.mustSetupMfa });
      res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', token, { secure: secure(req), maxAge: 60 * 60 * 10 }));
      return redirect(res, '/admin');
    }
    if (route === '/admin/mfa') {
      const userIndex = db.users.findIndex(user => user.username === ctx.session.username);
      const user = db.users[userIndex];
      const session = sessionFor(req);
      const secret = session && mfaSetupSessions.get(session.token);
      if (!user || !user.mustSetupMfa || !secret || !L.verifyTotp(secret, body.mfa_code)) return send(res, 400, A.mfaSetupPage(ctx, secret || '', 'Authenticator code is invalid or expired.'));
      user.mfaSecretEnc = L.encryptMfaSecret(secret);
      user.mfaEnabled = true;
      user.mustSetupMfa = false;
      await L.saveDB('users');
      await auditEvent(req, 'auth.mfa_enroll', 'user', user.username, 'success', {}, user.username);
      const oldToken = L.parseCookies(req).alpha_sid;
      mfaSetupSessions.delete(oldToken);
      for (const [token, record] of promotedSessions) if (record.username === user.username) promotedSessions.delete(token);
      const token = crypto.randomBytes(24).toString('hex');
      promotedSessions.set(token, { username: user.username, role: user.role, csrf: crypto.randomBytes(16).toString('hex'), created: Date.now() });
      res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', token, { secure: secure(req), maxAge: 60 * 60 * 10 }));
      return redirect(res, '/admin');
    }

    if (route === '/admin/news/save') {
      if (!clean(body.title)) return redirect(res, '/admin/news?msg=' + encodeURIComponent('Title is required'));
      const slug = body.slug ? clean(body.slug) : L.slugify(body.title);
      const existing = db.news.find(n => n.slug === slug);
      const item = existing || {};
      item.title = clean(body.title);
      item.slug = slug;
      item.category = clean(body.category);
      item.dateLabel = clean(body.dateLabel) || String(new Date().getFullYear());
      item.sortDate = (existing && existing.sortDate) || new Date().toISOString().slice(0, 10);
      item.author = clean(body.author) || 'School Administration';
      item.image = clean(body.image) || 'graduation-group';
      item.alt = clean(body.alt);
      item.excerpt = clean(body.excerpt);
      item.content = clean(body.content).split(/\n{2,}/).filter(Boolean);
      item.gallery = clean(body.gallery).split(',').map(s => s.trim()).filter(Boolean);
      if (!item.gallery.length) item.gallery = [item.image];
      item.featured = !!body.featured;
      if (item.featured) db.news.forEach(n => { if (n !== item) n.featured = false; });
      if (!existing) db.news.unshift(item);
      await saveAdminChange(req, route, 'news', slug);
      return back('/admin/news');
    }
    if (route === '/admin/news/delete') {
      db.news = db.news.filter(n => n.slug !== clean(body.slug));
      await saveAdminChange(req, route, 'news', body.slug); return redirect(res, '/admin/news?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/announcements/save') {
      if (!clean(body.title)) return redirect(res, '/admin/announcements?msg=' + encodeURIComponent('Title required'));
      db.announcements.unshift({ title: clean(body.title), body: clean(body.body), tag: clean(body.tag), dateLabel: clean(body.dateLabel) || String(new Date().getFullYear()) });
      await saveAdminChange(req, route, 'announcements', body.title); return back('/admin/announcements');
    }
    if (route === '/admin/announcements/delete') {
      db.announcements.splice(parseInt(body.index, 10), 1); await saveAdminChange(req, route, 'announcements', body.index);
      return redirect(res, '/admin/announcements?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/gallery/save') {
      const item = { img: clean(body.img), cat: clean(body.cat), caption: clean(body.caption), alt: clean(body.alt) };
      if (!item.caption) return redirect(res, '/admin/gallery?msg=' + encodeURIComponent('Caption required'));
      if (body.index != null && body.index !== '') db.gallery[parseInt(body.index, 10)] = item;
      else db.gallery.push(item);
      await saveAdminChange(req, route, 'gallery', body.index || item.caption); return back('/admin/gallery');
    }
    if (route === '/admin/gallery/delete') {
      db.gallery.splice(parseInt(body.index, 10), 1); await saveAdminChange(req, route, 'gallery', body.index);
      return redirect(res, '/admin/gallery?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/courses/save') {
      if (!clean(body.title)) return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Title required'));
      db.courses.push({ title: clean(body.title), blurb: clean(body.blurb), audience: clean(body.audience), status: body.status === 'current' ? 'current' : 'proposed' });
      await saveAdminChange(req, route, 'courses', body.title); return back('/admin/courses');
    }
    if (route === '/admin/courses/toggle') {
      const c = db.courses[parseInt(body.index, 10)];
      c.status = c.status === 'current' ? 'proposed' : 'current'; await saveAdminChange(req, route, 'courses', c.title);
      return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Status updated'));
    }
    if (route === '/admin/courses/delete') {
      db.courses.splice(parseInt(body.index, 10), 1); await saveAdminChange(req, route, 'courses', body.index);
      return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/hero/save') {
      const h = db.hero[parseInt(body.index, 10)];
      h.kicker = clean(body.kicker); h.title = clean(body.title); h.strap = clean(body.strap);
      h.text = clean(body.text); h.image = clean(body.image); h.alt = clean(body.alt);
      h.cta = { label: clean(body.cta_label), href: clean(body.cta_href) };
      h.cta2 = clean(body.cta2_label) ? { label: clean(body.cta2_label), href: clean(body.cta2_href) } : null;
      h.enabled = !!body.enabled;
      await saveAdminChange(req, route, 'hero', body.index); return back('/admin/hero');
    }
    if (route === '/admin/statements/save') {
      db.statements.mission = clean(body.mission);
      db.statements.vision = clean(body.vision);
      db.statements.philosophy = clean(body.philosophy);
      await saveAdminChange(req, route, 'statements', 'primary'); return back('/admin/statements');
    }
    if (route === '/admin/leadership/save') {
      db.leadership.name = clean(body.name);
      db.leadership.title = clean(body.title);
      db.leadership.photo = clean(body.photo) || null;
      db.leadership.signature = clean(body.signature);
      db.leadership.shortMessage = clean(body.shortMessage);
      db.leadership.fullMessage = clean(body.fullMessage).split(/\n{2,}/).filter(Boolean);
      await saveAdminChange(req, route, 'leadership', db.leadership.name); return back('/admin/leadership');
    }
    if (route === '/admin/settings/save') {
      const s = db.settings;
      s.locationText = clean(body.locationText); s.mapNote = clean(body.mapNote);
      const p1 = clean(body.phone1).split('|'), p2 = clean(body.phone2).split('|');
      s.phones = [
        { label: p1[0] || 'School Office', number: p1[1] || p1[0], href: (p1[2] || p1[1] || '').replace(/\s/g, '') },
        { label: p2[0] || 'Head of School\'s Office', number: p2[1] || p2[0], href: (p2[2] || p2[1] || '').replace(/\s/g, '') }
      ];
      const w = clean(body.whatsapp).split('|');
      s.whatsapp = { number: w[0], href: (w[1] || w[0]).replace(/\s/g, '') };
      s.email = clean(body.email); s.emailPublished = !!body.emailPublished;
      s.address.box = clean(body.box); s.address.city = clean(body.city);
      s.officeHours = clean(body.officeHours); s.socialNote = clean(body.socialNote);
      s.centreCode = clean(body.centreCode);
      await saveAdminChange(req, route, 'settings', 'primary'); return back('/admin/settings');
    }
    if (route === '/admin/submissions/delete') {
      db.submissions.splice(parseInt(body.index, 10), 1); await saveAdminChange(req, route, 'submissions', body.index);
      return redirect(res, '/admin/submissions?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/users/save') {
      const username = clean(body.username).toLowerCase();
      const name = clean(body.name);
      const role = clean(body.role);
      if (!username || !name || !body.password) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Username, name and password are required'));
      if (!['super', 'admin', 'editor', 'contributor'].includes(role)) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Invalid role'));
      if (!L.isStrongPassword(body.password)) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Password must be at least 12 characters with uppercase, lowercase, number and symbol'));
      if (['super', 'admin'].includes(role)) L.validateMfaEncryptionKey();
      if (db.users.some(u => u.username.toLowerCase() === username)) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Username already exists'));
      const user = L.newUser(username, name, role, body.password);
      if (['super', 'admin'].includes(role)) user.mustSetupMfa = true;
      db.users.push(user);
      await L.saveDB('users');
      await auditEvent(req, 'user.create', 'user', username, 'success', { role });
      return back('/admin/users');
    }
    if (route === '/admin/users/delete') {
      const i = parseInt(body.index, 10);
      if (db.users[i] && db.users[i].username !== ctx.session.username) {
        const username = db.users[i].username;
        db.users.splice(i, 1);
        await L.saveDB('users');
        await auditEvent(req, 'user.delete', 'user', username, 'success');
      }
      return redirect(res, '/admin/users?msg=' + encodeURIComponent('User removed'));
    }
    return redirect(res, '/admin');
  }
}
const promotedSessions = new Map();
/* extend lib session lookup to include promoted sessions */
const origGetSession = L.getSession;
L.getSession = function (req) {
  const cookies = L.parseCookies(req);
  const t = cookies.alpha_sid;
  if (t && promotedSessions.has(t)) {
    const r = promotedSessions.get(t);
    if (Date.now() - r.created > 1000 * 60 * 60 * 10) { promotedSessions.delete(t); return null; }
    return { token: t, ...r };
  }
  return origGetSession(req);
};

/* ---------- server ---------- */
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://local');
  const p = url.pathname;
  try {
    if (secure(req)) res.setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    /* static */
    if (['/img/', '/css/', '/js/', '/fonts/'].some(pre => p.startsWith(pre)) || p === '/favicon.ico') {
      if (L.serveStatic(req, res, p === '/favicon.ico' ? '/img/favicon-32.png' : p, PUBLIC_DIR)) return;
      return send(res, 404, 'Not found');
    }
    if (p === '/robots.txt') { res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' }); return res.end(ROBOTS); }
    if (p === '/sitemap.xml') { res.writeHead(200, { 'Content-Type': 'application/xml' }); return res.end(sitemap()); }
    if (p === '/site.webmanifest') { res.writeHead(200, { 'Content-Type': 'application/manifest+json' }); return res.end(MANIFEST); }

    /* public forms */
    if (FORM_RULES[p] && req.method === 'POST') return handlePublicForm(req, res, p);

    /* admin */
    if (p === '/admin' || p.startsWith('/admin/')) return handleAdmin(req, res, url);

    /* news */
    if (req.method === 'GET') {
      if (p === '/news' || p === '/news/') {
        const mod = require('./src/pages/news');
        const ctx = { db: L.data, path: p };
        const { body, meta } = mod.list(ctx);
        return send(res, 200, X.page(ctx, body, meta));
      }
      if (p.startsWith('/news/')) {
        const mod = require('./src/pages/news');
        const ctx = { db: L.data, path: p };
        const out = mod.article(ctx, decodeURIComponent(p.slice(6)));
        if (!out) return send(res, 404, X.page(ctx, '<section class="sec"><div class="container"><h1>Article not found</h1><p class="muted">The article you are looking for may have been moved. <a href="/news">Browse all news</a>.</p></div></section>', { title: 'Not found' }));
        return send(res, 200, X.page(ctx, out.body, out.meta));
      }
      const mod = pages[p];
      if (mod) {
        const ctx = { db: L.data, path: p };
        const { body, meta } = mod.render(ctx);
        return send(res, 200, X.page(ctx, body, meta));
      }
      return send(res, 404, X.page({ db: L.data, path: p }, `
        <section class="sec"><div class="container text-center">
          <h1 style="font-size:clamp(3rem,8vw,6rem);color:var(--gold)">404</h1>
          <p class="muted" style="max-width:46ch;margin:0 auto 24px">The page you are looking for is not on this website. Let us take you back to familiar ground.</p>
          <a class="btn btn--primary" href="/">Back to Home</a>
        </div></section>`, { title: 'Page not found — Alpha Adventist Pre & Primary School' }));
    }
    return send(res, 405, 'Method not allowed');
  } catch (err) {
    console.error('[error]', p, err.name, err.code || '');
    try { send(res, 500, '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px"><h1>Something went wrong</h1><p>Please try again, or call the school office.</p></body></html>'); } catch (e) {}
  }
});

async function start() {
  await L.initDB();
  await seedUsers();
  await purgeExpiredSubmissions();
  server.listen(PORT, '0.0.0.0', () => console.log(`[alpha] serving on :${PORT}`));
  if (process.env.FORM_RETENTION_DAYS) {
    const retentionTimer = setInterval(() => purgeExpiredSubmissions().catch(() => console.error('[retention] scheduled purge failed')), 24 * 60 * 60 * 1000);
    retentionTimer.unref();
  }
}

let shuttingDown = false;
async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`[alpha] received ${signal}; draining requests`);
  server.close(async error => {
    if (error) process.exitCode = 1;
    try { await L.closeDB(); } catch (closeError) { process.exitCode = 1; }
  });
  if (server.closeIdleConnections) server.closeIdleConnections();
}

process.once('SIGINT', () => shutdown('SIGINT'));
process.once('SIGTERM', () => shutdown('SIGTERM'));

start().catch(error => {
  console.error('[startup] database initialization failed; check MONGO_URI and database access.');
  process.exitCode = 1;
});
