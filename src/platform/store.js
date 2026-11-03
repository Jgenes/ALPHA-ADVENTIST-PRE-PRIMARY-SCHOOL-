'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { HttpError } = require('../lib');

// Names are code-owned, never interpolated from a request. The prefix keeps the
// audited platform separate from legacy collections until an explicit migration.
const COLLECTIONS = ['users', 'sessions', 'login_limits', 'departments', 'staff_profiles', 'student_profiles', 'student_guardians', 'classes', 'class_teachers', 'attendance_records', 'student_results', 'learning_materials', 'family_messages', 'admission_documents', 'leave_types', 'leave_requests', 'leave_balances', 'contracts', 'documents', 'document_versions', 'workflow_definitions', 'workflow_instances', 'notices', 'notice_acknowledgements', 'cms_content', 'admissions', 'submissions', 'notifications', 'outbox', 'calendar', 'media', 'media_consents', 'privacy_requests', 'incidents', 'audit_logs', 'system_settings', 'download_grants'];
const clone = value => value == null ? null : structuredClone(value);
const conflict = () => new HttpError(409, 'This record changed. Refresh and try again.', 'VERSION_CONFLICT');
function table(name) {
  if (!COLLECTIONS.includes(name)) throw new Error('Unknown collection.');
  return 'adsp_' + name;
}
class SqliteUnit {
  constructor(db) { this.db = db; }
  async get(name, id) {
    const row = this.db.prepare(`SELECT payload FROM ${table(name)} WHERE id = ?`).get(String(id));
    return row ? JSON.parse(row.payload) : null;
  }
  async list(name) { return this.db.prepare(`SELECT payload FROM ${table(name)} ORDER BY rowid`).all().map(row => JSON.parse(row.payload)); }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    try { this.db.prepare(`INSERT INTO ${table(name)} (id, revision, payload) VALUES (?, ?, ?)`).run(value.id, 1, JSON.stringify(value)); }
    catch (error) { if (error.code === 'ERR_SQLITE_ERROR' && /UNIQUE/.test(error.message)) throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    const result = this.db.prepare(`UPDATE ${table(name)} SET revision = ?, payload = ? WHERE id = ? AND revision = ?`).run(value.revision, JSON.stringify(value), value.id, expected);
    if (!result.changes) throw conflict();
    return value;
  }
  async remove(name, id) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    this.db.prepare(`DELETE FROM ${table(name)} WHERE id = ?`).run(id);
  }
}
class MongoUnit {
  constructor(db, session) { this.db = db; this.session = session; }
  collection(name) { return this.db.collection(table(name)); }
  async get(name, id) {
    const value = await this.collection(name).findOne({ _id: String(id) }, { session: this.session });
    if (value) delete value._id;
    return value;
  }
  async list(name) {
    const values = await this.collection(name).find({}, { session: this.session }).toArray();
    return values.map(({ _id, ...value }) => value);
  }
  async insert(name, record) {
    const value = { ...clone(record), revision: 1 };
    try { await this.collection(name).insertOne({ _id: value.id, ...value }, { session: this.session }); }
    catch (error) { if (error.code === 11000) throw conflict(); throw error; }
    return value;
  }
  async update(name, record, expected = record.revision) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    const value = { ...clone(record), revision: expected + 1 };
    const result = await this.collection(name).replaceOne({ _id: value.id, revision: expected }, { _id: value.id, ...value }, { session: this.session });
    if (!result.matchedCount) throw conflict();
    return value;
  }
  async remove(name, id) {
    if (name === 'audit_logs') throw new Error('Audit records are append-only.');
    await this.collection(name).deleteOne({ _id: id }, { session: this.session });
  }
}
async function openStore(config) {
  if (config.mongoUri) {
    const { MongoClient } = require('mongodb');
    const client = new MongoClient(config.mongoUri, { serverSelectionTimeoutMS: 10000, maxPoolSize: 15, appName: 'AlphaDigitalSchoolPlatform', ...(config.production ? { tls: true } : {}) });
    try {
      await client.connect();
      const db = client.db(config.mongoDb || 'alpha_adventist_development');
      const topology = await db.command({ hello: 1 });
      if (!topology.setName && topology.msg !== 'isdbgrid') throw new Error('MongoDB must support transactions (replica set or Atlas).');
      const existing = new Set((await db.listCollections({}, { nameOnly: true }).toArray()).map(item => item.name));
      for (const name of COLLECTIONS) {
        if (!existing.has(table(name))) await db.createCollection(table(name), { validator: { $jsonSchema: { bsonType: 'object', required: ['id', 'revision'], properties: { id: { bsonType: 'string' }, revision: { bsonType: ['int', 'long', 'double'], minimum: 1 } } } } });
      }
      await db.collection(table('users')).createIndex({ username: 1 }, { unique: true });
      await db.collection(table('audit_logs')).createIndex({ sequence: 1 }, { unique: true });
      await db.collection(table('staff_profiles')).createIndex({ staffId: 1 }, { unique: true });
      await db.collection(table('student_profiles')).createIndex({ studentRef: 1 }, { unique: true });
      await db.collection(table('student_profiles')).createIndex({ userId: 1 }, { unique: true });
      await db.collection(table('student_guardians')).createIndex({ studentId: 1, guardianUserId: 1 }, { unique: true });
      await db.collection(table('class_teachers')).createIndex({ classId: 1, teacherUserId: 1 }, { unique: true });
      await db.collection(table('attendance_records')).createIndex({ studentId: 1, date: 1 }, { unique: true });
      await db.collection(table('family_messages')).createIndex({ studentId: 1, createdAt: 1 });
      await db.collection(table('cms_content')).createIndex({ kind: 1, slug: 1 }, { unique: true });
      for (const name of ['sessions', 'login_limits', 'download_grants']) await db.collection(table(name)).createIndex({ expiresAt: 1 });
      return {
        kind: 'mongodb',
        async run(fn) {
          const session = client.startSession();
          try { return await session.withTransaction(() => fn(new MongoUnit(db, session)), { readConcern: { level: 'snapshot' }, writeConcern: { w: 'majority' } }); }
          finally { await session.endSession(); }
        },
        async health() { await db.command({ ping: 1 }); return true; },
        async close() { await client.close(); }
      };
    } catch (error) {
      await client.close();
      throw new Error(`MongoDB startup connection/check failed: ${error.message}`, { cause: error });
    }
  }
  const { DatabaseSync } = require('node:sqlite');
  const file = path.join(config.dataDir, 'adsp.sqlite');
  const db = new DatabaseSync(file);
  fs.chmodSync(file, 0o600);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA secure_delete = ON;');
  for (const name of COLLECTIONS) db.exec(`CREATE TABLE IF NOT EXISTS ${table(name)} (id TEXT PRIMARY KEY, revision INTEGER NOT NULL, payload TEXT NOT NULL CHECK(json_valid(payload)))`);
  db.exec(`CREATE UNIQUE INDEX IF NOT EXISTS adsp_username ON adsp_users(json_extract(payload, '$.username'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_staff_id ON adsp_staff_profiles(json_extract(payload, '$.staffId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_ref ON adsp_student_profiles(json_extract(payload, '$.studentRef'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_user ON adsp_student_profiles(json_extract(payload, '$.userId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_student_guardian ON adsp_student_guardians(json_extract(payload, '$.studentId'), json_extract(payload, '$.guardianUserId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_class_teacher ON adsp_class_teachers(json_extract(payload, '$.classId'), json_extract(payload, '$.teacherUserId'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_attendance_student_date ON adsp_attendance_records(json_extract(payload, '$.studentId'), json_extract(payload, '$.date'));
    CREATE UNIQUE INDEX IF NOT EXISTS adsp_content_slug ON adsp_cms_content(json_extract(payload, '$.kind'), json_extract(payload, '$.slug'));
    CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;
    CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON adsp_audit_logs BEGIN SELECT RAISE(ABORT, 'Audit records are append-only'); END;`);
  // All operations, including reads, share this queue: no reader can observe an
  // asynchronous transaction's uncommitted state on the SQLite connection.
  let queue = Promise.resolve();
  return {
    kind: 'sqlite',
    run(fn) {
      const pending = queue.then(async () => {
        db.exec('BEGIN IMMEDIATE');
        try { const result = await fn(new SqliteUnit(db)); db.exec('COMMIT'); return result; }
        catch (error) { db.exec('ROLLBACK'); throw error; }
      });
      queue = pending.catch(() => {});
      return pending;
    },
    async health() { await queue; return !!db.prepare('SELECT 1 AS ok').get().ok; },
    async close() { await queue; db.close(); }
  };
}
module.exports = { openStore, COLLECTIONS };
