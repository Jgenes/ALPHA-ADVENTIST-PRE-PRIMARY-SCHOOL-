'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { createApplication } = require('../server');
const { createConfig } = require('../src/config');
const { COLLECTIONS } = require('../src/platform/store');
const { verifyAudit } = require('../src/platform/audit');
const { bootstrapUser } = require('../scripts/bootstrap');
const { legacyRecords, importLegacy } = require('../scripts/migrate-mongodb');
const { validateRestore } = require('../scripts/restore-mongodb');
const B = require('../scripts/backup-lib');
const { textFile } = require('./helpers');

test('operator safeguards and an actual isolated SQLite/file recovery rehearsal', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'alpha-recovery-test-'));
  const config = createConfig({ NODE_ENV: 'test', DATA_DIR: path.join(root, 'source'), BASE_URL: 'https://school.example.test' });
  let app = await createApplication(config, { jobs: false });
  let closed = false;
  t.after(async () => { if (!closed) await app.close(); await fs.rm(root, { recursive: true, force: true }); });
  let encryptedFile, backup, archivePath;
  const key = crypto.randomBytes(32);
  await t.test('bootstrap creates no default account and cannot repeat or grant unapproved authority', async () => {
    assert.equal((await app.store.run(tx => tx.list('users'))).length, 0);
    const input = { username: 'synthetic.operator', name: 'Synthetic operator', role: 'system_admin', password: crypto.randomBytes(24).toString('base64url') + 'aA1!', approved: true, approvalReference: 'SYNTHETIC-DECISION' };
    await assert.rejects(bootstrapUser(app.store, config, { ...input, approved: false }));
    await assert.rejects(bootstrapUser(app.store, config, { ...input, role: 'hr_officer' }));
    const result = await bootstrapUser(app.store, config, input);
    const user = await app.store.run(tx => tx.get('users', result.id));
    assert.equal(user.mustChangePassword, true); assert.equal(user.mfaEnabled, false); assert.deepEqual(user.roles, ['system_admin']);
    await assert.rejects(bootstrapUser(app.store, config, { ...input, username: 'another.operator' }), /already exists/);
  });
  await t.test('anonymous session creation is bounded per source without inventing a default account', async () => {
    const request = { headers: {}, socket: { remoteAddress: '192.0.2.77' } };
    for (let i = 0; i < 60; i++) await app.auth.actor(request, { setHeader() {} }, true);
    await assert.rejects(app.auth.actor(request, { setHeader() {} }, true), error => error.status === 429);
    assert.equal((await app.store.run(tx => tx.list('users'))).length, 1);
  });
  await t.test('encrypted archives include every referenced private file, not application keys', async () => {
    encryptedFile = await app.platform.files.prepare(textFile('RECOVERY SYNTHETIC CONTENT'));
    await app.store.run(tx => tx.insert('document_versions', { id: 'RECOVERY-DOC', file: encryptedFile, status: 'DRAFT', classification: 'CONFIDENTIAL', version: 1 }));
    const collections = await app.store.run(async tx => { const rows = []; for (const name of COLLECTIONS) rows.push({ name: 'adsp_' + name, documents: await tx.list(name), indexes: [], options: {} }); return rows; });
    const files = await B.captureFiles(config.dataDir, collections);
    assert.equal(files.length, 1);
    await app.close(); closed = true;
    const sqlite = (await fs.readFile(path.join(config.dataDir, 'adsp.sqlite'))).toString('base64');
    const snapshot = { version: 2, database: 'synthetic_source', exportedAt: new Date(), collections, files, sqlite };
    archivePath = path.join(root, 'archives', 'synthetic.ejson.enc');
    const result = await B.writeBackup(archivePath, snapshot, key);
    const ciphertext = await fs.readFile(archivePath);
    assert.equal(result.sha256, crypto.createHash('sha256').update(ciphertext).digest('hex'));
    assert.ok(!ciphertext.includes(Buffer.from('Synthetic operator')));
    assert.ok(!ciphertext.includes(config.storageKey));
    assert.equal((await fs.stat(archivePath)).mode & 0o777, 0o600);
    backup = await B.readBackup(archivePath, key);
    assert.equal(backup.files[0].sha256, files[0].sha256);
    const damaged = Buffer.from(ciphertext); damaged[damaged.length - 1] ^= 1;
    assert.throws(() => B.openBackup(damaged, key));
    assert.throws(() => B.openBackup(ciphertext, crypto.randomBytes(32)));
    assert.throws(() => B.openBackup(Buffer.from('truncated'), key));
    assert.throws(() => B.validateFiles([{ ...files[0], key: '../outside' }]));
    assert.throws(() => B.validateFiles([{ ...files[0], content: Buffer.from('tampered').toString('base64') }]));
    assert.throws(() => B.keyBytes('not-a-key'));
  });
  await t.test('restore requires an approved empty isolated target, never the live database', () => {
    const env = { NODE_ENV: 'test', RESTORE_APPROVED: 'true', MONGODB_RESTORE_URI: 'mongodb://localhost:27017', MONGODB_RESTORE_DB_NAME: 'synthetic_restore_test', RESTORE_DATA_DIR: path.join(root, 'restore') };
    assert.equal(validateRestore(backup, env), 'synthetic_restore_test');
    assert.throws(() => validateRestore(backup, { ...env, NODE_ENV: 'production' }));
    assert.throws(() => validateRestore(backup, { ...env, RESTORE_APPROVED: 'false' }));
    assert.throws(() => validateRestore(backup, { ...env, MONGODB_RESTORE_DB_NAME: backup.database }));
    assert.throws(() => validateRestore(backup, { ...env, DATA_DIR: env.RESTORE_DATA_DIR }));
  });
  await t.test('restored SQLite starts, preserves audit integrity and decrypts the original controlled file', async () => {
    const restoredDir = path.join(root, 'restore');
    await B.restoreFiles(restoredDir, backup.files);
    await fs.writeFile(path.join(restoredDir, 'adsp.sqlite'), Buffer.from(backup.sqlite, 'base64'), { mode: 0o600, flag: 'wx' });
    const restoredConfig = createConfig({ NODE_ENV: 'test', DATA_DIR: restoredDir, BASE_URL: config.baseUrl, MFA_ENCRYPTION_KEY: config.mfaKey.toString('hex'), STORAGE_ENCRYPTION_KEY: config.storageKey.toString('hex'), AUDIT_HMAC_KEY: config.auditKey.toString('hex') });
    app = await createApplication(restoredConfig, { jobs: false }); closed = false;
    const record = await app.store.run(tx => tx.get('document_versions', 'RECOVERY-DOC'));
    assert.equal((await app.platform.files.read(record.file)).toString(), 'RECOVERY SYNTHETIC CONTENT');
    const records = await app.store.run(tx => tx.list('audit_logs'));
    assert.equal(verifyAudit(records, restoredConfig.auditKey, await app.store.run(tx => tx.get('system_settings', 'audit-head'))), true);
    assert.equal((await app.store.run(tx => tx.list('users'))).length, 1);
    await assert.rejects(B.restoreFiles(restoredDir, backup.files), /empty/);
    assert.equal((await fs.readdir(path.join(restoredDir, 'storage', 'public'))).length, 0);
  });
  await t.test('legacy import is explicit, text-only, review-first and idempotent', async () => {
    const snapshot = { database: 'synthetic_legacy', exportedAt: new Date(), collections: [
      { name: 'users', documents: [{ username: 'legacy-unsafe' }] },
      { name: 'gallery', documents: [{ img: '/unverified.jpg' }] },
      { name: 'news', documents: [{ id: 'old-story', title: 'Synthetic legacy story', slug: 'synthetic-legacy-story', content: ['Synthetic legacy text.'], image: '/unverified.jpg', sortDate: '2026-09-01' }] },
      { name: 'submissions', documents: [{ id: 'old-enquiry', type: 'Contact enquiry', at: new Date().toISOString(), data: { name: 'Synthetic old parent', phone: '+255700000000', message: 'Synthetic legacy message', privateExtra: 'ignored' } }] }
    ] };
    const transformed = legacyRecords(snapshot, config);
    assert.equal(transformed.content[0].status, 'DRAFT'); assert.equal(transformed.content[0].mediaId, '');
    assert.equal(transformed.records[0].record.data.privateExtra, undefined);
    assert.equal(transformed.records[0].record.consent.acceptedAt, null);
    const first = await importLegacy(app.store, app.config, snapshot);
    const second = await importLegacy(app.store, app.config, snapshot);
    assert.deepEqual(first, second); assert.equal(first.drafts, 1); assert.equal(first.privateRecords, 1);
    assert.equal((await app.store.run(tx => tx.list('users'))).length, 1);
    assert.equal((await app.store.run(tx => tx.list('media'))).length, 0);
    assert.equal((await app.platform.publicContent()).some(item => item.slug === 'synthetic-legacy-story'), false);
    assert.equal((await app.store.run(tx => tx.list('submissions'))).length, 1);
  });
});
