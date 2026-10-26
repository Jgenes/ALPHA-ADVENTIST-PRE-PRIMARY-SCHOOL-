'use strict';
require('dotenv').config();
const L = require('../src/lib');
const B = require('./backup-lib');
const { createApplication } = require('../server');
const { audit } = require('../src/platform/audit');
const { FORM_RULES } = require('../src/platform/communications');
function legacyCollections(snapshot) {
  const byName = new Map(snapshot.collections.map(item => [item.name, item.documents]));
  const oldState = byName.get('platform_state')?.find(item => item._id === 'primary')?.data || {};
  return name => byName.get(name) || oldState[name] || [];
}
function legacyRecords(snapshot, config) {
  const get = legacyCollections(snapshot), content = [], records = [];
  const text = (value, max = 2000) => String(value ?? '').trim().slice(0, max);
  const iso = value => { const date = new Date(value || snapshot.exportedAt); if (!Number.isFinite(date.getTime())) throw new Error('Legacy dates need review.'); return date.toISOString(); };
  for (const [kind, name] of [['news', 'news'], ['event', 'events'], ['banner', 'announcements']]) {
    for (const old of get(name)) {
      const title = text(old.title, 180), body = text(Array.isArray(old.content) ? old.content.join('\n\n') : old.body || old.description || old.text, 20000);
      if (!title || !body) throw new Error('Legacy public content needs field mapping before migration.');
      const slug = L.slugify(old.slug || title);
      const publishedAt = iso(old.sortDate || old.at || old.createdAt);
      const fields = { kind, slug, title, body, content: body.split(/\n\s*\n/), excerpt: text(old.excerpt || body, 400), category: text(old.category || 'School News', 100), language: 'en', mediaId: '', seoTitle: '', seoDescription: '', eventDate: kind === 'event' ? iso(old.date || old.sortDate || old.createdAt).slice(0, 10) : '', version: 1, publishedAt };
      content.push({ id: 'CMS-LEGACY-' + L.sha256(kind + ':' + slug).slice(0, 24), ...fields, status: 'DRAFT', createdBy: 'legacy-import', createdAt: new Date().toISOString(), revisionAuthors: [], published: null, history: [], legacyPublication: false, migrationNote: 'Imported text only. Verify institutional facts and use independent review before publishing.' });
    }
  }
  const applications = get('admissionApplications');
  const appReferences = new Set(applications.map(item => item.applicationReference));
  for (const [source, list] of [['submissions', get('submissions')], ['admissionApplications', applications]]) {
    for (const old of list) {
      if (source === 'submissions' && appReferences.has(old.reference)) continue;
      const application = source === 'admissionApplications';
      const kind = application ? 'apply' : Object.keys(FORM_RULES).find(key => !FORM_RULES[key].private && (key === old.kind || key === old.type || FORM_RULES[key].type === old.type)) || 'contact';
      const input = application ? { child_name: old.applicant?.fullName, guardian_name: old.guardians?.[0]?.fullName, phone: old.guardians?.[0]?.phone, email: old.guardians?.[0]?.email, level: old.programme?.level || old.level, arrangement: old.programme?.arrangement || old.arrangement, message: old.message || '' } : old.data || {};
      const data = Object.fromEntries(FORM_RULES[kind].fields.map(field => [field, text(input[field], field === 'message' ? 2000 : 160)]));
      if (application && (!data.child_name || !data.guardian_name || !data.phone)) throw new Error('Legacy application mapping needs review; no partial import is allowed.');
      const createdAt = iso(old.at || old.createdAt);
      const reference = text(old.applicationReference || old.reference, 80);
      const id = reference && /^[A-Za-z0-9-]+$/.test(reference) ? reference : 'LEGACY-' + L.sha256(source + ':' + String(old.id || old._id || JSON.stringify(old))).slice(0, 24);
      records.push({ collection: kind === 'apply' ? 'admissions' : 'submissions', record: { id, reference: id, kind, type: FORM_RULES[kind].type, data, status: kind === 'apply' ? 'SUBMITTED' : 'NEW', createdAt, expiresAt: Date.parse(createdAt) + (kind === 'apply' ? config.admissionRetentionDays : config.formRetentionDays) * 86400000, history: [{ from: 'LEGACY', to: kind === 'apply' ? 'SUBMITTED' : 'NEW', at: new Date().toISOString(), note: 'Migrated for authorised review. Verify old status and the lawful retention basis; no old consent is assumed.' }], consent: { acceptedAt: null, policyVersion: 'legacy-not-recorded', purpose: 'verify_legacy_record', legacy: true } } });
    }
  }
  return { content, records, legacyUsersNotImported: get('users').length, legacyMediaNotImported: get('gallery').length };
}
async function importLegacy(store, config, snapshot) {
  const transformed = legacyRecords(snapshot, config);
  return store.run(async tx => {
    const id = 'migration:legacy-to-adsp-v1';
    const previous = await tx.get('system_settings', id);
    if (previous) return previous.counts;
    if ((await tx.list('admissions')).length || (await tx.list('submissions')).length || (await tx.list('cms_content')).some(item => !item.legacyPublication)) throw new Error('Target has live platform records. Review a separate merge plan; nothing was changed.');
    // Seed articles are already-published legacy text. An incoming edition of
    // the same slug becomes a draft, preserving the current public snapshot.
    for (const item of transformed.content) {
      const old = (await tx.list('cms_content')).find(value => value.kind === item.kind && value.slug === item.slug);
      if (old) await tx.update('cms_content', { ...old, ...item, id: old.id, revision: old.revision, published: old.published, history: old.history, version: old.version + 1 });
      else await tx.insert('cms_content', item);
    }
    for (const item of transformed.records) await tx.insert(item.collection, item.record);
    const counts = { drafts: transformed.content.length, privateRecords: transformed.records.length, legacyUsersNotImported: transformed.legacyUsersNotImported, legacyMediaNotImported: transformed.legacyMediaNotImported };
    await tx.insert('system_settings', { id, at: new Date().toISOString(), sourceDatabase: snapshot.database, sourceExportedAt: isoDate(snapshot.exportedAt), counts });
    await audit(tx, config, { userId: 'approved-migration' }, 'migration.legacy_import', 'system', id, 'success', counts);
    return counts;
  });
}
function isoDate(value) { return new Date(value).toISOString(); }
async function main() {
  if (process.env.MONGO_MIGRATION_APPROVED !== 'true' || process.env.BACKUP_WRITES_PAUSED !== 'true') throw new Error('Explicit migration approval and a maintenance window are required.');
  if (['production', 'staging'].includes(process.env.NODE_ENV) && process.env.MONGO_MIGRATION_STAGE_VERIFIED !== 'true') throw new Error('Record successful rehearsal before migrating a non-development environment.');
  if (!process.env.MONGODB_URI || !process.env.MONGODB_DB_NAME || !process.env.MONGO_MIGRATION_BACKUP_FILE) throw new Error('Explicit MongoDB target and pre-migration backup are required.');
  const snapshot = await B.readBackup(process.env.MONGO_MIGRATION_BACKUP_FILE, await B.backupKey());
  if (snapshot.database !== process.env.MONGODB_DB_NAME) throw new Error('Backup source and migration database differ.');
  if (Date.now() - Date.parse(snapshot.exportedAt) > 24 * 60 * 60 * 1000) throw new Error('Use a verified backup made within the last 24 hours.');
  const app = await createApplication(undefined, { jobs: false });
  try { console.log('Legacy migration counts:', await importLegacy(app.store, app.config, snapshot)); console.log('Old collections are unchanged. Accounts, old password hashes, photographs, menus, settings and files were NOT imported. Re-provision accounts and review public drafts and private retention before reopening the site.'); }
  finally { await app.close(); }
}
if (require.main === module) main().catch(error => { console.error('[migration] Failed. Review approvals, mapping, a fresh backup and the empty target namespace.', error.name, error.code || ''); process.exitCode = 1; });
module.exports = { legacyRecords, importLegacy, main };
