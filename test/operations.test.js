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
const { Files } = require('../src/platform/files');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
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
  await t.test('Supabase S3 configuration and encrypted file operations stay server-side', async () => {
    const storageConfig = supabaseStorageConfig({
      SUPABASE_S3_ENDPOINT: 'https://project.storage.supabase.co/storage/v1/s3',
      SUPABASE_S3_REGION: 'us-east-1', SUPABASE_S3_ACCESS_KEY_ID: 'synthetic-access',
      SUPABASE_S3_SECRET_ACCESS_KEY: 'synthetic-secret', SUPABASE_STORAGE_BUCKET: 'alpha-private'
    });
    assert.equal(storageConfig.bucket, 'alpha-private');
    assert.throws(() => supabaseStorageConfig({ SUPABASE_S3_ENDPOINT: storageConfig.endpoint }), /missing: SUPABASE_S3_REGION, SUPABASE_S3_ACCESS_KEY_ID, SUPABASE_S3_SECRET_ACCESS_KEY, SUPABASE_STORAGE_BUCKET/);
    assert.throws(() => supabaseStorageConfig({
      SUPABASE_S3_ENDPOINT: 'http://unsafe.example', SUPABASE_S3_REGION: storageConfig.region,
      SUPABASE_S3_ACCESS_KEY_ID: storageConfig.accessKeyId, SUPABASE_S3_SECRET_ACCESS_KEY: storageConfig.secretAccessKey,
      SUPABASE_STORAGE_BUCKET: storageConfig.bucket
    }), /trusted HTTPS endpoint/);
    const commands = [];
    const sdk = createSupabaseStorage(storageConfig, { async send(command) {
      commands.push(command);
      if (command.constructor.name === 'GetObjectCommand') return { Body: { transformToByteArray: async () => Buffer.from('stored-ciphertext') } };
      if (command.constructor.name === 'ListObjectsV2Command') return { KeyCount: 0 };
      return {};
    } });
    await sdk.check();
    assert.equal((await sdk.get('private', 'a'.repeat(48))).toString(), 'stored-ciphertext');
    await sdk.put('private', 'b'.repeat(48), Buffer.from('ciphertext'));
    await sdk.remove('private', 'b'.repeat(48));
    assert.equal(await sdk.isEmpty(), true);
    assert.deepEqual(commands.map(command => command.constructor.name), ['HeadBucketCommand', 'GetObjectCommand', 'PutObjectCommand', 'DeleteObjectCommand', 'ListObjectsV2Command']);
    assert.equal(commands[1].input.Key, 'private/' + 'a'.repeat(48));
    await assert.rejects(sdk.get('private', '../unsafe'), /Invalid object storage key/);

    const objects = new Map();
    const remote = {
      bucket: 'alpha-private', check: async () => {}, isEmpty: async () => objects.size === 0,
      get: async (area, fileKey) => { const value = objects.get(`${area}/${fileKey}`); if (!value) throw new Error('missing'); return Buffer.from(value); },
      put: async (area, fileKey, value) => { objects.set(`${area}/${fileKey}`, Buffer.from(value)); },
      remove: async (area, fileKey) => { objects.delete(`${area}/${fileKey}`); }
    };
    const remoteFiles = new Files({ ...config, supabaseStorage: storageConfig }, remote);
    await remoteFiles.init();
    const file = await remoteFiles.prepare(textFile('REMOTE SYNTHETIC CONTENT'));
    assert.ok(!objects.get('private/' + file.storageKey).includes(Buffer.from('REMOTE SYNTHETIC CONTENT')));
    assert.equal((await remoteFiles.read(file)).toString(), 'REMOTE SYNTHETIC CONTENT');
    await remoteFiles.publish(file);
    assert.equal(objects.get('public/' + file.storageKey).toString(), 'REMOTE SYNTHETIC CONTENT');
    await remoteFiles.unpublish(file); await remoteFiles.discard(file);
    assert.equal(objects.size, 0);
  });
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
    const sourceBytes = await fs.readFile(app.platform.files.keyPath('private', encryptedFile.storageKey));
    const remoteObjects = new Map([['private/' + encryptedFile.storageKey, sourceBytes]]);
    const remoteSource = { get: async (area, fileKey) => Buffer.from(remoteObjects.get(`${area}/${fileKey}`)) };
    const remoteFiles = await B.captureFiles(config.dataDir, collections, remoteSource);
    assert.deepEqual(remoteFiles, files);
    const restoredObjects = new Map();
    const remoteTarget = {
      isEmpty: async () => restoredObjects.size === 0,
      put: async (area, fileKey, value) => { restoredObjects.set(`${area}/${fileKey}`, Buffer.from(value)); }
    };
    const remoteRestoreDir = path.join(root, 'supabase-restore');
    await B.restoreFiles(remoteRestoreDir, remoteFiles, remoteTarget);
    assert.deepEqual(restoredObjects.get('private/' + encryptedFile.storageKey), sourceBytes);
    await assert.rejects(B.restoreFiles(remoteRestoreDir, remoteFiles, remoteTarget), /bucket must be empty/);
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
    const remoteBackup = { ...backup, storage: { provider: 'supabase-s3', bucket: 'source-private' } };
    const remoteEnv = { ...env, SUPABASE_STORAGE_BUCKET: 'production-private', SUPABASE_RESTORE_BUCKET: 'restore-private' };
    assert.equal(validateRestore(remoteBackup, remoteEnv), 'synthetic_restore_test');
    assert.throws(() => validateRestore(remoteBackup, { ...remoteEnv, SUPABASE_RESTORE_BUCKET: 'source-private' }), /separate empty Supabase/);
    assert.throws(() => validateRestore(remoteBackup, { ...remoteEnv, SUPABASE_RESTORE_BUCKET: 'production-private' }), /separate empty Supabase/);
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
