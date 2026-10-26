'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { ACCOUNTS, CLASS_ID, STUDENT_REF, requireApproval } = require('../scripts/seed-sample-users');
const { ROLE_PERMISSIONS } = require('../src/platform/access');

test('sample accounts cover every role exactly once with valid usernames', () => {
  const roleCounts = {};
  for (const account of ACCOUNTS) {
    assert.match(account.username, /^[a-z0-9][a-z0-9._-]{2,63}$/, `${account.username} must satisfy the account username rule`);
    assert.ok(account.name && account.name.length <= 120);
    assert.equal(account.roles.length, 1, 'each sample account holds one role so permissions stay obvious');
    const role = account.roles[0];
    assert.ok(Object.hasOwn(ROLE_PERMISSIONS, role), `${role} is not a known role`);
    roleCounts[role] = (roleCounts[role] || 0) + 1;
  }
  assert.deepEqual(Object.keys(roleCounts).sort(), Object.keys(ROLE_PERMISSIONS).sort(), 'one sample account per role, no extras and no omissions');
  assert.ok(Object.values(roleCounts).every(count => count === 1), 'no role is seeded twice');
});

test('sample accounts are unmistakably synthetic and never collide with real-looking names', () => {
  for (const account of ACCOUNTS) {
    assert.match(account.username, /^sample\./, 'usernames stay inside the sample.* namespace');
    assert.match(account.name, /^Sample /, 'display names are obviously placeholders');
  }
  assert.match(CLASS_ID, /^[a-z0-9][a-z0-9._-]{1,39}$/, 'class id satisfies the classroom rule');
  assert.match(STUDENT_REF, /^[A-Z0-9-]{3,80}$/, 'learner reference satisfies the profile rule');
});

test('seeding refuses production and the read-only preview without explicit approval', () => {
  assert.throws(() => requireApproval({ PUBLIC_PREVIEW: 'true' }), /public preview/i);
  assert.throws(() => requireApproval({ NODE_ENV: 'production' }), /Refusing to seed a production database/);
  assert.throws(() => requireApproval({ NODE_ENV: 'production', SEED_SAMPLE_USERS_APPROVED: 'true' }), /decision reference|SEED_SAMPLE_USERS_REFERENCE/);
  assert.doesNotThrow(() => requireApproval({ NODE_ENV: 'development' }));
  assert.doesNotThrow(() => requireApproval({}));
  assert.doesNotThrow(() => requireApproval({ NODE_ENV: 'production', SEED_SAMPLE_USERS_APPROVED: 'true', SEED_SAMPLE_USERS_REFERENCE: 'APPROVED-REF-01' }));
});
