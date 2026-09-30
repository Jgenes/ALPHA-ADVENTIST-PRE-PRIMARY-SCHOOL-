'use strict';
const crypto = require('node:crypto');
const L = require('../lib');
const A = require('./access');
const { audit } = require('./audit');
const W = require('./workflows');
const V = require('./validation');
const { HttpError } = L;
async function versionAllowed(tx, user, version) {
  if (!version || !A.canReadDocument(user, version)) return false;
  if (['ISSUED', 'SUPERSEDED', 'ARCHIVED'].includes(version.status) && version.publishedAt) return true;
  if (version.status === 'APPROVED' && A.has(user, 'document.publish')) return true;
  if (version.createdBy === user.id) return true;
  const flow = version.workflowId && await tx.get('workflow_instances', version.workflowId);
  return flow && flow.status === 'PENDING' && W.eligible(user, flow, version);
}
async function downloadable(tx, user, kind, id) {
  if (kind === 'document') {
    const version = await tx.get('document_versions', id);
    return await versionAllowed(tx, user, version) ? version : null;
  }
  if (kind === 'contract') {
    const contract = await tx.get('contracts', id);
    if (!contract) return null;
    const flow = contract.workflowId && await tx.get('workflow_instances', contract.workflowId);
    return A.canReadContract(user, contract, flow?.status === 'PENDING' && W.eligible(user, flow, contract)) ? contract : null;
  }
  if (kind === 'media') {
    const media = await tx.get('media', id);
    return media && ['media.create', 'media.review', 'media.publish'].some(permission => A.has(user, permission)) ? media : null;
  }
  if (kind === 'leave') {
    const leave = await tx.get('leave_requests', id);
    return leave && A.canReadLeave(user, leave) ? leave : null;
  }
  return null;
}
const documentView = (document, current, draft, versions) => ({ ...document, current: V.safeRecord(current), draft: V.safeRecord(draft), versions: versions.map(V.safeRecord) });
module.exports = {
  async documents(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'document.read');
      const out = [];
      for (const document of await tx.list('documents')) {
        const current = document.currentVersionId && await tx.get('document_versions', document.currentVersionId);
        const draft = document.draftVersionId && await tx.get('document_versions', document.draftVersionId);
        const visibleCurrent = current && await versionAllowed(tx, actor.user, current) ? current : null;
        const visibleDraft = draft && await versionAllowed(tx, actor.user, draft) ? draft : null;
        if (visibleCurrent || visibleDraft) {
          const versions = [];
          for (const item of await tx.list('document_versions')) if (item.documentId === document.id && ['SUPERSEDED', 'ARCHIVED', 'RETIRED_DRAFT'].includes(item.status) && await versionAllowed(tx, actor.user, item)) versions.push(item);
          out.push(documentView(document, visibleCurrent, visibleDraft, versions));
        }
      }
      await audit(tx, this.config, actor, 'document.library_read', 'document', 'authorised');
      return out;
    });
  },
  async createDocument(actor, body, documentId = '') {
    A.requirePermission(actor.user, 'document.create');
    const file = await this.files.prepare(body.file);
    try {
      return await this.run(actor, async (tx, actor) => {
        A.requirePermission(actor.user, 'document.create');
        let document;
        if (documentId) {
          document = await tx.get('documents', documentId);
          if (!document || !A.canReadDocument(actor.user, document) || document.ownerId !== actor.user.id && document.createdBy !== actor.user.id) throw new HttpError(403, 'Only the document owner or author may create a revision.');
          V.revision(document, body);
          const oldDraft = document.draftVersionId && await tx.get('document_versions', document.draftVersionId);
          if (oldDraft && ['UNDER_REVIEW', 'APPROVED'].includes(oldDraft.status)) throw new HttpError(409, 'Complete the current review before creating another version.');
          if (oldDraft) await tx.update('document_versions', { ...oldDraft, status: 'RETIRED_DRAFT' });
        } else {
          const classification = V.choose(body.classification, A.CLASSIFICATIONS, 'classification');
          if (classification === 'CONFIDENTIAL') A.requirePermission(actor.user, 'document.confidential');
          if (classification === 'HIGHLY_CONFIDENTIAL') A.requirePermission(actor.user, 'document.high_confidential');
          const number = L.text(body.documentNumber, 64, true);
          if (!/^[A-Z0-9-]{3,64}$/.test(number)) throw new HttpError(400, 'Use an uppercase document number, e.g. ALPHA-HR-POL-001.');
          if ((await tx.list('documents')).some(item => item.documentNumber === number)) throw new HttpError(409, 'Document number exists. Add a version to the existing document instead.');
          const access = await V.audience(tx, { type: 'SELECTED', userIds: [actor.user.id, ...V.strings(body.access?.userIds)], roles: V.strings(body.access?.roles), departmentIds: V.strings(body.access?.departmentIds) });
          const draft = { id: L.rid('DOC-'), documentNumber: number, title: L.text(body.title, 180, true), description: L.text(body.description, 2000), category: V.choose(body.category, ['Admissions', 'Parents', 'Academic', 'Careers', 'Public policies', 'HR', 'Administration', 'ICT', 'Child protection', 'Safety']), classification, ownerId: actor.user.id, departmentId: actor.user.departmentId || '', access, createdBy: actor.user.id, createdAt: new Date().toISOString(), currentVersionId: '', draftVersionId: '', lastVersion: 0, status: 'DRAFT' };
          if (classification === 'PUBLIC' && ['HR', 'Child protection'].includes(draft.category)) throw new HttpError(400, 'Use Public policies for explicitly approved public guidance. HR and child-protection records are private.');
          document = await tx.insert('documents', draft);
        }
        const version = await tx.insert('document_versions', { id: L.rid('VER-'), documentId: document.id, documentNumber: document.documentNumber, title: document.title, ownerId: document.ownerId, departmentId: document.departmentId, access: document.access, classification: document.classification, version: document.lastVersion + 1, file, effectiveDate: V.date(body.effectiveDate, true), reviewDate: V.date(body.reviewDate, true), expiryDate: V.date(body.expiryDate, true), changeSummary: L.text(body.changeSummary, 1000, true), createdBy: actor.user.id, createdAt: new Date().toISOString(), status: 'DRAFT' });
        const saved = await tx.update('documents', { ...document, lastVersion: version.version, draftVersionId: version.id });
        await audit(tx, this.config, actor, 'document.upload', 'document_version', version.id, 'success', { checksum: file.checksum, classification: document.classification, version: version.version });
        return documentView(saved, null, version, []);
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async documentAction(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      const version = await tx.get('document_versions', id);
      if (!version || !A.canReadDocument(actor.user, version)) throw new HttpError(403, 'This document is not available to you.');
      V.revision(version, body);
      const document = await tx.get('documents', version.documentId);
      if (body.action === 'SUBMIT') {
        A.requirePermission(actor.user, 'document.create');
        if (version.createdBy !== actor.user.id || !['DRAFT', 'RETURNED'].includes(version.status) || document.draftVersionId !== version.id) throw new HttpError(409, 'Only the current draft author can submit for review.');
        const definitionId = L.text(body.workflowId, 100) || 'document-standard';
        if ((await tx.get('workflow_definitions', definitionId))?.kind !== 'CONTROLLED_DOCUMENT') throw new HttpError(400, 'Choose a document workflow.');
        const flow = await W.startWorkflow(tx, actor, this.config, definitionId, version);
        return V.safeRecord(await tx.update('document_versions', { ...version, workflowId: flow.id, status: 'UNDER_REVIEW' }));
      }
      if (body.action === 'PUBLISH') {
        A.requirePermission(actor.user, 'document.publish');
        if (version.createdBy === actor.user.id || version.status !== 'APPROVED' || document.draftVersionId !== version.id) throw new HttpError(409, 'An independent publisher can issue only the approved current version.');
        const current = document.currentVersionId && await tx.get('document_versions', document.currentVersionId);
        if (current) await tx.update('document_versions', { ...current, status: 'SUPERSEDED' });
        const published = await tx.update('document_versions', { ...version, status: 'ISSUED', publishedAt: new Date().toISOString(), publishedBy: actor.user.id });
        await tx.update('documents', { ...document, currentVersionId: version.id, draftVersionId: '', status: 'ISSUED' });
        if (version.classification === 'PUBLIC') { await this.files.publish(version.file); if (current) await this.files.unpublish(current.file); }
        await audit(tx, this.config, actor, 'document.publish', 'document_version', id, 'success', { version: version.version, classification: version.classification });
        return V.safeRecord(published);
      }
      if (body.action === 'ARCHIVE') {
        A.requirePermission(actor.user, 'document.archive');
        if (!['ISSUED', 'SUPERSEDED'].includes(version.status)) throw new HttpError(409, 'Only an issued or superseded version can be archived.');
        const saved = await tx.update('document_versions', { ...version, status: 'ARCHIVED', archivedAt: new Date().toISOString() });
        if (document.currentVersionId === version.id) await tx.update('documents', { ...document, status: 'ARCHIVED' });
        await this.files.unpublish(version.file);
        await audit(tx, this.config, actor, 'document.archive', 'document_version', id);
        return V.safeRecord(saved);
      }
      throw new HttpError(400, 'Choose submit, publish or archive.');
    });
  },
  async publicDownloads() {
    return this.store.run(async tx => {
      const out = [];
      const today = new Date().toISOString().slice(0, 10);
      for (const document of await tx.list('documents')) {
        if (document.classification !== 'PUBLIC' || document.status !== 'ISSUED') continue;
        const version = await tx.get('document_versions', document.currentVersionId);
        if (!version || version.status !== 'ISSUED' || version.effectiveDate && version.effectiveDate > today || version.expiryDate && version.expiryDate < today) continue;
        out.push({ id: document.id, documentNumber: document.documentNumber, title: document.title, category: document.category, description: document.description, version: version.version, publishedAt: version.publishedAt, size: version.file.size, name: version.file.name });
      }
      return out;
    });
  },
  async publicDownload(id, actor) {
    return this.store.run(async tx => {
      const document = await tx.get('documents', id);
      if (!document || document.classification !== 'PUBLIC' || document.status !== 'ISSUED') throw new HttpError(404, 'Public download not found.');
      const version = await tx.get('document_versions', document.currentVersionId);
      const today = new Date().toISOString().slice(0, 10);
      if (!version || version.status !== 'ISSUED' || version.effectiveDate && version.effectiveDate > today || version.expiryDate && version.expiryDate < today) throw new HttpError(404, 'This download is not currently published.');
      const buffer = await this.files.read(version.file, 'public');
      await audit(tx, this.config, actor, 'document.public_download', 'document_version', version.id);
      return { buffer, file: version.file };
    });
  },
  async downloadGrant(actor, kind, id) {
    return this.run(actor, async (tx, actor) => {
      const record = await downloadable(tx, actor.user, kind, id);
      if (!record?.file) throw new HttpError(403, 'You are not authorised to download this file.');
      const token = crypto.randomBytes(32).toString('hex');
      await tx.insert('download_grants', { id: L.sha256(token), userId: actor.user.id, sessionId: actor.session.id, kind, resourceId: id, checksum: record.file.checksum, expiresAt: Date.now() + 60000 });
      await audit(tx, this.config, actor, 'document.download_grant', kind, id);
      return { url: '/api/downloads/' + token, expiresIn: 60 };
    });
  },
  async privateDownload(actor, token) {
    return this.run(actor, async (tx, actor) => {
      const grant = await tx.get('download_grants', L.sha256(token));
      if (!grant || grant.userId !== actor.user.id || grant.sessionId !== actor.session.id || grant.expiresAt <= Date.now()) throw new HttpError(403, 'This download link expired or belongs to another session.');
      const record = await downloadable(tx, actor.user, grant.kind, grant.resourceId);
      if (!record?.file || record.file.checksum !== grant.checksum) throw new HttpError(403, 'Access to this version is no longer authorised.');
      const buffer = await this.files.read(record.file);
      await tx.remove('download_grants', grant.id);
      await audit(tx, this.config, actor, 'document.download', grant.kind, record.id);
      return { buffer, file: record.file };
    });
  }
};
