'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { isPrivatePasswordFile } = require('../scripts/bootstrap');

test('bootstrap password-file privacy is checked using platform permissions', t => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alpha-bootstrap-permissions-'));
  const file = path.join(dir, 'password.txt');
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  fs.writeFileSync(file, 'synthetic-password', { mode: 0o600 });

  if (process.platform === 'win32') {
    const who = execFileSync('whoami.exe', ['/user', '/fo', 'csv', '/nh'], { encoding: 'utf8' });
    const userSid = who.match(/S-\d-\d+(?:-\d+)+/);
    assert.ok(userSid, 'could not resolve current Windows user SID');
    execFileSync('icacls.exe', [file, '/inheritance:r'], { stdio: 'ignore' });
    execFileSync('icacls.exe', [file, '/grant:r', `*${userSid[0]}:(R,W)`, '*S-1-5-18:(F)', '*S-1-5-32-544:(F)'], { stdio: 'ignore' });
    assert.equal(isPrivatePasswordFile(file), true);
    execFileSync('icacls.exe', [file, '/grant', '*S-1-1-0:(R)'], { stdio: 'ignore' });
    assert.equal(isPrivatePasswordFile(file), false);
    return;
  }

  fs.chmodSync(file, 0o600);
  assert.equal(isPrivatePasswordFile(file), true);
  fs.chmodSync(file, 0o644);
  assert.equal(isPrivatePasswordFile(file), false);
});
