'use strict';
require('dotenv').config();
const path = require('node:path');
const L = require('../src/lib');
const fs = require('node:fs/promises');
const { mysqlConfig } = require('../src/platform/mysql-config');
const { createSchema } = require('../src/platform/mysql-store');
const B = require('./backup-lib');
const T = require('./mysql-transfer');
const { supabaseStorageConfig, createSupabaseStorage } = require('../src/platform/supabase-storage');
// One-time cutover import: read an archive written by the retired MongoDB backup tool
// (or a current MySQL archive) and load it into an empty MySQL schema. Legacy CMS
// collections are deliberately not guessed here; use npm run migrate:mysql for those.
function convert(snapshot) {
  const byName = new Map();
  const skipped = [];
  for (const entry of snapshot.collections || []) {
    const name = T.KNOWN.has(entry.name) ? entry.name : T.KNOWN.has('adsp_' + entry.name) ? 'adsp_' + entry.name : null;
    if (!name) { skipped.push(entry.name); continue; }
    const target = byName.get(name) || { name, documents: [] };
    for (const document of entry.documents || []) {
      const record = B.normaliseDocument(document);
      // An export of the same collection twice is only safe when the copies agree.
      const clash = target.documents.find(item => item.id === record.id);
      if (clash && JSON.stringify(clash) !== JSON.stringify(record)) throw new Error(`The archive holds two different ${name} records with id ${record.id}; resolve it before importing.`);
      if (!clash) target.documents.push(record);
    }
    byName.set(name, target);
  }
  return { collections: [...byName.values()], skipped };
}
function validateImport(snapshot, env) {
  if (env.MYSQL_IMPORT_APPROVED !== 'true') throw new Error('A cutover import needs explicit approval: MYSQL_IMPORT_APPROVED=true.');
  if (['production', 'staging'].includes(env.NODE_ENV) && env.MYSQL_IMPORT_STAGE_VERIFIED !== 'true') throw new Error('Rehearse the import on an isolated staging schema first, then set MYSQL_IMPORT_STAGE_VERIFIED=true.');
  const target = mysqlConfig(env, { production: ['production', 'staging'].includes(env.NODE_ENV) });
  if (!target) throw new Error('The MySQL target (MYSQL_URL or MYSQL_HOST/MYSQL_USER/MYSQL_DB_NAME) is required.');
  if (snapshot.database === target.database) throw new Error('The import source and target schemas must differ; never import over the database a backup was taken from.');
  const { collections, skipped } = convert(snapshot);
  if (!collections.length) throw new Error('The archive holds no platform tables; nothing to import.');
  const planned = T.planTables(collections);
  return { target, planned, skipped, files: snapshot.files || [], storage: snapshot.storage || { provider: 'local' } };
}
async function main() {
  const env = process.env;
  const file = process.argv[2] || env.MYSQL_IMPORT_BACKUP_FILE;
  if (!file) throw new Error('Supply the encrypted backup path, or set MYSQL_IMPORT_BACKUP_FILE.');
  const snapshot = await B.readBackup(file, await B.backupKey(env), { allowLegacy: true });
  const { target, planned, skipped, files, storage } = validateImport(snapshot, env);
  const dryRun = env.MYSQL_IMPORT_DRY_RUN === 'true';
  const summary = planned.planned.map(entry => `${entry.name}: ${entry.rows.length}${entry.invalidated ? ' (access records cleared)' : ''}`);
  console.log(`Import plan from ${snapshot.database} (exported ${snapshot.exportedAt}) into ${target.database}\n${summary.join('\n') || 'no rows'}\nArchive tables left for the reviewed legacy migration: ${skipped.join(', ') || 'none'}\nPrivate files: ${files.length}${storage.provider === 'supabase-s3' ? ' in Supabase bucket ' + storage.bucket : ' in local storage'}.`);
  if (dryRun) { console.log('Dry run only: nothing was written.'); return; }
  const dataDir = env.DATA_DIR ? path.resolve(env.DATA_DIR) : null;
  const objectStorage = storage.provider === 'supabase-s3' ? createSupabaseStorage(supabaseStorageConfig(env)) : null;
  if (files.length && !dataDir && !objectStorage) throw new Error('DATA_DIR (or the Supabase Storage settings) is required to place the archive files.');
  const mysql = require('mysql2/promise');
  const admin = await mysql.createConnection({ ...target.options, database: undefined });
  try {
    const [existing] = await admin.query('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [target.database]);
    if (!existing.length) {
      if (!target.createSchema) throw new Error(`The target schema ${target.database} does not exist. Create it, or set MYSQL_CREATE_SCHEMA=true to let this command create it.`);
      await admin.query('CREATE DATABASE `' + target.database + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    }
  } finally { await admin.end(); }
  const connection = await mysql.createConnection(target.options);
  try {
    const [tables] = await connection.query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (tables.length) throw new Error('The target schema already has tables. Import only into an empty schema so a partial cutover can never be mistaken for a complete one.');
    await createSchema(connection);
    await T.writeTables(connection, planned);
    if (files.length) await B.restoreFiles(dataDir, files, objectStorage);
    console.log(`Import complete: ${planned.planned.reduce((total, entry) => total + entry.rows.length, 0)} records. Sessions and download grants were deliberately cleared, so every person must sign in again and old download links no longer work.\nNow verify: audit-chain integrity in the portal system view, one document download, one approval round trip, and the legacy-migration counts. Keep the old database read-only until the school signs off.`);
  } finally { await connection.end(); }
}
if (require.main === module) main().catch(error => { console.error('[import] Failed. Check approval, archive key, an empty target schema and the maintenance window.', L.safeErrorMessage(error), error.code || ''); process.exitCode = 1; });
module.exports = { convert, validateImport, main };
