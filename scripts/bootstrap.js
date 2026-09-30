'use strict';
require('dotenv').config();
const fs = require('node:fs');
const { parseArgs } = require('node:util');
const { createApplication } = require('../server');
const { createUserRecord } = require('../src/platform/auth');
const { audit } = require('../src/platform/audit');
async function bootstrapUser(store, config, input) {
  if (input.approved !== true || !input.approvalReference || !['system_admin', 'head_teacher'].includes(input.role)) throw new Error('An owner-approved initial system_admin or head_teacher appointment is required.');
  const record = await createUserRecord(input.username, input.name, [input.role], input.password);
  return store.run(async tx => {
    if ((await tx.list('users')).some(user => user.roles.includes(input.role))) throw new Error('This initial authority already exists. Use the governed account process, not bootstrap.');
    await tx.insert('users', record);
    await audit(tx, config, { userId: 'owner-approved-bootstrap' }, 'user.bootstrap', 'user', record.id, 'success', { role: input.role, approvalReference: String(input.approvalReference).slice(0, 120) });
    return { id: record.id, username: record.username, role: input.role };
  });
}
async function main() {
  const { values } = parseArgs({ options: { username: { type: 'string' }, name: { type: 'string' }, role: { type: 'string' } }, strict: true });
  if (process.env.BOOTSTRAP_APPROVED !== 'true' || !process.env.BOOTSTRAP_APPROVAL_REFERENCE) throw new Error('Set the approved appointment flag and its private decision reference.');
  const file = process.env.BOOTSTRAP_PASSWORD_FILE;
  if (!file || (fs.statSync(file).mode & 0o077) !== 0) throw new Error('Provide BOOTSTRAP_PASSWORD_FILE with permissions 600 or stricter. Do not put a password in command arguments.');
  const password = fs.readFileSync(file, 'utf8').replace(/\r?\n$/, '');
  const app = await createApplication(undefined, { jobs: false });
  try {
    const result = await bootstrapUser(app.store, app.config, { ...values, approved: true, approvalReference: process.env.BOOTSTRAP_APPROVAL_REFERENCE, password });
    console.log(`Initial ${result.role} account created. First sign-in requires a password change and MFA. Remove the temporary password file after secure handover. No password is printed.`);
  } finally { await app.close(); }
}
if (require.main === module) main().catch(error => { console.error('[bootstrap] Failed. Review approval, protected password file, role existence and database connection.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { bootstrapUser };
