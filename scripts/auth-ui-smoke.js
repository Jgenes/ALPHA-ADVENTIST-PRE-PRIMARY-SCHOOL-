'use strict';
// Synthetic regression: HTTPS preview inside a genuinely cross-site iframe.
// The local proxy only adds the test parent to frame-ancestors; all auth routes,
// cookies, CSRF validation and frontend code come from the real application.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const https = require('node:https');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium, expect } = require('@playwright/test');
const { fixture } = require('../test/helpers');
async function main() {
  const f = await fixture();
  let browser, proxy;
  try {
    f.config.environment = 'development';
    const keyPath = path.join(f.dir, 'test-tls.key'), certPath = path.join(f.dir, 'test-tls.crt');
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', keyPath, '-out', certPath, '-days', '1', '-subj', '/CN=localhost'], { stdio: 'pipe' });
    let parentOrigin, childOrigin;
    proxy = https.createServer({ key: await fs.readFile(keyPath), cert: await fs.readFile(certPath) }, (req, res) => {
      if (req.headers.host.startsWith('auth-parent.example.test')) {
        res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' });
        return res.end(`<!doctype html><html lang="en"><title>Local preview frame regression</title><iframe name="school-preview" title="School preview" src="${childOrigin}/portal" style="width:100%;height:900px;border:0"></iframe></html>`);
      }
      const forwarded = http.request({ hostname: '127.0.0.1', port: f.app.server.address().port, path: req.url, method: req.method, headers: req.headers }, upstream => {
        const headers = { ...upstream.headers };
        if (headers['content-security-policy']) headers['content-security-policy'] = headers['content-security-policy'].replace(/frame-ancestors [^;]+/, value => value + ' ' + parentOrigin);
        res.writeHead(upstream.statusCode, headers); upstream.pipe(res);
      });
      forwarded.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end(); });
      req.pipe(forwarded);
    });
    await new Promise(resolve => proxy.listen(0, '0.0.0.0', resolve));
    const port = proxy.address().port;
    parentOrigin = 'https://auth-parent.example.test:' + port;
    childOrigin = 'https://3000-login-fixture.e2b.app:' + port;
    browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}), args: ['--no-sandbox', '--disable-dev-shm-usage', '--no-proxy-server', '--host-resolver-rules=MAP auth-parent.example.test 127.0.0.1, MAP 3000-login-fixture.e2b.app 127.0.0.1'] });
    const errors = [];
    async function framedPage(context) {
      const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
      await page.goto(parentOrigin, { waitUntil: 'networkidle' });
      const frame = page.frame({ name: 'school-preview' });
      await expect(frame.locator('#auth-form')).toBeVisible();
      await expect(frame.locator('[name="mfa_code"]')).toHaveCount(0);
      await expect(frame.locator('[data-framed-login]')).toBeVisible();
      return { page, frame };
    }
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const { page, frame } = await framedPage(context);
    await frame.locator('[name="username"]').fill(f.users.teacher.username);
    await frame.locator('[name="password"]').fill(f.password);
    // An old form/server restart must not require retyping credentials.
    await f.app.store.run(async tx => { for (const session of await tx.list('sessions')) if (!session.userId) await tx.update('sessions', { ...session, expiresAt: Date.now() - 1 }); });
    let attempts = 0;
    await page.route('**/api/auth/login', async route => {
      attempts++;
      if (attempts === 1) return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'CSRF_FAILED', message: 'Synthetic rotation race' }) });
      return route.continue();
    });
    await frame.getByRole('button', { name: 'Sign in to workspace' }).click();
    await expect(frame.locator('#workspace h1')).toHaveText('Overview');
    assert.equal(attempts, 2, 'Exactly one bounded login retry');
    const cookies = await context.cookies();
    const cookie = cookies.find(item => item.name === 'alpha_sid' && item.domain === '3000-login-fixture.e2b.app');
    assert.ok(cookie); assert.equal(cookie.secure, true); assert.equal(cookie.httpOnly, true); assert.equal(cookie.sameSite, 'None'); assert.ok(cookie.partitionKey, 'Embedded session is partitioned to the parent site');
    assert.equal(await frame.evaluate(() => localStorage.length + sessionStorage.length), 0);

    // Privileged accounts use the same password-only sign-in.
    const secondContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const { frame: second } = await framedPage(secondContext);
    await second.locator('[name="username"]').fill(f.users.head.username);
    await second.locator('[name="password"]').fill(f.password);
    await second.getByRole('button', { name: 'Sign in to workspace' }).click();
    await expect(second.locator('#workspace h1')).toHaveText('Overview');
    await expect(second.locator('[name="mfa_code"]')).toHaveCount(0);

    const blockedContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const { page: blockedPage, frame: blocked } = await framedPage(blockedContext);
    let rejected = 0;
    await blockedPage.route('**/api/auth/login', route => { rejected++; return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'CSRF_FAILED', message: 'Synthetic blocked cookie' }) }); });
    await blocked.locator('[name="username"]').fill(f.users.teacher.username);
    await blocked.locator('[name="password"]').fill(f.password);
    await blocked.getByRole('button', { name: 'Sign in to workspace' }).click();
    await expect(blocked.locator('.p-form-status')).toContainText('Open the portal in a new tab');
    await expect(blocked.getByRole('button', { name: 'Sign in to workspace' })).toBeEnabled();
    await expect(blocked.locator('[name="username"]')).toHaveValue(f.users.teacher.username);
    assert.equal(rejected, 2, 'No unbounded retry loop');
    assert.deepEqual(errors, []);
    console.log('Auth browser regression passed: cross-site HTTPS iframe, partitioned session, expired-tab recovery, bounded CSRF retry, password-only privileged login and blocked-cookie fallback.');
  } finally {
    if (browser) await browser.close();
    if (proxy?.listening) await new Promise(resolve => { proxy.close(resolve); proxy.closeAllConnections(); });
    await f.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
