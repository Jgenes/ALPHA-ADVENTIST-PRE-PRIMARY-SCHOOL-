'use strict';
const L = require('../lib');
const A = require('./access');
const { audit } = require('./audit');
const W = require('./workflows');
const { requireReady, createUserRecord, liveActor } = require('./auth');
const seed = require('../../data/seed.json');
const { HttpError } = L;

const { revision, safeRecord } = require('./validation');
class Platform {
  constructor(store, config, files) { this.store = store; this.config = config; this.files = files; }
  async init() {
    await this.files.init();
    await this.store.run(async tx => {
      for (const definition of W.DEFAULT_WORKFLOWS) if (!await tx.get('workflow_definitions', definition.id)) await tx.insert('workflow_definitions', { ...definition, version: 1 });
      for (const [id, name] of [['pre-primary', 'Pre-Primary'], ['primary', 'Primary'], ['administration', 'Administration'], ['hr', 'Human Resources'], ['finance', 'Finance'], ['ict', 'ICT'], ['support', 'Supporting Services']]) if (!await tx.get('departments', id)) await tx.insert('departments', { id, name });
      for (const id of ['annual', 'sick', 'maternity', 'paternity', 'compassionate', 'study', 'emergency', 'other']) if (!await tx.get('leave_types', id)) await tx.insert('leave_types', { id, name: id[0].toUpperCase() + id.slice(1), workflowId: 'leave-standard', deductsBalance: id === 'annual', nonWorkingDays: [0, 6], enabled: true });
      if (!await tx.get('system_settings', 'initialised')) {
        // Existing publicly released text is preserved, but all old photographs
        // are quarantined/removed. Subsequent edits must follow the new workflow.
        for (const news of seed.news) await tx.insert('cms_content', {
          id: L.rid('CMS-'), kind: 'news', slug: news.slug, title: news.title, excerpt: news.excerpt,
          body: news.content.join('\n\n'), category: news.category, language: 'en', version: 1,
          createdBy: 'legacy-public-site', status: 'PUBLISHED', legacyPublication: true,
          published: { ...news, body: news.content.join('\n\n'), version: 1, publishedAt: news.sortDate, language: 'en', mediaId: '' }, history: []
        });
        await tx.insert('system_settings', { id: 'initialised', schemaVersion: 1, at: new Date().toISOString() });
        await audit(tx, this.config, { userId: 'system' }, 'platform.initialise', 'system', 'adsp-v1');
      }
    });
    if (this.config.adminPassword) {
      const exists = await this.store.run(async tx => (await tx.list('users')).some(user => user.roles.includes('system_admin')));
      if (!exists) {
        const user = await createUserRecord(this.config.adminUsername.toLowerCase(), this.config.adminName, ['system_admin'], this.config.adminPassword);
        await this.store.run(async tx => {
          if ((await tx.list('users')).some(item => item.roles.includes('system_admin'))) return;
          await tx.insert('users', user);
          await audit(tx, this.config, { userId: 'bootstrap' }, 'user.create', 'user', user.id, 'success', { roles: user.roles });
        });
      }
    }
    const headTeacherConfig = this.config;
    const hasHeadTeacherBootstrap = headTeacherConfig.headTeacherUsername || headTeacherConfig.headTeacherName || headTeacherConfig.headTeacherPassword || headTeacherConfig.headTeacherApproved || headTeacherConfig.headTeacherApprovalReference;
    if (hasHeadTeacherBootstrap) {
      const exists = await this.store.run(async tx => (await tx.list('users')).some(user => user.roles.includes('head_teacher')));
      if (!exists) {
        if (!headTeacherConfig.headTeacherUsername || !headTeacherConfig.headTeacherName || !headTeacherConfig.headTeacherPassword || !headTeacherConfig.headTeacherApproved || !headTeacherConfig.headTeacherApprovalReference) throw new Error('Head-teacher environment provisioning requires complete account details and explicit owner approval.');
        const user = await createUserRecord(headTeacherConfig.headTeacherUsername.toLowerCase(), headTeacherConfig.headTeacherName, ['head_teacher'], headTeacherConfig.headTeacherPassword);
        await this.store.run(async tx => {
          if ((await tx.list('users')).some(item => item.roles.includes('head_teacher'))) return;
          await tx.insert('users', user);
          await audit(tx, this.config, { userId: 'owner-approved-bootstrap' }, 'user.bootstrap', 'user', user.id, 'success', { role: 'head_teacher', approvalReference: String(headTeacherConfig.headTeacherApprovalReference).slice(0, 120) });
        });
      }
    }
  }
  run(actor, fn) {
    return this.store.run(async tx => {
      // Re-read authority inside the same transaction as the action. Revocations
      // and role changes cannot be bypassed by an old browser or stale cookie.
      const fresh = await liveActor(tx, actor, this.config);
      requireReady(fresh);
      return fn(tx, fresh);
    });
  }
  async definitions(actor) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'workflow.read');
      return tx.list('workflow_definitions');
    });
  }
  async createWorkflow(actor, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'workflow.configure');
      const id = L.text(body.id, 80, true);
      if (!/^[a-z][a-z0-9-]{2,79}$/.test(id) || await tx.get('workflow_definitions', id)) throw new HttpError(400, 'Choose a unique lowercase workflow ID.');
      if (!W.RESOURCE_TYPES[body.kind]) throw new HttpError(400, 'Choose a supported workflow process.');
      const definition = W.validateDefinition(body, { id, kind: body.kind, version: 0 });
      const saved = await tx.insert('workflow_definitions', definition);
      await audit(tx, this.config, actor, 'workflow.create', 'workflow_definition', id);
      return saved;
    });
  }
  async configureWorkflow(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      A.requirePermission(actor.user, 'workflow.configure');
      const previous = await tx.get('workflow_definitions', id);
      if (!previous) throw new HttpError(404, 'Workflow not found.');
      revision(previous, body);
      const updated = await tx.update('workflow_definitions', W.validateDefinition(body, previous));
      await audit(tx, this.config, actor, 'workflow.configure', 'workflow_definition', id, 'success', { version: updated.version });
      return updated;
    });
  }
  async approvalQueue(actor) {
    return this.run(actor, async (tx, actor) => {
      if (!['leave.review', 'leave.approve', 'contract.review', 'contract.approve', 'contract.sign', 'contract.view_self', 'document.review', 'document.approve', 'cms.review', 'cms.approve', 'media.review'].some(permission => A.has(actor.user, permission))) throw new HttpError(403, 'No approval responsibilities are assigned to this account.');
      const list = [];
      for (const instance of await tx.list('workflow_instances')) {
        if (instance.status !== 'PENDING') continue;
        const resource = await tx.get(instance.collection, instance.resourceId);
        if (resource && W.eligible(actor.user, instance, resource)) list.push({ ...instance, resource: safeRecord(resource) });
      }
      await audit(tx, this.config, actor, 'approval.queue_read', 'workflow_instance', 'assigned');
      return list;
    });
  }
  async decide(actor, id, body) {
    return this.run(actor, async (tx, actor) => {
      const instance = await tx.get('workflow_instances', id);
      if (!instance) throw new HttpError(403, 'Approval is not available.');
      const resource = await tx.get(instance.collection, instance.resourceId);
      if (!resource) throw new HttpError(409, 'Request is no longer available.');
      const updated = await W.decide(tx, actor, this.config, instance, resource, body);
      if (['APPROVE', 'REJECT', 'RETURN'].includes(body.decision)) {
        if (updated.kind === 'LEAVE_REQUEST' && ['APPROVED', 'REJECTED', 'RETURNED'].includes(updated.status)) {
          if (resource.balanceId) {
            const balance = await tx.get('leave_balances', resource.balanceId);
            balance.reservedDays -= resource.days;
            if (updated.status === 'APPROVED') balance.usedDays += resource.days;
            await tx.update('leave_balances', balance);
          }
        }
        resource.status = updated.status === 'PENDING' ? 'UNDER_REVIEW' : updated.status;
        if (updated.kind === 'EMPLOYMENT_CONTRACT') {
          if (updated.status === 'APPROVED') {
            resource.status = 'ACTIVE';
            if (resource.replacesId) {
              const previous = await tx.get('contracts', resource.replacesId);
              if (!previous || !['ACTIVE', 'EXPIRED'].includes(previous.status)) throw new HttpError(409, 'The previous contract has already been superseded. HR must resolve the renewal conflict.');
              await tx.update('contracts', { ...previous, status: 'SUPERSEDED', supersededBy: resource.id });
            }
          }
          else if (updated.status === 'PENDING' && updated.steps[updated.stepIndex].selector === 'owner') resource.status = 'AWAITING_EMPLOYEE';
        }
        if (updated.status === 'APPROVED') { resource.approvedBy = actor.user.id; resource.approvedAt = new Date().toISOString(); }
        await tx.update(instance.collection, resource);
      }
      return updated;
    });
  }
}
Object.assign(Platform.prototype, require('./people'), require('./documents'), require('./communications'), require('./privacy'), require('./overview'));
module.exports = { Platform };
// Shared pure validation helpers are separate to avoid circular module loading.
