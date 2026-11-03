'use strict';
require('dotenv').config();
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const L = require('./src/lib');
const X = require('./src/layout');
const { createConfig } = require('./src/config');
const { openStore } = require('./src/platform/store');
const { Files } = require('./src/platform/files');
const { Auth, requestIp } = require('./src/platform/auth');
const { Platform } = require('./src/platform/service');
const { handleApi, json, sendFile } = require('./src/platform/router');
const { audit } = require('./src/platform/audit');
const { startJobs } = require('./src/platform/jobs');
const { portal } = require('./src/pages/portal');
const seed = require('./data/seed.json');
const PUBLIC_DIR = path.join(__dirname, 'public');
const pages = {
  '/': require('./src/pages/home'), '/about': require('./src/pages/about'),
  '/academics': require('./src/pages/academics'), '/admissions': require('./src/pages/admissions'),
  '/computer-learning': require('./src/pages/computer'), '/school-life': require('./src/pages/schoollife'),
  '/faith': require('./src/pages/faith'), '/parents': require('./src/pages/parents'),
  '/students': require('./src/pages/students'), '/gallery': require('./src/pages/gallery'),
  '/contact': require('./src/pages/contact'), '/privacy': require('./src/pages/privacy'),
  '/downloads': require('./src/pages/downloads'), '/safeguarding': require('./src/pages/safeguarding'),
  '/talents': require('./src/pages/talents')
};
const SW_PAGES = ['/sw', '/sw/admissions', '/sw/contact'];
function send(res, status, html, headers = {}) {
  const body = Buffer.from(html);
  res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Length': body.length, 'Cache-Control': 'no-cache', ...headers });
  res.end(body);
}
function safeErrorMessage(error) {
  return String(error?.message || error?.name || 'Unknown error')
    .replace(/mongodb(?:\+srv)?:\/\/[^\s"'`]+/gi, '[redacted MongoDB URI]')
    .replace(/\b(password|passwd|secret|token|authorization|api[_-]?key)\s*[:=]\s*("[^"]*"|'[^']*'|[^\s,;]+)/gi, '$1=[redacted]')
    .replace(/[\r\n\t]+/g, ' ')
    .slice(0, 500);
}
function safeErrorLocation(error) {
  return String(error?.stack || '').split('\n').slice(1, 4).join(' ').replace(/\s+/g, ' ').slice(0, 500);
}
function redirect(res, target, status = 303) { res.writeHead(status, { Location: target, 'Cache-Control': 'no-store' }); res.end(); }
function headers(req, res, config, nonce) {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  if (config.environment !== 'production') res.setHeader('X-Robots-Tag', 'noindex, nofollow');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
  res.setHeader('Cross-Origin-Resource-Policy', 'same-origin');
  const frames = config.production ? "'self'" : "'self' https://arena.ai https://*.arena.ai https://*.e2b.app https://lmarena.ai https://*.lmarena.ai";
  res.setHeader('Content-Security-Policy', `default-src 'self'; script-src 'self' 'nonce-${nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src 'none'; frame-ancestors ${frames}; manifest-src 'self'; worker-src 'self'`);
  if (config.production || req.socket.encrypted) res.setHeader('Strict-Transport-Security', 'max-age=31536000');
}
async function publicContext(platform, url, nonce) {
  const db = structuredClone(seed);
  const content = await platform.publicContent();
  db.settings = require('./src/platform/school-details').applyDetails(db.settings, content);
  const media = await platform.publicMedia();
  db.news = content.filter(item => item.kind === 'news').map(item => ({ ...item, image: item.mediaId ? '/media/' + item.mediaId : 'campus', alt: media.find(image => image.id === item.mediaId)?.alt || 'Alpha school crest — photograph awaiting approval', gallery: [], author: item.author || 'School Communications', dateLabel: item.dateLabel || new Date(item.publishedAt).toLocaleDateString('en-GB'), sortDate: item.publishedAt, content: item.content || item.body.split(/\n\s*\n/) })).sort((a, b) => b.sortDate.localeCompare(a.sortDate));
  db.events = content.filter(item => item.kind === 'event').map(item => ({ ...item, dateLabel: item.eventDate }));
  db.gallery = media.map(item => ({ img: item.url, cat: item.category, caption: item.title, alt: item.alt }));
  return { db, content, downloads: ['/downloads', '/parents'].includes(url.pathname) ? await platform.publicDownloads() : [], path: url.pathname, query: url.searchParams, lang: url.pathname.startsWith('/sw') ? 'sw' : 'en', baseUrl: platform.config.baseUrl, config: platform.config, nonce };
}
async function createApplication(config = createConfig(), options = {}) {
  const store = await openStore(config);
  const files = new Files(config);
  const platform = new Platform(store, config, files);
  try { await platform.init(); } catch (error) { await store.close(); throw error; }
  const auth = new Auth(store, config);
  const jobs = startJobs(platform);
  if (options.jobs !== false) await jobs.run();
  else jobs.stop();
  const server = http.createServer(async (req, res) => {
    let url;
    const nonce = crypto.randomBytes(18).toString('base64');
    headers(req, res, config, nonce);
    try {
      url = new URL(req.url, 'http://internal');
      const route = url.pathname;
      if (route.startsWith('/api/')) {
        res.setHeader('Cache-Control', 'no-store');
        if (config.publicPreview) return json(res, 404, { ok: false, code: 'PREVIEW_READ_ONLY', message: 'Public preview does not accept submissions or provide staff access.' });
        return await handleApi(req, res, url, platform, auth);
      }
      if (route === '/admin' || route.startsWith('/admin/')) {
        if (req.method !== 'GET') throw new L.HttpError(410, 'The legacy CMS is retired. Use the secure portal.');
        return redirect(res, '/portal');
      }
      if (route === '/portal' || route.startsWith('/portal/')) {
        if (req.method !== 'GET') throw new L.HttpError(405, 'Use the authenticated API.');
        if (config.publicPreview) return send(res, 404, '<!doctype html><html lang="en"><meta name="robots" content="noindex,nofollow"><title>Unavailable in preview</title><body><h1>Staff access is unavailable in this public preview.</h1><a href="/">Return to the school website</a></body></html>', { 'X-Robots-Tag': 'noindex, nofollow' });
        const actor = await auth.actor(req, res, true);
        return send(res, 200, portal({ section: route.split('/')[2] || 'dashboard', nonce }, actor, config), { 'Cache-Control': 'private, no-store, max-age=0', 'X-Robots-Tag': 'noindex, nofollow', 'Referrer-Policy': 'no-referrer' });
      }
      if (!['GET', 'HEAD'].includes(req.method)) throw new L.HttpError(405, 'Method not allowed.');
      if (route === '/healthz' || route === '/readyz') {
        try { await store.health(); return json(res, 200, { ok: true }); }
        catch { return json(res, 503, { ok: false }); }
      }
      if (route === '/favicon.ico' || ['/img/', '/css/', '/js/', '/fonts/'].some(prefix => route.startsWith(prefix))) {
        if (L.serveStatic(req, res, route === '/favicon.ico' ? '/img/favicon-32.png' : route, PUBLIC_DIR)) return;
        throw new L.HttpError(404, 'Asset not found.');
      }
      if (route === '/service-worker.js') {
        res.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-cache', 'Service-Worker-Allowed': '/' });
        return res.end(await fs.readFile(path.join(PUBLIC_DIR, 'service-worker.js')));
      }
      if (route === '/offline') return send(res, 200, await fs.readFile(path.join(PUBLIC_DIR, 'offline.html'), 'utf8'), { 'Cache-Control': 'public, max-age=3600' });
      if (route === '/site.webmanifest') {
        res.writeHead(200, { 'Content-Type': 'application/manifest+json', 'Cache-Control': 'no-cache' });
        const shortcuts = [{ name: 'Staff workspace', url: '/portal' }, { name: 'Admissions', url: '/admissions' }, { name: 'Kiswahili', url: '/sw' }].filter(item => !config.publicPreview || item.url !== '/portal');
        return res.end(JSON.stringify({ id: '/', name: 'Alpha Adventist Digital School', short_name: 'Alpha School', lang: 'en', start_url: '/', scope: '/', display: 'standalone', background_color: '#f8f6f0', theme_color: '#0A1E59', description: 'Alpha Adventist school information and secure, online staff workspace.', icons: [{ src: '/img/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' }, { src: '/img/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' }], shortcuts }));
      }
      if (route === '/robots.txt') {
        res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-cache' });
        return res.end(config.environment === 'staging' ? 'User-agent: *\nDisallow: /\n' : `User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /portal\nDisallow: /api/\nDisallow: /offline\n\nSitemap: ${config.baseUrl}/sitemap.xml\n`);
      }
      if (route === '/sitemap.xml') {
        const content = await platform.publicContent();
        const paths = [...Object.keys(pages), ...SW_PAGES, '/news', ...content.filter(item => item.kind === 'news').map(item => '/news/' + item.slug), ...content.filter(item => ['page', 'faq', 'vacancy', 'event'].includes(item.kind)).map(item => '/pages/' + item.slug)];
        res.writeHead(200, { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'no-cache' });
        return res.end(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${[...new Set(paths)].map(item => `<url><loc>${L.esc(config.baseUrl + item)}</loc></url>`).join('')}</urlset>`);
      }
      if (/^\/downloads\/DOC-[a-f0-9-]+$/.test(route)) return sendFile(res, await platform.publicDownload(route.slice('/downloads/'.length), { ip: requestIp(req, config), userAgent: req.headers['user-agent'] }));
      if (/^\/media\/MED-[a-f0-9-]+$/.test(route)) return sendFile(res, await platform.publicMediaFile(route.slice('/media/'.length)), true);
      if (route !== '/' && route.endsWith('/')) return redirect(res, route.replace(/\/+$/, '') + url.search, 308);
      const ctx = await publicContext(platform, url, nonce);
      let output;
      if (route === '/sw') output = pages['/'].render(ctx);
      else if (route === '/sw/admissions') output = require('./src/pages/swahili').admissions(ctx);
      else if (route === '/sw/contact') output = require('./src/pages/swahili').contact(ctx);
      else if (route === '/news') output = require('./src/pages/news').list(ctx);
      else if (/^\/news\/[^/]+$/.test(route)) output = require('./src/pages/news').article(ctx, decodeURIComponent(route.slice(6)));
      else if (/^\/pages\/[^/]+$/.test(route)) {
        const item = ctx.content.find(record => record.slug === route.slice(7) && ['page', 'faq', 'vacancy', 'event'].includes(record.kind));
        if (item) output = { body: `${require('./src/pages/about').phero(ctx, item.kind, item.title, item.excerpt, 'campus')}<section class="sec"><article class="container article">${L.paragraphs(item.content || item.body.split(/\n\s*\n/))}</article></section>`, meta: { title: item.seoTitle || item.title + ' — Alpha Adventist', desc: item.seoDescription || item.excerpt } };
      } else if (pages[route]) output = pages[route].render(ctx);
      if (!output) return send(res, 404, X.page(ctx, '<section class="sec"><div class="container text-center"><span class="kicker">404</span><h1>This page isn’t here.</h1><p>Let’s return to familiar ground.</p><a class="btn btn--primary" href="/">Back to school home</a></div></section>', { title: 'Page not found — Alpha Adventist' }));
      return send(res, 200, X.page(ctx, output.body, output.meta));
    } catch (error) {
      const status = error instanceof L.HttpError ? error.status : 500;
      if (status === 413 && !res.headersSent) res.setHeader('Connection', 'close');
      if (url?.pathname.startsWith('/api/') && status >= 400) {
        try { await store.run(tx => audit(tx, config, req.actor || { ip: requestIp(req, config) }, status === 403 ? 'api.access_denied' : 'api.request_failed', 'api', url.pathname.replace(/\/api\/downloads\/.*/, '/api/downloads/[redacted]'), status === 403 ? 'denied' : 'failure', { method: req.method, status })); }
        catch { console.error('[audit] recording failed; investigate database availability.'); }
      }
      if (status >= 500) console.error('[request] failed', error.name, error.code || '', safeErrorMessage(error), safeErrorLocation(error));
      const message = status < 500 ? error.message : error instanceof L.HttpError ? error.message : 'The service is temporarily unavailable. Please try again or contact the school.';
      if (!res.headersSent) {
        if (url?.pathname.startsWith('/api/')) return json(res, status, { ok: false, code: error.code || 'REQUEST_FAILED', message });
        return send(res, status, `<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Alpha School</title></head><body style="font-family:system-ui;padding:40px;max-width:700px;margin:auto"><h1>${status === 404 ? 'Not found' : 'Please try again'}</h1><p>${L.esc(message)}</p><a href="/">Return to school home</a></body></html>`, { 'Cache-Control': 'no-store' });
      }
      res.end();
    }
  });
  server.requestTimeout = 40000;
  server.headersTimeout = 10000;
  server.keepAliveTimeout = 5000;
  return {
    server, platform, auth, store, config,
    async close() {
      await jobs.stop();
      if (server.listening) await new Promise(resolve => { server.close(resolve); server.closeIdleConnections?.(); });
      await store.close();
    }
  };
}
if (require.main === module) {
  createApplication().then(app => {
    app.server.listen(app.config.port, '0.0.0.0', () => console.log(`[alpha] digital school platform listening on :${app.config.port} (${app.store.kind})`));
    let closing = false;
    for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, async () => { if (closing) return; closing = true; await app.close(); });
  }).catch(error => { console.error('[startup]', error.message.replace(/mongodb(?:\+srv)?:\/\/[^\s]+/gi, '[redacted database URI]')); process.exitCode = 1; });
}
module.exports = { createApplication };
