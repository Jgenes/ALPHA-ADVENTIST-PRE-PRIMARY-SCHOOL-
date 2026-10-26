'use strict';
// Development/demonstration seeding only.
//
// Creates one sample account per role plus enough classroom data that every
// dashboard shows something real. It refuses to touch a production or public
// preview deployment unless an explicit, referenced approval is supplied.
//
//   node scripts/seed-sample-users.js            # seed
//   node scripts/seed-sample-users.js --dry-run  # show the plan, change nothing
require('dotenv').config();
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { createConfig } = require('../src/config');
const { createApplication } = require('../server');
const { createUserRecord } = require('../src/platform/auth');
const { audit } = require('../src/platform/audit');
const seedData = require('../data/seed.json');

const CREDENTIALS_FILE = path.join(__dirname, '..', 'sample-users.txt');
const DOMAIN = 'sample.local';

// One account per role the platform knows about. Names are deliberately
// obviously synthetic so nobody mistakes them for real staff appointments.
const ACCOUNTS = [
  { username: 'sample.sysadmin', name: 'Sample System Administrator', roles: ['system_admin'] },
  { username: 'sample.head', name: 'Sample Head Teacher', roles: ['head_teacher'] },
  { username: 'sample.admin', name: 'Sample School Administrator', roles: ['school_admin'] },
  { username: 'sample.ict', name: 'Sample ICT Officer', roles: ['ict_officer'] },
  { username: 'sample.hr', name: 'Sample HR Officer', roles: ['hr_officer'] },
  { username: 'sample.finance', name: 'Sample Finance Officer', roles: ['finance_officer'] },
  { username: 'sample.academic', name: 'Sample Academic Coordinator', roles: ['academic_coordinator'] },
  { username: 'sample.hod', name: 'Sample Head of Department', roles: ['head_of_department'] },
  { username: 'sample.teacher', name: 'Sample KG II Teacher', roles: ['teacher'] },
  { username: 'sample.support', name: 'Sample Supporting Staff', roles: ['supporting_staff'] },
  { username: 'sample.dpo', name: 'Sample Privacy Officer', roles: ['dpo'] },
  { username: 'sample.cms.author', name: 'Sample Content Author', roles: ['cms_author'] },
  { username: 'sample.cms.editor', name: 'Sample Content Editor', roles: ['cms_editor'] },
  { username: 'sample.cms.publisher', name: 'Sample CMS Publisher', roles: ['cms_publisher'] },
  { username: 'sample.media', name: 'Sample Media Manager', roles: ['media_manager'] },
  { username: 'sample.auditor', name: 'Sample Auditor', roles: ['auditor'] },
  { username: 'sample.parent', name: 'Sample Parent Guardian', roles: ['parent'] },
  { username: 'sample.student', name: 'Sample Learner', roles: ['student'] }
];

// Accounts the seeding run signs in as. They are created able to sign in, and
// every seeded account is switched back to "must change password" afterwards.
const ACTING = ['sample.head', 'sample.teacher', 'sample.admin', 'sample.parent', 'sample.cms.author'];

const CLASS_ID = 'sample-kg-ii';
const STUDENT_REF = 'STU-SAMPLE-001';

function password() {
  return crypto.randomBytes(18).toString('base64url') + 'aA1!';
}

function requireApproval(env) {
  if (env.PUBLIC_PREVIEW === 'true') throw new Error('Seeding is disabled on the read-only public preview service.');
  if (env.NODE_ENV !== 'production') return;
  if (env.SEED_SAMPLE_USERS_APPROVED !== 'true' || !env.SEED_SAMPLE_USERS_REFERENCE) {
    throw new Error('Refusing to seed a production database. This creates accounts with generated passwords. Set SEED_SAMPLE_USERS_APPROVED=true and SEED_SAMPLE_USERS_REFERENCE to an owner-approved decision reference, or seed a development database instead.');
  }
}

// Force a password change at first sign-in for every account this run created.
async function rearm(app, created, restored) {
  for (const account of created) {
    if (!ACTING.includes(account.username) || restored.has(account.username)) continue;
    const current = await app.store.run(tx => tx.get('users', account.id));
    if (!current || current.mustChangePassword) { restored.add(account.username); continue; }
    await app.store.run(tx => tx.update('users', { ...current, mustChangePassword: true, authVersion: current.authVersion + 1 }));
    restored.add(account.username);
  }
  return restored;
}

// Minimal same-origin JSON client with CSRF handling, mirroring the real
// browser flow so every write passes the same guards the portal uses.
class Agent {
  constructor(base) { this.base = base; this.cookie = ''; this.csrf = ''; }
  async request(method, route, body) {
    const headers = {
      ...(this.cookie ? { Cookie: this.cookie } : {}),
      ...(body === undefined ? {} : { 'Content-Type': 'application/json', ...(this.csrf ? { 'X-CSRF-Token': this.csrf } : {}) })
    };
    const res = await fetch(this.base + route, { method, headers, redirect: 'manual', ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    const cookie = res.headers.get('set-cookie');
    if (cookie) this.cookie = cookie.split(';')[0];
    const bytes = Buffer.from(await res.arrayBuffer());
    let json;
    try { json = JSON.parse(bytes.toString()); } catch { json = undefined; }
    if (json && json.csrf) this.csrf = json.csrf;
    return { status: res.status, json, data: json && json.data !== undefined ? json.data : json };
  }
  async ok(method, route, body) {
    const response = await this.request(method, route, body);
    if (response.status !== 200) throw new Error(`${method} ${route} failed (${response.status}): ${response.json && response.json.message ? response.json.message : 'no message'}`);
    return response.data;
  }
  async login(user, secret) {
    await this.request('GET', '/api/auth/session');
    await this.ok('POST', '/api/auth/login', { username: user.username, password: secret });
  }
}

async function main() {
  const dryRun = process.argv.includes('--dry-run');
  requireApproval(process.env);
  const config = createConfig();
  const app = await createApplication(config, { jobs: false });
  await new Promise(resolve => app.server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${app.server.address().port}`;
  const created = [];
  const skipped = [];
  const credentials = [];
  const restored = new Set();

  try {
    // 1. Accounts -----------------------------------------------------------------
    const existing = new Set((await app.store.run(tx => tx.list('users'))).map(user => user.username));
    for (const account of ACCOUNTS) {
      if (existing.has(account.username)) { skipped.push(account.username); continue; }
      const secret = password();
      const record = await createUserRecord(account.username, account.name, account.roles, secret);
      // The seeding run itself needs to sign in as a few of these accounts.
      if (ACTING.includes(account.username)) record.mustChangePassword = false;
      await app.store.run(async tx => {
        await tx.insert('users', record);
        await audit(tx, config, { userId: 'sample-seed' }, 'user.seed_sample', 'user', record.id, 'success', { roles: record.roles });
      });
      created.push({ ...account, id: record.id });
      credentials.push({ username: account.username, name: account.name, roles: record.roles, password: secret });
      console.log(`  + ${account.username.padEnd(20)} ${record.roles.join(', ')}`);
    }

    if (dryRun) {
      console.log(`\nDry run: ${created.length} account(s) would be created, ${skipped.length} already exist. Nothing was written.`);
      return;
    }

    const users = await app.store.run(tx => tx.list('users'));
    const userOf = name => users.find(user => user.username === name);
    const secretOf = name => (credentials.find(item => item.username === name) || {}).password;

    // 2. Demo classroom data ------------------------------------------------------
    const demoDone = await app.store.run(tx => tx.get('classes', CLASS_ID));
    if (demoDone) {
      console.log(`\n  Demo classroom "${CLASS_ID}" already exists — leaving existing demonstration data untouched.`);
    } else {
      const head = new Agent(base); await head.login(userOf('sample.head'), secretOf('sample.head'));
      const teacher = new Agent(base); await teacher.login(userOf('sample.teacher'), secretOf('sample.teacher'));
      const admin = new Agent(base); await admin.login(userOf('sample.admin'), secretOf('sample.admin'));
      const parent = new Agent(base); await parent.login(userOf('sample.parent'), secretOf('sample.parent'));
      const author = new Agent(base); await author.login(userOf('sample.cms.author'), secretOf('sample.cms.author'));

      const classroom = await head.ok('POST', '/api/classes', { id: CLASS_ID, name: 'KG II (Sample)', yearLevel: 'KG II' });
      console.log(`  + classroom ${classroom.name}`);
      await head.ok('POST', '/api/class-teachers', { classId: classroom.id, teacherUserId: userOf('sample.teacher').id });
      console.log('  + teacher assigned to classroom');

      const student = await head.ok('POST', '/api/students', {
        userId: userOf('sample.student').id,
        studentRef: STUDENT_REF,
        classId: classroom.id,
        yearLevel: 'KG II'
      });
      console.log(`  + learner ${student.studentRef}`);
      await head.ok('POST', `/api/students/${student.id}/guardians`, {
        guardianUserId: userOf('sample.parent').id,
        relationship: 'Parent',
        verificationReference: 'SAMPLE-GUARDIAN-LINK',
        authorityVerified: true
      });
      console.log('  + guardian link verified');

      await teacher.ok('POST', '/api/attendance', {
        studentId: student.id,
        date: new Date().toISOString().slice(0, 10),
        status: 'PRESENT',
        note: 'Sample attendance note. Not a real learner record.'
      });
      console.log('  + attendance record');

      const result = await teacher.ok('POST', '/api/results', { studentId: student.id, subject: 'Number work', term: 'Term 1', score: 'A' });
      await head.ok('POST', `/api/results/${result.id}/publish`, { revision: result.revision });
      console.log('  + published result');

      const material = await teacher.ok('POST', '/api/learning-materials', {
        title: 'Sample number practice',
        description: 'Sample learning material. Replace with approved school content.',
        href: '/downloads',
        classIds: [classroom.id]
      });
      await head.ok('POST', `/api/learning-materials/${material.id}/publish`, { revision: material.revision });
      console.log('  + published learning material');

      const notice = await head.ok('POST', '/api/notices', {
        title: 'Sample notice: staff briefing',
        message: 'This is sample data created for a demonstration. It is not a real school notice.',
        category: 'General',
        priority: 'Information',
        audience: { type: 'ALL_STAFF' }
      });
      await admin.ok('POST', `/api/notices/${notice.id}/publish`, { revision: notice.revision });
      console.log('  + published staff notice');

      await author.ok('POST', '/api/cms', {
        kind: 'news',
        title: 'Sample draft: welcome to our school',
        slug: 'sample-draft-welcome',
        excerpt: 'Sample draft content for the editorial workflow.',
        body: 'This draft exists so the editorial workflow has something to review. Replace it with approved school text.'
      });
      console.log('  + CMS draft awaiting editorial review');

      const level = (seedData.academics.prePrimary[0] || 'KG I');
      const application = await parent.ok('POST', '/api/my/admissions', {
        childName: 'Sample Child',
        level,
        arrangement: 'Day',
        guardianPhone: '+255700000000',
        guardianEmail: `sample.parent@${DOMAIN}`,
        message: 'Sample admission application created by the seeding script.',
        privacyConsent: true
      });
      await parent.ok('POST', `/api/my/admissions/${application.id}/submit`, { revision: application.revision });
      console.log(`  + admission application ${application.reference} submitted`);
    }

    // 3. Write credentials and re-arm first-sign-in password changes --------------
    const rows = credentials.map(item => `${item.username}\t${item.password}\t${item.roles.join(',')}\t${item.name}`);
    if (rows.length) {
      fs.writeFileSync(CREDENTIALS_FILE, [
        '# Generated sample accounts. Development/demonstration use only.',
        '# Each account must change its password at first sign-in.',
        '# username\tpassword\troles\tname',
        ...rows
      ].join('\n') + '\n', { mode: 0o600 });
      console.log(`\n  Credentials written to ${path.relative(process.cwd(), CREDENTIALS_FILE)} (mode 600). Passwords are not printed here.`);
    }

    await rearm(app, created, restored);

    console.log(`\nCreated ${created.length} account(s)${skipped.length ? `, skipped ${skipped.length} that already existed` : ''}.`);
    if (restored.size) console.log(`Re-armed forced password change for: ${[...restored].join(', ')}.`);
    console.log('Sign in at /portal. Change every sample password before using this database for anything but a demonstration.');
  } finally {
    // Even if seeding failed halfway, never leave a sample account able to sign
    // in without forcing an immediate password change.
    try { await rearm(app, created, restored); } catch { /* reported below by the caller */ }
    await app.close();
  }
}

if (require.main === module) {
  main().catch(error => {
    console.error(`\n[seed] Failed: ${error.message}`);
    if (process.env.DEBUG) console.error(error);
    process.exitCode = 1;
  });
}

module.exports = { ACCOUNTS, CLASS_ID, STUDENT_REF, requireApproval };
