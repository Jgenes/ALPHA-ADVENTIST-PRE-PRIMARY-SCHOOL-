'use strict';
const crypto = require('node:crypto');
const L = require('../lib');
const A = require('./access');
const V = require('./validation');
const W = require('./workflows');
const { audit } = require('./audit');
const { HttpError } = L;

function applicationData(user, body) {
  const childName = L.text(body.childName, 160, true);
  const level = L.text(body.level, 40, true);
  const arrangement = V.choose(body.arrangement, ['Day', 'Boarding']);
  const validLevels = [...require('../../data/seed.json').academics.prePrimary, ...require('../../data/seed.json').academics.primary];
  if (!validLevels.includes(level)) throw new HttpError(400, 'Select a current school level.');
  const guardianPhone = L.text(body.guardianPhone, 30, true);
  if (!/^\+?[0-9 ()-]{7,24}$/.test(guardianPhone)) throw new HttpError(400, 'Enter a valid guardian telephone number.');
  const guardianEmail = L.text(body.guardianEmail, 160);
  if (guardianEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guardianEmail)) throw new HttpError(400, 'Enter a valid guardian email address.');
  return { child_name: childName, guardian_name: user.name, phone: guardianPhone, email: guardianEmail, level, arrangement, message: L.text(body.message, 2000), previous_school: L.text(body.previousSchool, 160) };
}
function parentApplication(user, record, documents = []) {
  return {
    id: record.id,
    reference: record.reference,
    status: record.status,
    data: { child_name: record.data.child_name, guardian_name: record.data.guardian_name, level: record.data.level, arrangement: record.data.arrangement, previous_school: record.data.previous_school || '' },
    documents: documents.map(item => ({ id: item.id, documentType: item.documentType, title: item.title, file: require('./files').publicFileMetadata(item.file), uploadedAt: item.createdAt })),
    history: (record.history || []).map(item => ({ from: item.from, to: item.to, note: item.by === user.id ? item.note : '', at: item.at })),
    createdAt: record.createdAt,
    updatedAt: record.updatedAt || record.createdAt,
    revision: record.revision
  };
}
module.exports = {
  async myAdmissions(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'admission.read_self');
      const records = (await tx.list('admissions')).filter(item => item.applicantUserId === actor.user.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
      const output = [];
      for (const record of records) {
        const documents = (await tx.list('admission_documents')).filter(item => item.applicationId === record.id);
        output.push(parentApplication(actor.user, record, documents));
      }
      return output;
    });
  },
  async createMyAdmission(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'admission.submit');
      if (!actor.user.roles.includes('parent')) throw new HttpError(403, 'Only a parent or guardian account can submit an application.');
      const data = applicationData(actor.user, body);
      if (body.privacyConsent !== true) throw new HttpError(400, 'Confirm the privacy notice before saving this application.');
      const id = `ALPHA-${new Date().getFullYear()}-${crypto.randomBytes(6).toString('hex').toUpperCase()}`;
      const now = new Date().toISOString();
      const record = await tx.insert('admissions', { id, reference: id, type: 'Admission application', kind: 'apply', source: 'authenticated_parent', applicantUserId: actor.user.id, data, status: 'DRAFT', consent: { purpose: 'admissions_review', policyVersion: this.config.policyVersion, acceptedAt: now }, createdAt: now, expiresAt: Date.now() + this.config.admissionRetentionDays * 86400000, history: [{ from: '', to: 'DRAFT', note: '', by: actor.user.id, at: now }], documentIds: [] });
      await audit(tx, this.config, actor, 'admission.application_create', 'admissions', id);
      return parentApplication(actor.user, record);
    });
  },
  async updateMyAdmission(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'admission.submit');
      const record = await tx.get('admissions', id);
      if (!record || record.applicantUserId !== actor.user.id) throw new HttpError(404, 'Application not found.');
      V.revision(record, body);
      if (!['DRAFT', 'DOCUMENTS_REQUIRED'].includes(record.status)) throw new HttpError(409, 'Only a draft or returned application can be edited.');
      const updated = await tx.update('admissions', { ...record, data: applicationData(actor.user, body), updatedAt: new Date().toISOString() });
      await audit(tx, this.config, actor, 'admission.application_update', 'admissions', id);
      return parentApplication(actor.user, updated, (await tx.list('admission_documents')).filter(item => item.applicationId === id));
    });
  },
  async uploadAdmissionDocument(actor, id, body) {
    A.requirePermission(actor.user, 'admission.submit');
    const file = await this.files.prepare(body.file);
    try {
      return await this.run(actor, async (tx, actor) => {
        const record = await tx.get('admissions', id);
        if (!record || record.applicantUserId !== actor.user.id) throw new HttpError(404, 'Application not found.');
        if (!['DRAFT', 'DOCUMENTS_REQUIRED'].includes(record.status)) throw new HttpError(409, 'Application documents can only be changed while a draft or correction is open.');
        const document = await tx.insert('admission_documents', { id: L.rid('ADOC-'), applicationId: id, ownerId: actor.user.id, documentType: L.text(body.documentType, 60, true), title: L.text(body.title, 140, true), file, status: 'SUBMITTED', createdAt: new Date().toISOString() });
        await tx.update('admissions', { ...record, documentIds: [...new Set([...(record.documentIds || []), document.id])], updatedAt: new Date().toISOString() });
        await audit(tx, this.config, actor, 'admission.document_upload', 'admission_document', document.id, 'success', { checksum: file.checksum });
        return { id: document.id, documentType: document.documentType, title: document.title, file: require('./files').publicFileMetadata(file), revision: document.revision };
      });
    } catch (error) { await this.files.discard(file); throw error; }
  },
  async submitMyAdmission(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'admission.submit');
      const record = await tx.get('admissions', id);
      if (!record || record.applicantUserId !== actor.user.id) throw new HttpError(404, 'Application not found.');
      V.revision(record, body);
      if (!['DRAFT', 'DOCUMENTS_REQUIRED'].includes(record.status)) throw new HttpError(409, 'This application is not ready for submission.');
      const now = new Date().toISOString();
      const saved = await tx.update('admissions', { ...record, status: 'SUBMITTED', submittedAt: now, updatedAt: now, history: [...(record.history || []), { from: record.status, to: 'SUBMITTED', note: '', by: actor.user.id, at: now }] });
      for (const user of await tx.list('users')) if (user.active && A.has(user, 'admission.read')) await W.notify(tx, user.id, `New admission application: ${saved.reference}`, '/portal/admissions', saved.id);
      await audit(tx, this.config, actor, 'admission.application_submit', 'admissions', id);
      return parentApplication(actor.user, saved, (await tx.list('admission_documents')).filter(item => item.applicationId === id));
    });
  },
  async admissionDocumentGrant(actor, id) {
    return this.run(actor, async (tx, actor) => {
      const document = await tx.get('admission_documents', id);
      const application = document && await tx.get('admissions', document.applicationId);
      const allowed = application && (application.applicantUserId === actor.user.id && A.has(actor.user, 'admission.read_self') || A.has(actor.user, 'admission.read'));
      if (!allowed) throw new HttpError(403, 'You are not authorised to access this application document.');
      const token = crypto.randomBytes(32).toString('hex');
      await tx.insert('download_grants', { id: L.sha256(token), userId: actor.user.id, sessionId: actor.session.id, kind: 'admission_document', resourceId: id, checksum: document.file.checksum, expiresAt: Date.now() + 60000 });
      await audit(tx, this.config, actor, 'admission.document_download_grant', 'admission_document', id);
      return { url: '/api/downloads/' + token, expiresIn: 60 };
    });
  }
};