'use strict';
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { createConfig } = require('../src/config');
const { createApplication } = require('../server');
const { createUserRecord } = require('../src/platform/auth');
class Agent {
  constructor(base) { this.base = base; this.cookie = ''; this.csrf = ''; }
  async request(method, route, body, options = {}) {
    const headers = { ...(this.cookie ? { Cookie: this.cookie } : {}), ...(body === undefined ? {} : { 'Content-Type': 'application/json', ...(options.csrf === false ? {} : { 'X-CSRF-Token': this.csrf }) }), ...options.headers };
    const res = await fetch(this.base + route, { method, headers, redirect: 'manual', ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    const cookie = res.headers.get('set-cookie');
    if (cookie) this.cookie = cookie.split(';')[0];
    const bytes = Buffer.from(await res.arrayBuffer());
    let json;
    try { json = JSON.parse(bytes.toString()); } catch {}
    if (json?.csrf) this.csrf = json.csrf;
    return { status: res.status, headers: res.headers, bytes, text: bytes.toString(), json, data: json?.data ?? json };
  }
  async ok(method, route, body, options) {
    const response = await this.request(method, route, body, options);
    assert.equal(response.status, 200, `${method} ${route}: ${response.json?.message || response.status}`);
    return response.data;
  }
  async login(user, password) {
    await this.ok('GET', '/api/auth/session');
    return this.ok('POST', '/api/auth/login', { username: user.username, password });
  }
}
async function fixture() {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-test-'));
  const config = createConfig({ NODE_ENV: 'test', DATA_DIR: dir, BASE_URL: 'https://school.example.test', PORT: '0' });
  const app = await createApplication(config, { jobs: false });
  await new Promise(resolve => app.server.listen(0, '0.0.0.0', resolve));
  const base = `http://127.0.0.1:${app.server.address().port}`;
  const password = crypto.randomBytes(24).toString('base64url') + 'aA1!';
  const users = {}, agents = {};
  for (const [key, role] of Object.entries({ tech: 'system_admin', head: 'head_teacher', office: 'school_admin', hr: 'hr_officer', finance: 'finance_officer', hod: 'head_of_department', teacher: 'teacher', other: 'teacher', author: 'cms_author', otherAuthor: 'cms_author', editor: 'cms_editor', publisher: 'cms_publisher', dpo: 'dpo', media: 'media_manager', auditor: 'auditor' })) {
    const record = await createUserRecord('fixture.' + key.toLowerCase(), 'Synthetic ' + key, [role], password);
    record.mustChangePassword = false;
    users[key] = await app.store.run(tx => tx.insert('users', record));
    agents[key] = new Agent(base);
    await agents[key].login(users[key], password);
  }
  return { app, dir, config, base, users, agents, password, anonymous: new Agent(base), async close() { await this.app.close(); await fs.rm(dir, { recursive: true, force: true }); } };
}
function textFile(content = 'Synthetic controlled document. No real personal data.') { return { name: 'controlled-document.txt', content: Buffer.from(content).toString('base64') }; }
function nextMonday() {
  const value = new Date(); value.setUTCDate(value.getUTCDate() + 7 + (8 - value.getUTCDay()) % 7); return value.toISOString().slice(0, 10);
}
function addDays(value, days) { return new Date(Date.parse(value + 'T00:00:00Z') + days * 86400000).toISOString().slice(0, 10); }
async function approve(agent, flowId, decision = 'APPROVE', comment = 'Synthetic test decision and attestation.') {
  const queue = await agent.ok('GET', '/api/approvals');
  const flow = queue.find(item => item.id === flowId);
  assert.ok(flow, 'Expected an assigned approval');
  return agent.ok('POST', `/api/approvals/${flow.id}/decision`, { revision: flow.revision, decision, comment });
}
module.exports = { Agent, fixture, textFile, nextMonday, addDays, approve };
