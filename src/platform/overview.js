'use strict';
const L = require('../lib');
const A = require('./access');
const W = require('./workflows');
const V = require('./validation');
const { audit, verifyAudit } = require('./audit');
const { HttpError } = L;
module.exports = {
  async metadata(actor) {
    return this.run(actor, async (tx, actor) => ({ publicSettings: require('./school-details').applyDetails(require('../../data/seed.json').settings, (await tx.list('cms_content')).filter(item => item.published && item.status !== 'ARCHIVED').map(item => ({ kind: item.kind, ...item.published }))), departments: await tx.list('departments'), leaveTypes: A.has(actor.user, 'leave.create') ? await tx.list('leave_types') : [], roles: A.has(actor.user, 'role.grant') || A.has(actor.user, 'user.create') ? A.ROLE_LABELS : {}, classifications: A.CLASSIFICATIONS, environment: this.config.environment }));
  },
  async dashboard(actor) {
    return this.run(actor, async (tx, actor) => {
      const user = actor.user;
      const profile = A.has(user, 'staff.view_self') ? await tx.get('staff_profiles', user.id) : null;
      const year = new Date().getFullYear();
      const balances = (await tx.list('leave_balances')).filter(item => item.userId === user.id && item.year === year);
      const contracts = (await tx.list('contracts')).filter(record => record.ownerId === user.id && A.canReadContract(user, record));
      const current = contracts.sort((a, b) => b.createdAt.localeCompare(a.createdAt))[0];
      const receipts = await tx.list('notice_acknowledgements');
      const notices = (await tx.list('notices')).filter(record => record.status === 'PUBLISHED' && V.activeDate(record) && A.audienceAllows(user, record.audience));
      const notifications = (await tx.list('notifications')).filter(item => item.userId === user.id && !item.readAt);
      let pendingApprovals = 0;
      for (const instance of await tx.list('workflow_instances')) if (instance.status === 'PENDING') {
        const resource = await tx.get(instance.collection, instance.resourceId);
        if (resource && W.eligible(user, instance, resource)) pendingApprovals++;
      }
      const documents = (await tx.list('document_versions')).filter(record => record.status === 'ISSUED' && A.canReadDocument(user, record));
      const calendar = (await tx.list('calendar')).filter(record => record.date >= new Date().toISOString().slice(0, 10) && A.audienceAllows(user, record.audience)).sort((a, b) => a.date.localeCompare(b.date)).slice(0, 5);
      const annual = balances.find(item => item.typeId === 'annual');
      const output = {
        name: user.name, profile, annualLeave: annual ? annual.entitledDays - annual.usedDays - annual.reservedDays : null,
        balances, contractStatus: current?.status || 'No issued contract', contractEndDate: current?.endDate || null,
        notices: notices.length, unreadNotices: notices.filter(notice => !receipts.some(receipt => receipt.noticeId === notice.id && receipt.version === notice.version && receipt.userId === user.id)).length,
        acknowledgementsDue: notices.filter(notice => notice.acknowledgementRequired && !receipts.some(receipt => receipt.noticeId === notice.id && receipt.version === notice.version && receipt.userId === user.id && receipt.acknowledgedAt)).length,
        documents: documents.length, pendingApprovals, notifications: notifications.slice(-5).reverse(), upcoming: calendar
      };
      if (A.has(user, 'staff.view_all')) output.staffTotal = (await tx.list('staff_profiles')).length;
      if (A.has(user, 'system.read')) {
        output.accountsTotal = (await tx.list('users')).filter(item => item.active).length;
        output.alertsPending = (await tx.list('outbox')).filter(item => item.status !== 'DELIVERED').length;
        output.database = this.store.kind; output.officeAlertsConfigured = !!this.config.alertUrl;
      }
      return output;
    });
  },
  async requests(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'request.read');
      const result = [];
      for (const flow of await tx.list('workflow_instances')) {
        const record = await tx.get(flow.collection, flow.resourceId);
        if (!record) continue;
        let allowed = flow.requesterId === actor.user.id;
        if (flow.kind === 'LEAVE_REQUEST') allowed = A.canReadLeave(actor.user, record);
        if (flow.kind === 'EMPLOYMENT_CONTRACT') allowed = A.canReadContract(actor.user, record, flow.status === 'PENDING' && W.eligible(actor.user, flow, record));
        if (flow.kind === 'CONTROLLED_DOCUMENT') allowed = A.canReadDocument(actor.user, record) && (record.createdBy === actor.user.id || W.eligible(actor.user, flow, record));
        if (allowed) result.push({ ...flow, resource: V.safeRecord(record) });
      }
      await audit(tx, this.config, actor, 'request.read', 'workflow_instance', 'authorised');
      return result.reverse();
    });
  },
  async auditLog(actor, params = {}) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'audit.read');
      const all = await tx.list('audit_logs');
      const isScoped = actor.user.roles.includes('auditor') && !actor.user.roles.some(role => ['head_teacher', 'dpo'].includes(role));
      let records = all.filter(record => !isScoped || (actor.user.auditScopes || []).includes(record.resourceType));
      if (params.action) records = records.filter(record => record.action.includes(L.text(params.action, 100)));
      if (params.result) records = records.filter(record => record.result === params.result);
      const integrityValid = verifyAudit(all, this.config.auditKey, await tx.get('system_settings', 'audit-head'));
      await audit(tx, this.config, actor, 'audit.read', 'audit_log', 'authorised');
      return { integrityValid, total: records.length, records: records.slice(-200).reverse(), limitedToAssignedScopes: isScoped };
    });
  },
  async reports(actor) {
    return this.run(actor, async (tx, actor) => {
      const user = actor.user;
      if (!['report.read', 'report.hr', 'system.read', 'cms.publish'].some(permission => A.has(user, permission))) throw new HttpError(403, 'Reports are restricted.');
      const output = {};
      if (A.has(user, 'staff.view_all')) {
        const profiles = await tx.list('staff_profiles');
        const leave = await tx.list('leave_requests');
        const today = new Date().toISOString().slice(0, 10);
        output.hr = { staff: profiles.length, permanent: profiles.filter(item => item.employmentType === 'Permanent').length, contractStaff: profiles.filter(item => item.employmentType === 'Contract').length, onLeave: leave.filter(item => item.status === 'APPROVED' && item.startDate <= today && item.returnDate > today).length, pendingLeave: leave.filter(item => ['SUBMITTED', 'UNDER_REVIEW'].includes(item.status)).length, usedLeaveDays: (await tx.list('leave_balances')).reduce((sum, item) => sum + item.usedDays, 0) };
        if (A.has(user, 'contract.view_all')) output.hr.expiringContracts = (await tx.list('contracts')).filter(item => item.status === 'ACTIVE' && Date.parse(item.endDate) - Date.now() <= 90 * 86400000).map(V.safeRecord);
      }
      if (A.has(user, 'admission.read')) {
        const admissions = await tx.list('admissions');
        const enquiries = await tx.list('submissions');
        output.admissions = { applications: admissions.length, visitsRequested: enquiries.filter(item => item.kind === 'visit').length, enquiries: enquiries.length, enrolled: admissions.filter(item => item.status === 'ENROLLED').length, statuses: Object.fromEntries([...new Set(admissions.map(item => item.status))].map(status => [status, admissions.filter(item => item.status === status).length])) };
      }
      if (A.has(user, 'document.read')) {
        const versions = (await tx.list('document_versions')).filter(item => A.canReadDocument(user, item));
        const today = new Date().toISOString().slice(0, 10);
        output.documents = { issued: versions.filter(item => item.status === 'ISSUED').length, awaitingApproval: versions.filter(item => item.status === 'UNDER_REVIEW').length, reviewDue: versions.filter(item => item.status === 'ISSUED' && item.reviewDate && item.reviewDate <= today).length, expired: versions.filter(item => item.status === 'ISSUED' && item.expiryDate && item.expiryDate < today).length, superseded: versions.filter(item => item.status === 'SUPERSEDED').length };
      }
      if (['cms.review', 'cms.publish'].some(permission => A.has(user, permission))) {
        const content = await tx.list('cms_content');
        output.cms = { live: content.filter(item => item.published && item.status !== 'ARCHIVED').length, drafts: content.filter(item => item.status === 'DRAFT').length, awaitingApproval: content.filter(item => item.status === 'UNDER_REVIEW').length, scheduled: content.filter(item => item.status === 'SCHEDULED').length, archived: content.filter(item => item.status === 'ARCHIVED').length, analytics: 'No visitor tracking is enabled.' };
      }
      if (A.has(user, 'system.read')) {
        const logs = await tx.list('audit_logs');
        const safeguardingRecord = (await tx.list('cms_content')).find(item => item.kind === 'settings' && item.slug === 'school-contact' && item.published && item.status !== 'ARCHIVED');
        let safeguardingContactConfigured = false;
        if (safeguardingRecord) {
          try { safeguardingContactConfigured = Boolean(require('./school-details').validateDetails(safeguardingRecord.published.body).safeguardingName); } catch { /* Keep the status false if a legacy record is invalid. */ }
        }
        output.security = { failedSignIns: logs.filter(item => item.action === 'auth.login' && item.result === 'failure').length, lockouts: logs.filter(item => item.action === 'auth.locked').length, database: this.store.kind, externalAlertsConfigured: !!this.config.alertUrl, safeguardingContactConfigured };
      }
      await audit(tx, this.config, actor, 'report.read', 'report', 'authorised');
      return output;
    });
  },
  async systemStatus(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'system.read');
      return { database: this.store.kind, environment: this.config.environment, officeAlertsConfigured: !!this.config.alertUrl, outbox: (await tx.list('outbox')).map(({ id, status, attempts, nextAttemptAt, createdAt, deliveredAt, lastStatus }) => ({ id, status, attempts, nextAttemptAt, createdAt, deliveredAt, lastStatus })), maintenance: await tx.get('system_settings', 'maintenance') };
    });
  },
  async retryAlert(actor, id) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'system.read');
      const record = await tx.get('outbox', id);
      if (!record || !['FAILED', 'PENDING', 'RETRY'].includes(record.status)) throw new HttpError(409, 'Only a queued or failed alert can be retried.');
      await tx.update('outbox', { ...record, status: 'PENDING', attempts: 0, nextAttemptAt: Date.now() });
      await audit(tx, this.config, actor, 'notification.retry', 'outbox', id);
      return { ok: true };
    });
  },
  async search(actor, params) {
    const query = L.text(params.q, 150).toLowerCase();
    if (query.length < 2) return [];
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'search');
      const results = [];
      const add = (type, record, title, summary, url) => results.push({ type, id: record.id, title, summary, url, classification: record.classification || 'INTERNAL', category: record.category || '', departmentId: record.departmentId || '', ownerId: record.ownerId || record.createdBy || '', version: record.version || 1, status: record.status || '', year: (record.createdAt || '').slice(0, 4) });
      for (const record of await tx.list('document_versions')) if (record.status === 'ISSUED' && A.canReadDocument(actor.user, record)) add('Document', record, record.title, `${record.documentNumber} · version ${record.version}`, '/portal/documents');
      for (const record of await tx.list('notices')) if (record.status === 'PUBLISHED' && V.activeDate(record) && A.audienceAllows(actor.user, record.audience)) add('Notice', record, record.title, record.message.slice(0, 180), '/portal/notices');
      for (const record of await tx.list('calendar')) if (A.audienceAllows(actor.user, record.audience)) add('Event', record, record.title, record.date, '/portal/calendar');
      for (const record of await tx.list('cms_content')) if (record.published && record.status !== 'ARCHIVED' && record.kind !== 'settings') add('News / page', { id: record.id, ...record.published, status: 'PUBLISHED', classification: 'PUBLIC' }, record.published.title, record.published.excerpt, record.kind === 'news' ? '/news/' + record.slug : '/pages/' + record.slug);
      for (const profile of await tx.list('staff_profiles')) if (A.canReadStaff(actor.user, profile)) add('Staff', profile, profile.name, profile.position, profile.id === actor.user.id ? '/portal/profile' : '/portal/staff');
      for (const contract of await tx.list('contracts')) {
        const instance = contract.workflowId && await tx.get('workflow_instances', contract.workflowId);
        if (A.canReadContract(actor.user, contract, instance?.status === 'PENDING' && W.eligible(actor.user, instance, contract))) add('Contract', contract, contract.title, contract.reference, '/portal/contracts');
      }
      await audit(tx, this.config, actor, 'search.read', 'search', 'authorised');
      return results.filter(item => `${item.title} ${item.summary}`.toLowerCase().includes(query) && ['category', 'classification', 'departmentId', 'ownerId', 'version', 'status', 'year', 'type'].every(key => !params[key] || String(item[key]) === params[key])).slice(0, 50);
    });
  }
};
