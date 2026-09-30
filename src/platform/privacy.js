'use strict';
const L = require('../lib');
const A = require('./access');
const V = require('./validation');
const W = require('./workflows');
const { audit } = require('./audit');
const { HttpError } = L;
const CHANNELS = ['website', 'facebook', 'instagram', 'youtube', 'print', 'externalPromotion'];
async function currentConsent(tx, studentRef, channel = 'website') {
  const head = await tx.get('system_settings', 'consent-current:' + L.sha256(studentRef));
  const latest = head ? await tx.get('media_consents', head.recordId) : null;
  const today = new Date().toISOString().slice(0, 10);
  return !!latest && latest.status === 'ACTIVE' && latest.channels[channel] === true && latest.grantedAt <= today && latest.expiresAt >= today;
}
async function validMediaConsent(tx, record) {
  if (!record.subjectsVerified || !record.noChildNames) return false;
  if (!record.childrenPresent) return record.studentRefs.length === 0;
  if (!record.studentRefs.length) return false;
  for (const ref of record.studentRefs) if (!await currentConsent(tx, ref)) return false;
  return true;
}
async function mediaIsPublic(tx, id) {
  const record = typeof id === 'string' ? await tx.get('media', id) : id;
  return !!record && record.status === 'PUBLISHED' && await validMediaConsent(tx, record);
}
module.exports = {
  async privacyRecords(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      await audit(tx, this.config, actor, 'privacy.register_read', 'privacy', 'authorised');
      return { consents: await tx.list('media_consents'), requests: await tx.list('privacy_requests'), incidents: await tx.list('incidents') };
    });
  },
  async saveConsent(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const studentRef = L.text(body.studentRef, 80, true);
      if (!/^[A-Z0-9-]{3,80}$/.test(studentRef)) throw new HttpError(400, 'Use a private pupil reference, not the child’s name.');
      const grantedAt = V.date(body.grantedAt), expiresAt = V.date(body.expiresAt);
      if (grantedAt > new Date().toISOString().slice(0, 10) || expiresAt <= grantedAt || Date.parse(expiresAt) - Date.parse(grantedAt) > 366 * 86400000) throw new HttpError(400, 'Consent must be verified, dated and reviewed within one year.');
      if (!body.channels || CHANNELS.some(channel => typeof body.channels[channel] !== 'boolean')) throw new HttpError(400, 'Record a separate grant or refusal for every media channel.');
      const evidence = await tx.get('document_versions', L.text(body.evidenceVersionId, 100, true));
      if (!evidence || !['CONFIDENTIAL', 'HIGHLY_CONFIDENTIAL'].includes(evidence.classification) || !A.canReadDocument(actor.user, evidence)) throw new HttpError(400, 'Attach a confidential signed-consent evidence document you are authorised to access.');
      if (body.authorityVerified !== true) throw new HttpError(400, 'Verify the guardian’s authority before recording consent.');
      const channels = Object.fromEntries(CHANNELS.map(channel => [channel, body.channels[channel]]));
      const record = { id: L.rid('CON-'), studentRef, guardianName: L.text(body.guardianName, 160, true), relationship: V.choose(body.relationship, ['Parent', 'Legal guardian']), grantedAt, expiresAt, channels, evidenceVersionId: evidence.id, authorityVerifiedBy: actor.user.id, status: Object.values(channels).some(Boolean) ? 'ACTIVE' : 'REFUSED', recordedAt: new Date().toISOString(), recordedBy: actor.user.id, withdrawal: null };
      const saved = await tx.insert('media_consents', record);
      const pointerId = 'consent-current:' + L.sha256(studentRef);
      const pointer = await tx.get('system_settings', pointerId);
      if (pointer) await tx.update('system_settings', { ...pointer, recordId: saved.id });
      else await tx.insert('system_settings', { id: pointerId, recordId: saved.id });
      // A newer refusal immediately blocks old grants, even before maintenance.
      for (const media of await tx.list('media')) {
        if (media.studentRefs.includes(studentRef) && media.status === 'PUBLISHED' && !await validMediaConsent(tx, media)) {
          await tx.update('media', { ...media, status: 'WITHDRAWN' });
          await this.files.unpublish(media.file);
        }
      }
      await audit(tx, this.config, actor, 'consent.record', 'media_consent', saved.id, 'success', { status: saved.status });
      return saved;
    });
  },
  async withdrawConsent(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const record = await tx.get('media_consents', id);
      if (!record) throw new HttpError(404, 'Consent record not found.');
      V.revision(record, body);
      if (record.status === 'WITHDRAWN') throw new HttpError(409, 'This consent is already withdrawn.');
      const now = new Date();
      const deadline = new Date(Date.now() + 48 * 60 * 60 * 1000).toISOString();
      const withdrawal = { at: now.toISOString(), verifiedBy: actor.user.id, reason: L.text(body.reason, 1000, true), removalDeadline: deadline, websiteRemovedAt: now.toISOString(), externalChannels: CHANNELS.filter(channel => channel !== 'website' && record.channels[channel]), externalRemovalConfirmedAt: null };
      const saved = await tx.update('media_consents', { ...record, status: 'WITHDRAWN', withdrawal });
      for (const media of await tx.list('media')) {
        if (media.studentRefs.includes(record.studentRef) && media.status === 'PUBLISHED') {
          await tx.update('media', { ...media, status: 'WITHDRAWN', withdrawnAt: now.toISOString() });
          await this.files.unpublish(media.file);
        }
      }
      await W.notify(tx, actor.user.id, 'Consent withdrawn: confirm removal from external channels within 48 hours', '/portal/privacy', id);
      await audit(tx, this.config, actor, 'consent.withdraw', 'media_consent', id, 'success', { removalDeadline: deadline });
      return saved;
    });
  },
  async confirmConsentRemoval(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const record = await tx.get('media_consents', id);
      if (!record?.withdrawal) throw new HttpError(409, 'No withdrawal is pending.');
      V.revision(record, body);
      const saved = await tx.update('media_consents', { ...record, withdrawal: { ...record.withdrawal, externalRemovalConfirmedAt: new Date().toISOString(), removalConfirmedBy: actor.user.id, removalNote: L.text(body.comment, 1000, true) } });
      await audit(tx, this.config, actor, 'consent.removal_confirmed', 'media_consent', id);
      return saved;
    });
  },
  async resolvePrivacyRequest(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const record = await tx.get('privacy_requests', id);
      if (!record) throw new HttpError(404, 'Request not found.');
      V.revision(record, body);
      const status = V.choose(body.status, ['UNDER_REVIEW', 'VERIFICATION_REQUIRED', 'RESOLVED']);
      record.history.push({ status, comment: L.text(body.comment, 2000, true), by: actor.user.id, at: new Date().toISOString() });
      record.status = status;
      const saved = await tx.update('privacy_requests', record);
      await audit(tx, this.config, actor, 'privacy.request_update', 'privacy_request', id, 'success', { status });
      return saved;
    });
  },
  async createIncident(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const record = await tx.insert('incidents', { id: L.rid('INC-'), title: L.text(body.title, 160, true), description: L.text(body.description, 4000, true), status: 'OPEN', severity: V.choose(body.severity, ['Low', 'Medium', 'High', 'Critical']), reportedBy: actor.user.id, createdAt: new Date().toISOString(), actions: [] });
      await audit(tx, this.config, actor, 'privacy.incident_create', 'incident', record.id);
      return record;
    });
  },
  async updateIncident(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'privacy.manage');
      const record = await tx.get('incidents', id);
      if (!record) throw new HttpError(404, 'Incident not found.');
      V.revision(record, body);
      record.actions.push({ comment: L.text(body.comment, 2000, true), by: actor.user.id, at: new Date().toISOString() });
      record.status = V.choose(body.status, ['OPEN', 'INVESTIGATING', 'CONTAINED', 'CLOSED']);
      const saved = await tx.update('incidents', record);
      await audit(tx, this.config, actor, 'privacy.incident_update', 'incident', id, 'success', { status: record.status });
      return saved;
    });
  },
  async media(actor) {
    return this.run(actor, async (tx, actor) => {
      if (!['media.create', 'media.review', 'media.publish'].some(permission => A.has(actor.user, permission))) throw new HttpError(403, 'Media management is restricted.');
      const result = [];
      for (const item of await tx.list('media')) result.push({ ...V.safeRecord(item), consentCurrent: await validMediaConsent(tx, item) });
      await audit(tx, this.config, actor, 'media.library_read', 'media', 'authorised');
      return result;
    });
  },
  async createMedia(actor, body) {
    A.requirePermission(actor.user, 'media.create');
    const file = await this.files.prepare(body.file, true);
    try {
      return await this.run(actor, async (tx, actor) => {
        A.requirePermission(actor.user, 'media.create');
        if (typeof body.childrenPresent !== 'boolean' || body.subjectsVerified !== true || body.noChildNames !== true) throw new HttpError(400, 'Confirm the subjects have been checked and that captions contain no child names.');
        const studentRefs = V.strings(body.studentRefs);
        if (body.childrenPresent ? !studentRefs.length : studentRefs.length > 0) throw new HttpError(400, 'Record every depicted child’s private consent reference, or confirm no children are depicted.');
        const record = { id: L.rid('MED-'), title: L.text(body.title, 160, true), alt: L.text(body.alt, 300, true), category: L.text(body.category, 100, true), studentRefs, childrenPresent: body.childrenPresent, subjectsVerified: true, noChildNames: true, file, status: 'DRAFT', createdBy: actor.user.id, createdAt: new Date().toISOString() };
        if (!await validMediaConsent(tx, record)) throw new HttpError(409, 'Every child needs a current, independently recorded website consent before this media can be submitted.');
        const saved = await tx.insert('media', record);
        await audit(tx, this.config, actor, 'media.upload', 'media', record.id, 'success', { checksum: file.checksum });
        return V.safeRecord(saved);
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async mediaAction(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      const record = await tx.get('media', id);
      if (!record) throw new HttpError(404, 'Media not found.');
      V.revision(record, body);
      if (body.action === 'SUBMIT') {
        A.requirePermission(actor.user, 'media.create');
        if (record.createdBy !== actor.user.id || !['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(403, 'Only the author can submit a draft.');
        if (!await validMediaConsent(tx, record)) throw new HttpError(409, 'Current media consent is missing.');
        const flow = await W.startWorkflow(tx, actor, this.config, 'media-standard', record);
        return V.safeRecord(await tx.update('media', { ...record, workflowId: flow.id, status: 'UNDER_REVIEW' }));
      }
      A.requirePermission(actor.user, 'media.publish');
      if (body.action === 'PUBLISH') {
        if (record.status !== 'APPROVED' || record.createdBy === actor.user.id || !await validMediaConsent(tx, record)) throw new HttpError(409, 'Independent approval and current consent are required before publication.');
        await this.files.publish(record.file);
        const saved = await tx.update('media', { ...record, status: 'PUBLISHED', publishedAt: new Date().toISOString(), publishedBy: actor.user.id });
        await audit(tx, this.config, actor, 'media.publish', 'media', id);
        return V.safeRecord(saved);
      }
      if (body.action === 'ARCHIVE') {
        const saved = await tx.update('media', { ...record, status: 'ARCHIVED' });
        await this.files.unpublish(record.file);
        await audit(tx, this.config, actor, 'media.archive', 'media', id);
        return V.safeRecord(saved);
      }
      throw new HttpError(400, 'Choose submit, publish or archive.');
    });
  },
  async publicMedia() {
    return this.store.run(async tx => {
      const out = [];
      for (const media of await tx.list('media')) if (await mediaIsPublic(tx, media)) out.push({ id: media.id, title: media.title, alt: media.alt, category: media.category, url: '/media/' + media.id });
      return out;
    });
  },
  async publicMediaFile(id) {
    return this.store.run(async tx => {
      const record = await tx.get('media', id);
      if (!await mediaIsPublic(tx, record)) throw new HttpError(404, 'Media is not available.');
      return { buffer: await this.files.read(record.file, 'public'), file: record.file };
    });
  }
};
Object.defineProperty(module.exports, 'mediaIsPublic', { value: mediaIsPublic });
Object.defineProperty(module.exports, 'validMediaConsent', { value: validMediaConsent });
Object.defineProperty(module.exports, 'CHANNELS', { value: CHANNELS });
