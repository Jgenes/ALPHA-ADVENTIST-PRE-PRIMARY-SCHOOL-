'use strict';
/* Alpha Adventist Pre & Primary School — official website platform
   Zero-dependency Node.js server: SSR public site + JSON-file CMS + role-based admin. */
const http = require('http');
const path = require('path');
const crypto = require('crypto');
const L = require('./src/lib');
const X = require('./src/layout');
const { esc } = L;

const PUBLIC_DIR = path.join(__dirname, 'public');
const PORT = process.env.PORT || 3000;
const BASE_URL = process.env.BASE_URL || 'https://alphaadventist.ac.tz';

/* seed a default super-admin on first run (change after first sign-in) */
(function seedUsers() {
  const db = L.data;
  if (!db.users.length) {
    db.users.push(L.newUser('admin', 'School Administrator', 'super', process.env.ADMIN_PASSWORD || 'Alpha@2026!'));
    L.saveDB();
    console.log('[cms] default super-admin created: username "admin" (see README)');
  }
})();

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
  return (req.headers['x-forwarded-for'] || '').split(',')[0].trim() || req.socket.remoteAddress || 'unknown';
}
function secure(req) {
  return (req.headers['x-forwarded-proto'] || 'https') === 'https';
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
  '/api/contact': { type: 'Contact enquiry', required: ['name', 'phone', 'message'] },
  '/api/visit': { type: 'School visit request', required: ['name', 'phone'] },
  '/api/apply': { type: 'Admission application', required: ['child_name', 'guardian_name', 'phone', 'level', 'arrangement'] },
  '/api/computer-class': { type: 'Computer class enquiry', required: ['name', 'phone'] },
  '/api/computer-interest': { type: 'Community training interest', required: ['name', 'phone'] }
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
  const out = {};
  Object.keys(data).forEach(k => { out[k] = clean(data[k]); });
  L.data.submissions.push({ type: rule.type, data: out, at: Date.now() });
  L.saveDB();
  const msgs = {
    'Contact enquiry': 'Thank you — your message has reached the school office. We respond during working hours on school days.',
    'School visit request': 'Asante! Your visit request has been received. The school office will confirm a convenient day with you.',
    'Admission application': 'Your application request has been received. The admissions office will contact you with the next steps.',
    'Computer class enquiry': 'Received! The school office will contact you about computer class placement.',
    'Community training interest': 'Your interest has been registered. You will be contacted when approved registration opens.'
  };
  json(res, 200, { ok: true, message: msgs[rule.type] });
}

/* ---------- admin ---------- */
const A = require('./src/pages/admin');
const ROLE_PAGES = {
  '/admin/news': ['super', 'admin', 'editor', 'contributor'],
  '/admin/announcements': ['super', 'admin', 'editor', 'contributor'],
  '/admin/gallery': ['super', 'admin', 'editor'],
  '/admin/courses': ['super', 'admin', 'editor'],
  '/admin/submissions': ['super', 'admin', 'editor'],
  '/admin/hero': ['super', 'admin'],
  '/admin/statements': ['super', 'admin'],
  '/admin/leadership': ['super', 'admin'],
  '/admin/settings': ['super', 'admin'],
  '/admin/users': ['super']
};
function can(role, route) {
  const base = '/' + route.split('/').slice(1, 3).join('/');
  const roles = ROLE_PAGES[base];
  if (!roles) return ['super', 'admin'].includes(role);
  return roles.includes(role);
}
function adminCtx(req, res) {
  const s = sessionFor(req);
  const user = s && s.username ? L.data.users.find(u => u.username === s.username) : null;
  return {
    db: L.data, path: req.url, session: user ? { username: user.username, role: user.role, csrf: s.csrf } : null
  };
}
function requireAuth(req, res, route) {
  const ctx = adminCtx(req, res);
  if (!ctx.session) return { login: true, ctx };
  if (!can(ctx.session.role, route)) {
    send(res, 403, X.page(ctx, '<section class="sec"><div class="container"><h1>Not permitted</h1><p class="muted">Your role does not allow access to this area of the CMS.</p></div></section>', { title: 'Not permitted' }));
    return { blocked: true };
  }
  return { ctx };
}
function checkCsrf(req, body, ctx) {
  return body._csrf && ctx.session && body._csrf === ctx.session.csrf;
}

async function handleAdmin(req, res, url) {
  const route = url.pathname;
  /* login */
  if (route === '/admin/login' && req.method === 'POST') {
    if (!L.rateLimit('login:' + ipOf(req), 6, 10 * 60 * 1000)) return send(res, 429, A.loginPage({ publicCsrf: '' }, 'Too many attempts. Please wait a few minutes and try again.'));
    const s = sessionFor(req) || getOrAnonSession(req, res);
    const body = await L.parseForm(req);
    if (!body._csrf || body._csrf !== s.csrf) return send(res, 403, A.loginPage({ publicCsrf: s.csrf }, 'Session expired — please try again.'));
    const user = L.data.users.find(u => u.username === clean(body.username));
    if (!user || !L.verifyUser(user, body.password || '')) {
      return send(res, 401, A.loginPage({ publicCsrf: s.csrf }, 'Incorrect username or password.'));
    }
    /* promote session */
    const rec = { username: user.username, role: user.role, csrf: crypto.randomBytes(16).toString('hex'), created: Date.now() };
    anonSessions.delete(s.token);
    promotedSessions.set(s.token, rec);
    res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', s.token, { secure: secure(req), maxAge: 60 * 60 * 10 }));
    return redirect(res, '/admin');
  }
  if (route === '/admin/logout' && req.method === 'POST') {
    const cookies = L.parseCookies(req);
    promotedSessions.delete(cookies.alpha_sid);
    anonSessions.delete(cookies.alpha_sid);
    res.setHeader('Set-Cookie', L.cookieHeader('alpha_sid', '', { maxAge: 0 }));
    return redirect(res, '/admin');
  }
  if (route === '/admin' || route === '/admin/') {
    const s = sessionFor(req);
    if (!s || !s.username) {
      const ses = s || getOrAnonSession(req, res);
      return send(res, 200, A.loginPage({ publicCsrf: ses.csrf }, url.searchParams.get('error') || ''));
    }
    const ctx = { db: L.data, path: route, session: { username: s.username, role: s.role, csrf: s.csrf } };
    return send(res, 200, A.dashboard(ctx));
  }
  /* authenticated GET pages */
  if (req.method === 'GET') {
    const { login, blocked, ctx } = requireAuth(req, res, route);
    if (login) return redirect(res, '/admin');
    if (blocked) return;
    const msg = url.searchParams.get('msg') || '';
    if (route === '/admin/news') return send(res, 200, A.newsPage(ctx, url.searchParams.get('edit') ? L.data.news.find(n => n.slug === url.searchParams.get('edit')) : null, msg));
    if (route === '/admin/announcements') return send(res, 200, A.announcementsPage(ctx, msg));
    if (route === '/admin/gallery') { const e = url.searchParams.get('edit'); return send(res, 200, A.galleryPage(ctx, e != null ? parseInt(e, 10) : null, msg)); }
    if (route === '/admin/courses') return send(res, 200, A.coursesPage(ctx, msg));
    if (route === '/admin/hero') return send(res, 200, A.heroPage(ctx, msg));
    if (route === '/admin/statements') return send(res, 200, A.statementsPage(ctx, msg));
    if (route === '/admin/leadership') return send(res, 200, A.leadershipPage(ctx, msg));
    if (route === '/admin/settings') return send(res, 200, A.settingsPage(ctx, msg));
    if (route === '/admin/submissions') return send(res, 200, A.submissionsPage(ctx));
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
      L.saveDB();
      return back('/admin/news');
    }
    if (route === '/admin/news/delete') {
      db.news = db.news.filter(n => n.slug !== clean(body.slug));
      L.saveDB(); return redirect(res, '/admin/news?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/announcements/save') {
      if (!clean(body.title)) return redirect(res, '/admin/announcements?msg=' + encodeURIComponent('Title required'));
      db.announcements.unshift({ title: clean(body.title), body: clean(body.body), tag: clean(body.tag), dateLabel: clean(body.dateLabel) || String(new Date().getFullYear()) });
      L.saveDB(); return back('/admin/announcements');
    }
    if (route === '/admin/announcements/delete') {
      db.announcements.splice(parseInt(body.index, 10), 1); L.saveDB();
      return redirect(res, '/admin/announcements?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/gallery/save') {
      const item = { img: clean(body.img), cat: clean(body.cat), caption: clean(body.caption), alt: clean(body.alt) };
      if (!item.caption) return redirect(res, '/admin/gallery?msg=' + encodeURIComponent('Caption required'));
      if (body.index != null && body.index !== '') db.gallery[parseInt(body.index, 10)] = item;
      else db.gallery.push(item);
      L.saveDB(); return back('/admin/gallery');
    }
    if (route === '/admin/gallery/delete') {
      db.gallery.splice(parseInt(body.index, 10), 1); L.saveDB();
      return redirect(res, '/admin/gallery?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/courses/save') {
      if (!clean(body.title)) return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Title required'));
      db.courses.push({ title: clean(body.title), blurb: clean(body.blurb), audience: clean(body.audience), status: body.status === 'current' ? 'current' : 'proposed' });
      L.saveDB(); return back('/admin/courses');
    }
    if (route === '/admin/courses/toggle') {
      const c = db.courses[parseInt(body.index, 10)];
      c.status = c.status === 'current' ? 'proposed' : 'current'; L.saveDB();
      return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Status updated'));
    }
    if (route === '/admin/courses/delete') {
      db.courses.splice(parseInt(body.index, 10), 1); L.saveDB();
      return redirect(res, '/admin/courses?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/hero/save') {
      const h = db.hero[parseInt(body.index, 10)];
      h.kicker = clean(body.kicker); h.title = clean(body.title); h.strap = clean(body.strap);
      h.text = clean(body.text); h.image = clean(body.image); h.alt = clean(body.alt);
      h.cta = { label: clean(body.cta_label), href: clean(body.cta_href) };
      h.cta2 = clean(body.cta2_label) ? { label: clean(body.cta2_label), href: clean(body.cta2_href) } : null;
      h.enabled = !!body.enabled;
      L.saveDB(); return back('/admin/hero');
    }
    if (route === '/admin/statements/save') {
      db.statements.mission = clean(body.mission);
      db.statements.vision = clean(body.vision);
      db.statements.philosophy = clean(body.philosophy);
      L.saveDB(); return back('/admin/statements');
    }
    if (route === '/admin/leadership/save') {
      db.leadership.name = clean(body.name);
      db.leadership.title = clean(body.title);
      db.leadership.photo = clean(body.photo) || null;
      db.leadership.signature = clean(body.signature);
      db.leadership.shortMessage = clean(body.shortMessage);
      db.leadership.fullMessage = clean(body.fullMessage).split(/\n{2,}/).filter(Boolean);
      L.saveDB(); return back('/admin/leadership');
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
      L.saveDB(); return back('/admin/settings');
    }
    if (route === '/admin/submissions/delete') {
      db.submissions.splice(parseInt(body.index, 10), 1); L.saveDB();
      return redirect(res, '/admin/submissions?msg=' + encodeURIComponent('Deleted'));
    }
    if (route === '/admin/users/save') {
      if (!clean(body.username) || !body.password) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Username and password required'));
      if (db.users.some(u => u.username === clean(body.username))) return redirect(res, '/admin/users?msg=' + encodeURIComponent('Username already exists'));
      db.users.push(L.newUser(clean(body.username), clean(body.name), clean(body.role), body.password));
      L.saveDB(); return back('/admin/users');
    }
    if (route === '/admin/users/delete') {
      const i = parseInt(body.index, 10);
      if (db.users[i] && db.users[i].username !== ctx.session.username) { db.users.splice(i, 1); L.saveDB(); }
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
    console.error('[error]', p, err);
    try { send(res, 500, '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px"><h1>Something went wrong</h1><p>Please try again, or call the school office.</p></body></html>'); } catch (e) {}
  }
});

server.listen(PORT, '0.0.0.0', () => console.log(`[alpha] serving on :${PORT}`));
