'use strict';
require('dotenv').config();
const fs = require('node:fs');
const { parseArgs } = require('node:util');
const { execFileSync } = require('node:child_process');
const { createApplication } = require('../server');
const { createUserRecord } = require('../src/platform/auth');
const { audit } = require('../src/platform/audit');
function isPrivatePasswordFile(file) {
  const stat = fs.statSync(file, { throwIfNoEntry: false });
  if (!stat || !stat.isFile()) return false;
  if (process.platform !== 'win32') return (stat.mode & 0o077) === 0;
  const escapedPath = file.replace(/'/g, "''");
  const script = `$ErrorActionPreference = 'Stop'; $acl = Get-Acl -LiteralPath '${escapedPath}'; $currentSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value; $allowedSids = @($currentSid, 'S-1-5-18', 'S-1-5-32-544'); $unsafe = $false; $hasCurrent = $false; foreach ($rule in $acl.Access) { if ($rule.AccessControlType -ne 'Allow') { continue }; try { $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { $unsafe = $true; break }; if ($sid -eq $currentSid) { $hasCurrent = $true }; if ($rule.IsInherited -or $allowedSids -notcontains $sid) { $unsafe = $true; break } }; if (-not $unsafe -and $hasCurrent) { 'private' } else { 'insecure' }`;
  const result = execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], { encoding: 'utf8', windowsHide: true });
  return result.trim() === 'private';
}
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
  if (!file || !isPrivatePasswordFile(file)) throw new Error('Provide BOOTSTRAP_PASSWORD_FILE readable only by the current user (POSIX permissions 600 or stricter; on Windows use a protected NTFS ACL). Do not put a password in command arguments.');
  const password = fs.readFileSync(file, 'utf8').replace(/\r?\n$/, '');
  const app = await createApplication(undefined, { jobs: false });
  try {
    const result = await bootstrapUser(app.store, app.config, { ...values, approved: true, approvalReference: process.env.BOOTSTRAP_APPROVAL_REFERENCE, password });
    console.log(`Initial ${result.role} account created. First sign-in requires a password change. Remove the temporary password file after secure handover. No password is printed.`);
  } finally { await app.close(); }
}
if (require.main === module) main().catch(error => { console.error('[bootstrap] Failed. Review approval, protected password file, role existence and database connection.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { bootstrapUser, isPrivatePasswordFile };
