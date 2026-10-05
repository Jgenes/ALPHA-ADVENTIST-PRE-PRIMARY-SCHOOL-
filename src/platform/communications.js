'use strict';
const crypto = require('node:crypto');
const L = require('../lib');
const A = require('./access');
const { audit } = require('./audit');
const { limit } = require('./auth');
const W = require('./workflows');
const V = require('./validation');
const seed = require('../../data/seed.json');
const { HttpError } = L;
const FORM_RULES = {
  contact: { type: 'Contact enquiry', fields: ['name', 'phone', 'email', 'topic', 'message'], required: ['name', 'phone', 'message'] },
  visit: { type: 'School visit request', fields: ['name', 'phone', 'email', 'preferred_day', 'level', 'message'], required: ['name', 'phone'] },
  apply: { type: 'Admission application', fields: ['child_name', 'guardian_name', 'phone', 'email', 'level', 'arrangement', 'message'], required: ['child_name', 'guardian_name', 'phone', 'level', 'arrangement'] },
  'computer-class': { type: 'Computer class enquiry', fields: ['name', 'phone', 'email', 'level', 'class', 'message'], required: ['name', 'phone'] },
  'computer-interest': { type: 'Community training interest', fields: ['name', 'phone', 'email', 'course', 'interest', 'category', 'message'], required: ['name', 'phone'] },
  safeguarding: { type: 'Safeguarding concern', fields: ['name', 'phone', 'message'], required: ['message'], private: true },
  'privacy-request': { type: 'Privacy request', fields: ['name', 'phone', 'email', 'request_type', 'message'], required: ['name', 'message'], private: true }
};
const ADMISSION_TRANSITIONS = {
  DRAFT: ['SUBMITTED'],
  SUBMITTED: ['DOCUMENTS_REQUIRED', 'UNDER_REVIEW'], DOCUMENTS_REQUIRED: ['UNDER_REVIEW', 'DECLINED'],
  UNDER_REVIEW: ['DOCUMENTS_REQUIRED', 'ASSESSMENT', 'ACCEPTED', 'WAITLISTED', 'DECLINED'],
  ASSESSMENT: ['DOCUMENTS_REQUIRED', 'ACCEPTED', 'WAITLISTED', 'DECLINED'], ACCEPTED: ['ENROLLED', 'DECLINED'],
  WAITLISTED: ['UNDER_REVIEW', 'ACCEPTED', 'DECLINED'], DECLINED: [], ENROLLED: []
};
async function queueAlert(tx, reference, type, privateRequest = false, application = false) {
  const href = privateRequest ? '/portal/privacy' : application ? '/portal/admissions' : '/portal/enquiries';
  for (const user of await tx.list('users')) if (A.has(user, privateRequest ? 'privacy.manage' : application ? 'admission.read' : 'submission.read')) await W.notify(tx, user.id, `${type}: ${reference}`, href, reference);
  await tx.insert('outbox', { id: L.rid('ALERT-'), reference, type, href, status: 'PENDING', attempts: 0, nextAttemptAt: Date.now(), createdAt: new Date().toISOString() });
}
function cmsFields(body) {
  const kind = V.choose(body.kind, ['news', 'page', 'event', 'vacancy', 'faq', 'banner', 'settings']);
  const title = L.text(body.title, 180, true);
  const slug = L.slugify(L.text(body.slug, 100) || title);
  if (!slug) throw new HttpError(400, 'Use a readable URL slug.');
  const language = V.choose(body.language || 'en', ['en', 'sw']);
  const settingsBody = kind === 'settings' ? JSON.stringify(require('./school-details').validateDetails(body.body)) : null;
  if (kind === 'settings' && (slug !== 'school-contact' || language !== 'en')) throw new HttpError(400, 'School details use the school-contact record in English.');
  const eventDate = kind === 'event' ? V.date(body.eventDate) : '';
  return { kind, title, slug, language, body: settingsBody || L.text(body.body, 20000, true), excerpt: L.text(body.excerpt, 400, true), category: L.text(body.category, 100) || 'School News', seoTitle: L.text(body.seoTitle, 70), seoDescription: L.text(body.seoDescription, 170), mediaId: L.text(body.mediaId, 100), eventDate };
}
function noticeFields(body) {
  return { title: L.text(body.title, 180, true), message: L.text(body.message, 8000, true), category: V.choose(body.category, ['General', 'Academic', 'HR', 'Administration', 'ICT', 'Meetings', 'Training', 'Events', 'Emergency', 'Deadlines']), priority: V.choose(body.priority, ['Urgent', 'Important', 'Information', 'General']), expiresAt: V.date(body.expiresAt, true), acknowledgementRequired: body.acknowledgementRequired === true };
}
module.exports = {
  async submitPublic(actor, kind, body) {
    const rule = Object.hasOwn(FORM_RULES, kind) ? FORM_RULES[kind] : null;
    if (!rule) throw new HttpError(404, 'Form not found.');
    if (body.website_url) return { ok: true, message: 'Thank you. Please call the school if you need help.' };
    if (!['on', 'yes', 'true', true].includes(body.privacy_consent)) throw new HttpError(400, 'Please read and accept the privacy notice before submitting.');
    const data = {};
    for (const field of rule.fields) data[field] = L.text(body[field], field === 'message' ? 2000 : 160, rule.required.includes(field));
    if (kind === 'privacy-request' && !data.phone && !data.email) throw new HttpError(400, 'Provide a safe phone number or email for private follow-up.');
    if (data.phone && !/^\+?[0-9 ()-]{7,24}$/.test(data.phone)) throw new HttpError(400, 'Enter a valid telephone number.');
    if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) throw new HttpError(400, 'Enter a valid email address.');
    if (kind === 'apply' && (![...seed.academics.prePrimary, ...seed.academics.primary].includes(data.level) || !['Day', 'Boarding'].includes(data.arrangement))) throw new HttpError(400, 'Select a valid class and day or boarding arrangement.');
    const inputKey = L.text(body.submissionKey, 80);
    const key = inputKey ? 'submission-key:' + L.sha256(actor.ip + ':' + kind + ':' + inputKey) : null;
    const result = await this.store.run(async tx => {
      if (key) {
        const saved = await tx.get('system_settings', key);
        if (saved && saved.expiresAt > Date.now()) return { reference: saved.reference };
      }
      if (!await limit(tx, 'form:' + actor.ip, 8, 60 * 60 * 1000)) return { error: new HttpError(429, 'Too many submissions. Please wait or call the school office.') };
      const reference = `ALPHA-${new Date().getFullYear()}-${crypto.randomBytes(6).toString('hex').toUpperCase()}`;
      const record = { id: reference, reference, type: rule.type, kind, data, status: kind === 'apply' ? 'SUBMITTED' : 'NEW', consent: { purpose: kind === 'apply' ? 'admissions_review' : rule.private ? 'private_request_triage' : 'respond_to_enquiry', policyVersion: this.config.policyVersion, acceptedAt: new Date().toISOString() }, createdAt: new Date().toISOString(), expiresAt: Date.now() + (kind === 'apply' ? this.config.admissionRetentionDays : this.config.formRetentionDays) * 86400000, history: [] };
      const collection = kind === 'apply' ? 'admissions' : rule.private ? 'privacy_requests' : 'submissions';
      await tx.insert(collection, record);
      await queueAlert(tx, reference, rule.type, rule.private, kind === 'apply');
      if (key) {
        const existing = await tx.get('system_settings', key);
        const receipt = { id: key, reference, expiresAt: Date.now() + 86400000 };
        if (existing) await tx.update('system_settings', { ...existing, ...receipt }); else await tx.insert('system_settings', receipt);
      }
      await audit(tx, this.config, actor, 'public_form.submit', collection, reference, 'success', { kind, policyVersion: this.config.policyVersion });
      return { reference };
    });
    if (result.error) throw result.error;
    return { ok: true, reference: result.reference, message: body.language === 'sw' ? 'Tumepokea ombi lako. Hifadhi namba hii ya kumbukumbu; ofisi itawasiliana nawe.' : 'Your request is saved in the school office queue. Keep your reference number; the authorised team will follow up.' };
  },
  async enquiries(actor, admissions = false) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, admissions ? 'admission.read' : 'submission.read');
      const collection = admissions ? 'admissions' : 'submissions';
      await audit(tx, this.config, actor, admissions ? 'admission.read' : 'submission.read', collection, 'authorised');
      return (await tx.list(collection)).reverse();
    });
  },
  async changeEnquiry(actor, id, body, admissions = false) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, admissions ? 'admission.manage' : 'submission.manage');
      const collection = admissions ? 'admissions' : 'submissions';
      const record = await tx.get(collection, id);
      if (!record) throw new HttpError(404, 'Reference not found.');
      V.revision(record, body);
      const next = L.text(body.status, 40, true);
      if (admissions ? !ADMISSION_TRANSITIONS[record.status]?.includes(next) : !{ NEW: ['IN_PROGRESS', 'CLOSED'], IN_PROGRESS: ['CLOSED'], CLOSED: ['IN_PROGRESS'] }[record.status]?.includes(next)) throw new HttpError(409, 'This status transition is not allowed.');
      const from = record.status;
      const note = L.text(body.comment, 2000, admissions && ['DECLINED', 'DOCUMENTS_REQUIRED'].includes(next));
      record.history.push({ from, to: next, note, by: actor.user.id, at: new Date().toISOString() });
      record.status = next; record.updatedAt = new Date().toISOString();
      const saved = await tx.update(collection, record);
      if (admissions && record.applicantUserId) await W.notify(tx, record.applicantUserId, `Admission application ${record.reference}: ${next.replace(/_/g, ' ').toLowerCase()}`, '/portal/family', record.id);
      await audit(tx, this.config, actor, admissions ? 'admission.status_change' : 'submission.status_change', collection, id, 'success', { from, to: next });
      return saved;
    });
  },
  async cms(actor) {
    return this.run(actor, async (tx, actor) => {
      if (!['cms.create', 'cms.review', 'cms.approve', 'cms.publish'].some(permission => A.has(actor.user, permission))) throw new HttpError(403, 'The content workspace is restricted.');
      return (await tx.list('cms_content')).filter(record => record.createdBy === actor.user.id || ['cms.edit_any', 'cms.review', 'cms.approve', 'cms.publish'].some(permission => A.has(actor.user, permission)));
    });
  },
  async saveContent(actor, body, id = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'cms.create');
      const fields = cmsFields(body);
      if ((await tx.list('cms_content')).some(item => item.id !== id && item.kind === fields.kind && item.slug === fields.slug)) throw new HttpError(409, 'This URL slug is already in use for that content type.');
      let record;
      if (id) {
        record = await tx.get('cms_content', id);
        if (!record || record.createdBy !== actor.user.id && !A.has(actor.user, 'cms.edit_any')) throw new HttpError(403, 'This draft is not available for editing.');
        V.revision(record, body);
        if (!['DRAFT', 'RETURNED', 'REJECTED', 'PUBLISHED'].includes(record.status)) throw new HttpError(409, 'Reviewed, scheduled or archived content cannot be overwritten.');
        if (record.kind !== fields.kind || record.slug !== fields.slug) throw new HttpError(400, 'Keep the content type and permanent URL when revising a published item.');
        const authors = record.status === 'PUBLISHED' ? [actor.user.id] : [...new Set([...(record.revisionAuthors || [record.createdBy]), actor.user.id])];
        record = await tx.update('cms_content', { ...record, ...fields, version: record.version + 1, status: 'DRAFT', revisionAuthors: authors, workflowId: '', approvedBy: '', approvedAt: '', publishAt: null });
      } else record = await tx.insert('cms_content', { id: L.rid('CMS-'), ...fields, status: 'DRAFT', version: 1, createdBy: actor.user.id, revisionAuthors: [actor.user.id], createdAt: new Date().toISOString(), published: null, history: [] });
      await audit(tx, this.config, actor, id ? 'cms.edit' : 'cms.create', 'cms_content', record.id, 'success', { version: record.version });
      return record;
    });
  },
  async contentAction(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      const record = await tx.get('cms_content', id);
      if (!record) throw new HttpError(404, 'Content not found.');
      V.revision(record, body);
      if (body.action === 'SUBMIT') {
        A.requirePermission(actor.user, 'cms.create');
        if (record.createdBy !== actor.user.id && !A.has(actor.user, 'cms.edit_any')) throw new HttpError(403, 'This draft is not yours.');
        if (!['DRAFT', 'RETURNED'].includes(record.status)) throw new HttpError(409, 'Only a draft or returned item can be submitted.');
        const flow = await W.startWorkflow(tx, actor, this.config, 'cms-standard', record);
        return tx.update('cms_content', { ...record, workflowId: flow.id, workflowIds: [...(record.workflowIds || []), flow.id], status: 'UNDER_REVIEW' });
      }
      if (body.action === 'PUBLISH') {
        A.requirePermission(actor.user, 'cms.publish');
        const flow = record.workflowId && await tx.get('workflow_instances', record.workflowId);
        if (record.status !== 'APPROVED' || flow?.status !== 'APPROVED' || record.createdBy === actor.user.id || (record.revisionAuthors || []).includes(actor.user.id)) throw new HttpError(409, 'Only independently approved content can be published by a separate publisher.');
        if (record.mediaId && !await require('./privacy').mediaIsPublic(tx, record.mediaId)) throw new HttpError(409, 'The selected media has not passed approval and current consent checks.');
        const publishAt = body.publishAt ? new Date(body.publishAt) : new Date();
        if (!Number.isFinite(publishAt.getTime())) throw new HttpError(400, 'Use a valid publication date and time.');
        record.publishAt = publishAt.toISOString(); record.publishedBy = actor.user.id;
        if (publishAt.getTime() > Date.now()) record.status = 'SCHEDULED';
        else {
          if (record.published) record.history.push(record.published);
          record.published = { ...cmsFields(record), content: record.body.split(/\n\s*\n/), publishedAt: record.publishAt, version: record.version };
          record.status = 'PUBLISHED';
        }
        const saved = await tx.update('cms_content', record);
        await audit(tx, this.config, actor, record.status === 'SCHEDULED' ? 'cms.schedule' : 'cms.publish', 'cms_content', id, 'success', { version: record.version });
        return saved;
      }
      if (body.action === 'ARCHIVE') {
        A.requirePermission(actor.user, 'cms.archive');
        const saved = await tx.update('cms_content', { ...record, status: 'ARCHIVED', archivedAt: new Date().toISOString() });
        await audit(tx, this.config, actor, 'cms.archive', 'cms_content', id);
        return saved;
      }
      throw new HttpError(400, 'Choose submit, publish or archive.');
    });
  },
  async publicContent() {
    return this.store.run(async tx => {
      const out = [];
      for (const record of await tx.list('cms_content')) {
        if (!record.published || record.status === 'ARCHIVED') continue;
        const published = { id: record.id, kind: record.kind, ...record.published };
        if (published.mediaId && !await require('./privacy').mediaIsPublic(tx, published.mediaId)) published.mediaId = '';
        out.push(published);
      }
      return out;
    });
  },
  async notices(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notice.read');
      const acknowledgements = await tx.list('notice_acknowledgements');
      return (await tx.list('notices')).filter(record => record.createdBy === actor.user.id || A.has(actor.user, 'notice.publish') || record.status === 'PUBLISHED' && V.activeDate(record) && A.audienceAllows(actor.user, record.audience)).map(record => ({ ...record, acknowledgement: acknowledgements.find(ack => ack.noticeId === record.id && ack.version === record.version && ack.userId === actor.user.id) || null }));
    });
  },
  async saveNotice(actor, body, id = '') {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notice.create');
      const fields = noticeFields(body);
      fields.audience = await V.audience(tx, body.audience);
      let record;
      if (id) {
        const old = await tx.get('notices', id);
        if (!old || old.createdBy !== actor.user.id) throw new HttpError(403, 'Only the notice author can revise it.');
        V.revision(old, body);
        record = await tx.update('notices', { ...old, ...fields, version: old.version + 1, status: 'DRAFT', history: [...(old.history || []), { title: old.title, message: old.message, audience: old.audience, version: old.version, publishedAt: old.publishedAt || null }] });
      } else record = await tx.insert('notices', { id: L.rid('NTC-'), ...fields, version: 1, status: 'DRAFT', createdBy: actor.user.id, createdAt: new Date().toISOString() });
      await audit(tx, this.config, actor, id ? 'notice.edit' : 'notice.create', 'notice', record.id);
      return record;
    });
  },
  async publishNotice(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notice.publish');
      const record = await tx.get('notices', id);
      if (!record) throw new HttpError(404, 'Notice not found.');
      V.revision(record, body);
      if (record.status !== 'DRAFT' || record.createdBy === actor.user.id) throw new HttpError(409, 'A separate publisher must review and issue this draft.');
      if (record.expiresAt && record.expiresAt < new Date().toISOString().slice(0, 10)) throw new HttpError(400, 'This notice has already expired.');
      const saved = await tx.update('notices', { ...record, status: 'PUBLISHED', publishedBy: actor.user.id, publishedAt: new Date().toISOString() });
      for (const user of await tx.list('users')) if (A.audienceAllows(user, record.audience)) await W.notify(tx, user.id, record.acknowledgementRequired ? 'A staff notice requires your acknowledgement' : 'A new staff notice is available', '/portal/notices', id);
      await audit(tx, this.config, actor, 'notice.publish', 'notice', id, 'success', { version: record.version });
      return saved;
    });
  },
  async acknowledgeNotice(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notice.read');
      const notice = await tx.get('notices', id);
      if (!notice || notice.status !== 'PUBLISHED' || !V.activeDate(notice) || !A.audienceAllows(actor.user, notice.audience)) throw new HttpError(403, 'This notice is not addressed to you or is no longer current.');
      if (Number(body.version) !== notice.version) throw new HttpError(409, 'The notice has changed. Read the current version first.');
      const ackId = `${id}:${notice.version}:${actor.user.id}`;
      const existing = await tx.get('notice_acknowledgements', ackId);
      const now = new Date().toISOString();
      const staff = await tx.get('staff_profiles', actor.user.id);
      const value = { ...(existing || {}), id: ackId, noticeId: id, version: notice.version, userId: actor.user.id, staffId: staff?.staffId || '', readAt: existing?.readAt || now, acknowledgedAt: body.acknowledge === true && notice.acknowledgementRequired ? existing?.acknowledgedAt || now : existing?.acknowledgedAt || null, session: L.sha256(actor.session.id).slice(0, 20) };
      const saved = existing ? await tx.update('notice_acknowledgements', value) : await tx.insert('notice_acknowledgements', value);
      await audit(tx, this.config, actor, body.acknowledge ? 'notice.acknowledge' : 'notice.read', 'notice', id, 'success', { version: notice.version });
      return saved;
    });
  },
  async noticeReport(actor, id) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notice.publish');
      const notice = await tx.get('notices', id);
      if (!notice) throw new HttpError(404, 'Notice not found.');
      const receipts = await tx.list('notice_acknowledgements');
      const people = (await tx.list('users')).filter(user => user.active && A.audienceAllows(user, notice.audience)).map(user => ({ userId: user.id, name: user.name, acknowledgement: receipts.find(item => item.userId === user.id && item.noticeId === id && item.version === notice.version) || null }));
      const acknowledged = people.filter(person => person.acknowledgement?.acknowledgedAt).length;
      await audit(tx, this.config, actor, 'notice.acknowledgement_report', 'notice', id);
      return { noticeId: id, version: notice.version, total: people.length, acknowledged, percentage: people.length ? Math.round(acknowledged / people.length * 100) : 0, people };
    });
  },
  async calendar(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'calendar.read');
      return (await tx.list('calendar')).filter(record => A.audienceAllows(actor.user, record.audience) || record.createdBy === actor.user.id);
    });
  },
  async createEvent(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'calendar.manage');
      const record = { id: L.rid('EVT-'), title: L.text(body.title, 180, true), description: L.text(body.description, 2000), date: V.date(body.date), category: V.choose(body.category, ['School', 'Meeting', 'Training', 'Examination', 'Worship', 'Deadline', 'Holiday', 'Sports']), audience: await V.audience(tx, body.audience), createdBy: actor.user.id, createdAt: new Date().toISOString() };
      const saved = await tx.insert('calendar', record);
      await audit(tx, this.config, actor, 'calendar.create', 'calendar_event', record.id);
      return saved;
    });
  },
  async notifications(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notification.read');
      return (await tx.list('notifications')).filter(item => item.userId === actor.user.id).reverse().slice(0, 100);
    });
  },
  async readNotification(actor, id) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'notification.read');
      const record = await tx.get('notifications', id);
      if (!record || record.userId !== actor.user.id) throw new HttpError(403, 'Notification is not available.');
      return tx.update('notifications', { ...record, readAt: new Date().toISOString() });
    });
  }
};
// Constants are exported separately from the service-method mixin.
Object.defineProperty(module.exports, 'FORM_RULES', { value: FORM_RULES });
Object.defineProperty(module.exports, 'ADMISSION_TRANSITIONS', { value: ADMISSION_TRANSITIONS });
