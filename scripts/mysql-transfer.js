'use strict';
// Shared, guarded table movement for restore and cutover import. Table names are only
// ever taken from the code-owned collection list, never from an archive.
const { COLLECTIONS, table } = require('../src/platform/collections');
const { createSchema } = require('../src/platform/mysql-store');
const KNOWN = new Set(COLLECTIONS.map(table));
// Sessions and download grants are never carried across a recovery: an old cookie or
// an old signed link must not resurrect access that has since been revoked.
const INVALIDATED = ['adsp_sessions', 'adsp_download_grants'];
function planTables(collections, { allowUnknown = false } = {}) {
  const names = new Set();
  const planned = [];
  let skipped = 0;
  for (const entry of collections || []) {
    if (!entry || typeof entry.name !== 'string' || !Array.isArray(entry.documents)) throw new Error('An archive table has no name or document list.');
    if (!KNOWN.has(entry.name)) {
      if (allowUnknown) { skipped++; continue; }
      throw new Error(`The archive contains the unrecognised table ${entry.name}; review it before writing to MySQL.`);
    }
    if (names.has(entry.name)) throw new Error(`The archive repeats the table ${entry.name}.`);
    names.add(entry.name);
    const documents = INVALIDATED.includes(entry.name) ? [] : entry.documents;
    const rows = documents.map(document => {
      if (!document || typeof document !== 'object' || Array.isArray(document)) throw new Error(`A ${entry.name} record is not an object.`);
      const revision = Number(document.revision);
      if (!Number.isSafeInteger(revision) || revision < 1) throw new Error(`A ${entry.name} record has no valid revision.`);
      return [String(document.id), revision, JSON.stringify(document)];
    });
    planned.push({ name: entry.name, rows, invalidated: INVALIDATED.includes(entry.name) && entry.documents.length > 0 });
  }
  return { planned, skipped };
}
async function requireEmptySchema(connection, database) {
  const [rows] = await connection.query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [database]);
  if (rows.length) throw new Error(`The target schema ${database} already holds ${rows.length} table(s). Existing data is never overwritten; use a fresh, empty schema.`);
  await createSchema(connection);
}
async function writeTables(connection, { planned }) {
  const counts = {};
  for (const entry of planned) {
    // Chunked multi-row inserts keep a large import inside the server's packet limit.
    const chunk = 200;
    for (let index = 0; index < entry.rows.length; index += chunk) {
      const part = entry.rows.slice(index, index + chunk);
      const placeholders = part.map(() => '(?, ?, ?)').join(', ');
      await connection.query(`INSERT INTO \`${entry.name}\` (id, revision, payload) VALUES ${placeholders}`, part.flat());
    }
    const [rows] = await connection.query(`SELECT COUNT(*) AS total FROM \`${entry.name}\``);
    if (Number(rows[0].total) !== entry.rows.length) throw new Error(`Row count verification failed for ${entry.name}.`);
    counts[entry.name] = entry.rows.length;
  }
  return counts;
}
module.exports = { INVALIDATED, KNOWN, planTables, requireEmptySchema, writeTables };
