'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { createApplication } = require('../server');
const { createConfig } = require('../src/config');
const { createUserRecord, sessionCookieOptions } = require('../src/platform/auth');
const L = require('../src/lib');
const { Agent } = require('./helpers');

test('password sign-in, session recovery and cookie policy', async t => {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-auth-test-'));
  const config = createConfig({ NODE_ENV: 'test', DATA_DIR: dir, BASE_URL: 'https://school.example.test' });
  const app = await createApplication(config, { jobs: false });
  await new Promise(resolve => app.server.listen(0, '0.0.0.0', resolve));
  t.after(async () => { await app.close(); await fs.rm(dir, { recursive: true, force: true }); });
  const base = 'http://127.0.0.1:' + app.server.address().port;
  const password = crypto.randomBytes(24).toString('base64url') + '!Aa7';
  const teacher = await createUserRecord('login.teacher', 'Synthetic teacher', ['teacher'], password);
  teacher.mustChangePassword = false;
  const manager = await createUserRecord('login.manager', 'Synthetic manager', ['head_teacher'], password);
  manager.mustChangePassword = false;
  manager.mfaEnabled = true;
  manager.mfaSecretEnc = 'legacy-encrypted-mfa-data';
  await app.store.run(async tx => { await tx.insert('users', teacher); await tx.insert('users', manager); });

  await t.test('initial sign-in has username and password only, with a new-tab fallback', async () => {
    const result = await new Agent(base).request('GET', '/portal');
    assert.equal(result.status, 200);
    assert.ok(result.text.includes('name="username"'));
    assert.ok(result.text.includes('name="password"'));
    assert.ok(!result.text.includes('name="mfa_code"'));
    assert.ok(!result.text.includes('Authenticator code'));
    assert.ok(result.text.includes('open the portal in a new tab'));
    assert.ok(result.text.includes('/js/portal.js?v=5'));
    assert.match(result.headers.get('cache-control'), /no-store/);
  });
  await t.test('only the development preview uses secure partitioned cross-site cookies', () => {
    const req = { headers: { host: '3000-synthetic-preview.e2b.app' }, socket: {} };
    const preview = sessionCookieOptions(req, { ...config, environment: 'development' });
    assert.deepEqual(preview, { secure: true, sameSite: 'None', partitioned: true });
    assert.match(L.cookieHeader('alpha_sid', 'test-only', preview), /SameSite=None;.*Secure; Partitioned/);
    const production = sessionCookieOptions(req, { ...config, production: true, environment: 'production' });
    assert.deepEqual(production, { secure: true, sameSite: 'Lax', partitioned: false });
    for (const host of ['localhost:3000', 'school.example.test', '3000-preview.e2b.app.evil.example']) {
      const local = sessionCookieOptions({ ...req, headers: { host } }, { ...config, environment: 'development' });
      assert.equal(local.sameSite, 'Lax'); assert.equal(local.partitioned, false);
    }
    assert.throws(() => L.cookieHeader('sid', 'value', { sameSite: 'None', secure: false }));
    assert.throws(() => L.cookieHeader('sid', 'value', { partitioned: true, secure: false }));
  });
  await t.test('fresh CSRF sessions recover from expired tabs without accepting stale tokens', async () => {
    const agent = new Agent(base);
    await agent.ok('GET', '/api/auth/session');
    const oldToken = agent.csrf;
    const sessionId = L.sha256(agent.cookie.split('=')[1]);
    await app.store.run(async tx => { const session = await tx.get('sessions', sessionId); await tx.update('sessions', { ...session, expiresAt: Date.now() - 1 }); });
    const expired = await agent.request('POST', '/api/auth/login', { username: teacher.username, password });
    assert.equal(expired.status, 403); assert.equal(expired.json.code, 'CSRF_FAILED');
    await agent.ok('GET', '/api/auth/session');
    assert.notEqual(agent.csrf, oldToken);
    const signedIn = await agent.ok('POST', '/api/auth/login', { username: teacher.username, password });
    assert.equal(signedIn.user.id, teacher.id);
    const staleWrite = await agent.request('POST', '/api/leave', {}, { headers: { 'X-CSRF-Token': oldToken } });
    assert.equal(staleWrite.status, 403); assert.equal(staleWrite.json.code, 'CSRF_FAILED');
  });
  await t.test('privileged accounts sign in with a password and MFA endpoints are unavailable', async () => {
    const agent = new Agent(base);
    await agent.ok('GET', '/api/auth/session');
    const invalid = await agent.request('POST', '/api/auth/login', { username: manager.username, password: 'not-the-password' });
    assert.equal(invalid.status, 401); assert.equal(invalid.json.requiresMfaCode, undefined);
    const signedIn = await agent.ok('POST', '/api/auth/login', { username: manager.username, password });
    assert.equal(signedIn.user.id, manager.id);
    assert.equal((await agent.request('GET', '/api/dashboard')).status, 200);
    assert.equal((await agent.request('POST', '/api/auth/mfa/setup', {})).status, 404);
    assert.equal((await agent.request('POST', '/api/auth/mfa/confirm', { code: '123456' })).status, 404);
  });
  await t.test('first-time management accounts can continue after password change', async () => {
    const account = await createUserRecord('login.newmanager', 'Synthetic new manager', ['head_teacher'], password);
    account.mustChangePassword = false; // Isolate the post-password-change enrolment screen.
    await app.store.run(tx => tx.insert('users', account));
    const agent = new Agent(base); const result = await agent.login(account, password);
    assert.equal(result.requiresMfaCode, undefined);
    const page = await agent.request('GET', '/portal');
    assert.equal(page.status, 200);
    assert.ok(!page.text.includes('Google Authenticator'));
    assert.equal((await agent.request('GET', '/api/dashboard')).status, 200);
  });
});
