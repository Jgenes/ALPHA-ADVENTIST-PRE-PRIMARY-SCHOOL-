'use strict';
const { HttpError, rid, text } = require('../lib');
const A = require('./access');
const { audit } = require('./audit');
const DEFAULT_WORKFLOWS = [
  { id: 'leave-standard', kind: 'LEAVE_REQUEST', name: 'Leave · supervisor → HR → Head Teacher', steps: [
    { id: 'supervisor', label: 'Supervisor review', selector: 'supervisor', permission: 'leave.review' },
    { id: 'hr', label: 'HR review', selector: 'role', roles: ['hr_officer'], permission: 'leave.review' },
    { id: 'final', label: 'Final approval', selector: 'role', roles: ['head_teacher'], permission: 'leave.approve' }
  ] },
  { id: 'contract-standard', kind: 'EMPLOYMENT_CONTRACT', name: 'Contract · administration → finance (if needed) → approval → signature → employee', steps: [
    { id: 'administration', label: 'Administration review', selector: 'role', roles: ['school_admin'], permission: 'contract.review' },
    { id: 'finance', label: 'Financial terms review', selector: 'role', roles: ['finance_officer'], permission: 'contract.review', condition: 'financialReview' },
    { id: 'approve', label: 'Management approval', selector: 'role', roles: ['head_teacher'], permission: 'contract.approve' },
    { id: 'sign', label: 'Authorised signatory attestation', selector: 'role', roles: ['head_teacher'], permission: 'contract.sign' },
    { id: 'employee', label: 'Employee acknowledgement', selector: 'owner', permission: 'contract.view_self' }
  ] },
  { id: 'document-standard', kind: 'CONTROLLED_DOCUMENT', name: 'Document · administration → authorised approval', steps: [
    { id: 'review', label: 'Document review', selector: 'role', roles: ['school_admin', 'hr_officer', 'dpo'], permission: 'document.review' },
    { id: 'approve', label: 'Institutional approval', selector: 'role', roles: ['head_teacher'], permission: 'document.approve' }
  ] },
  { id: 'cms-standard', kind: 'CMS_PUBLICATION', name: 'Website · editor → approver → separate publication', steps: [
    { id: 'editor', label: 'Editorial review', selector: 'role', roles: ['cms_editor'], permission: 'cms.review' },
    { id: 'approve', label: 'Publication approval', selector: 'role', roles: ['head_teacher'], permission: 'cms.approve' }
  ] },
  { id: 'media-standard', kind: 'MEDIA_PUBLICATION', name: 'Child media · privacy review → management approval', steps: [
    { id: 'privacy', label: 'Consent and safeguarding review', selector: 'role', roles: ['dpo'], permission: 'media.review' },
    { id: 'approve', label: 'Media publication approval', selector: 'role', roles: ['head_teacher'], permission: 'cms.approve' }
  ] }
];
const RESOURCE_TYPES = { LEAVE_REQUEST: 'leave_requests', EMPLOYMENT_CONTRACT: 'contracts', CONTROLLED_DOCUMENT: 'document_versions', CMS_PUBLICATION: 'cms_content', MEDIA_PUBLICATION: 'media' };
function eligible(user, instance, resource, step = instance.steps[instance.stepIndex]) {
  if (!user || !user.active || !step || !A.has(user, step.permission)) return false;
  if (step.selector === 'owner') return user.id === resource.ownerId;
  // No requester/author can approve their own request, even with several roles.
  if (user.id === resource.createdBy || (resource.revisionAuthors || []).includes(user.id) || user.id === resource.ownerId && instance.kind === 'LEAVE_REQUEST') return false;
  if (instance.kind === 'CONTROLLED_DOCUMENT' && !A.canReadDocument(user, resource)) return false;
  if (step.selector === 'supervisor') return resource.supervisorId === user.id;
  if (step.selector === 'department') return user.departmentId && user.departmentId === resource.departmentId && (step.roles || []).some(role => user.roles.includes(role));
  return step.selector === 'role' && (step.roles || []).some(role => user.roles.includes(role));
}
async function notify(tx, userId, title, href, resourceId, key = '') {
  const id = key || rid('NTF-');
  if (key && await tx.get('notifications', id)) return;
  await tx.insert('notifications', { id, userId, title, href, resourceId, readAt: null, createdAt: new Date().toISOString() });
}
async function notifyStep(tx, instance, resource) {
  for (const user of await tx.list('users')) if (eligible(user, instance, resource)) await notify(tx, user.id, `Approval required: ${resource.reference || resource.title || 'controlled request'}`, '/portal/approvals', resource.id);
}
function validateDefinition(body, previous) {
  if (!Array.isArray(body.steps) || !body.steps.length || body.steps.length > 8) throw new HttpError(400, 'Use between one and eight workflow steps.');
  const kind = previous.kind;
  const allowed = {
    LEAVE_REQUEST: ['leave.review', 'leave.approve'],
    EMPLOYMENT_CONTRACT: ['contract.review', 'contract.approve', 'contract.sign', 'contract.view_self'],
    CONTROLLED_DOCUMENT: ['document.review', 'document.approve'],
    CMS_PUBLICATION: ['cms.review', 'cms.approve'],
    MEDIA_PUBLICATION: ['media.review', 'cms.approve']
  }[kind];
  const ids = new Set();
  const steps = body.steps.map(step => {
    if (!/^[a-z0-9-]{1,40}$/.test(step.id || '') || ids.has(step.id) || !allowed.includes(step.permission) || !['role', 'supervisor', 'department', 'owner'].includes(step.selector)) throw new HttpError(400, 'Invalid workflow step or permission.');
    ids.add(step.id);
    if (step.selector === 'owner' && !(kind === 'EMPLOYMENT_CONTRACT' && step.permission === 'contract.view_self')) throw new HttpError(400, 'Only contract acknowledgement can be assigned to the requester.');
    if (step.selector === 'supervisor' && kind !== 'LEAVE_REQUEST') throw new HttpError(400, 'Supervisor routing is reserved for leave.');
    const roles = ['role', 'department'].includes(step.selector) ? A.validRoles(step.roles) : [];
    if (roles.some(role => !A.ROLE_PERMISSIONS[role].includes(step.permission))) throw new HttpError(400, 'Each selected role must have the step permission.');
    if (step.condition && !(kind === 'EMPLOYMENT_CONTRACT' && step.condition === 'financialReview' && step.permission === 'contract.review')) throw new HttpError(400, 'Unsupported workflow condition.');
    return { id: step.id, label: text(step.label, 100, true), selector: step.selector, permission: step.permission, roles, ...(step.condition ? { condition: step.condition } : {}) };
  });
  const last = steps.at(-1);
  const requiredFinal = { LEAVE_REQUEST: 'leave.approve', CONTROLLED_DOCUMENT: 'document.approve', CMS_PUBLICATION: 'cms.approve', MEDIA_PUBLICATION: 'cms.approve', EMPLOYMENT_CONTRACT: 'contract.view_self' }[kind];
  if (last.permission !== requiredFinal || last.condition) throw new HttpError(400, 'The final required authority cannot be removed or made optional.');
  if (kind === 'EMPLOYMENT_CONTRACT' && (last.selector !== 'owner' || !steps.some(step => step.permission === 'contract.approve') || !steps.some(step => step.permission === 'contract.sign'))) throw new HttpError(400, 'Contracts require management approval, signatory attestation and employee acknowledgement.');
  if (kind === 'EMPLOYMENT_CONTRACT' && (steps.findIndex(step => step.permission === 'contract.sign') < steps.findIndex(step => step.permission === 'contract.approve') || steps.filter(step => step.selector === 'owner').length !== 1)) throw new HttpError(400, 'Contract approval must precede signatory attestation and one final employee acknowledgement.');
  if (kind === 'MEDIA_PUBLICATION' && !steps.some(step => step.permission === 'media.review')) throw new HttpError(400, 'Media must pass privacy review.');
  if (kind === 'CMS_PUBLICATION' && !steps.some(step => step.permission === 'cms.review')) throw new HttpError(400, 'CMS content requires an editorial review before approval.');
  return { ...previous, name: text(body.name, 150, true), steps, version: previous.version + 1 };
}
async function startWorkflow(tx, actor, config, definitionId, resource) {
  const definition = await tx.get('workflow_definitions', definitionId);
  if (!definition || !RESOURCE_TYPES[definition.kind]) throw new HttpError(400, 'Workflow is not configured.');
  const steps = definition.steps.filter(step => !step.condition || !!resource[step.condition]);
  const instance = { id: rid('WF-'), definitionId, definitionVersion: definition.version, kind: definition.kind, collection: RESOURCE_TYPES[definition.kind], resourceId: resource.id, requesterId: resource.createdBy, steps, stepIndex: 0, status: 'PENDING', decisions: [], createdAt: new Date().toISOString() };
  const users = await tx.list('users');
  for (const step of steps) {
    if (!users.some(user => eligible(user, instance, resource, step))) throw new HttpError(409, `No independent authorised approver is configured for “${step.label}”. Ask management to configure the route and staff profile.`);
  }
  const saved = await tx.insert('workflow_instances', instance);
  await audit(tx, config, actor, 'workflow.submit', instance.collection, resource.id, 'success', { workflowId: saved.id, definitionVersion: definition.version });
  await notifyStep(tx, saved, resource);
  return saved;
}
async function decide(tx, actor, config, instance, resource, body) {
  if (instance.status !== 'PENDING' || Number(body.revision) !== instance.revision) throw new HttpError(409, 'The approval moved to another stage. Refresh before deciding.', 'VERSION_CONFLICT');
  if (!eligible(actor.user, instance, resource)) throw new HttpError(403, 'You are not assigned to this approval stage.');
  if (!['APPROVE', 'REJECT', 'RETURN', 'COMMENT', 'ESCALATE'].includes(body.decision)) throw new HttpError(400, 'Select a valid decision.');
  const step = instance.steps[instance.stepIndex];
  const comment = text(body.comment, 2000, ['REJECT', 'RETURN', 'ESCALATE'].includes(body.decision) || step.permission === 'contract.sign' || step.selector === 'owner');
  instance.decisions.push({ stepId: step.id, actorId: actor.user.id, decision: body.decision, comment, at: new Date().toISOString(), session: actor.session?.id?.slice(0, 12) || '' });
  if (body.decision === 'APPROVE') {
    instance.stepIndex++;
    if (instance.stepIndex >= instance.steps.length) instance.status = 'APPROVED';
  } else if (body.decision === 'REJECT') instance.status = 'REJECTED';
  else if (body.decision === 'RETURN') instance.status = 'RETURNED';
  const updated = await tx.update('workflow_instances', instance);
  await audit(tx, config, actor, 'workflow.' + body.decision.toLowerCase(), instance.collection, resource.id, 'success', { workflowId: instance.id, stepId: step.id });
  if (['REJECTED', 'RETURNED', 'APPROVED'].includes(updated.status)) {
    await notify(tx, resource.createdBy, `${resource.reference || resource.title}: ${updated.status.toLowerCase()}`, '/portal/requests', resource.id);
    if (resource.ownerId && resource.ownerId !== resource.createdBy) await notify(tx, resource.ownerId, `${resource.reference || 'Your request'}: ${updated.status.toLowerCase()}`, '/portal/requests', resource.id);
  } else if (body.decision === 'APPROVE') await notifyStep(tx, updated, resource);
  if (updated.status === 'APPROVED') {
    const publishPermission = { CMS_PUBLICATION: 'cms.publish', CONTROLLED_DOCUMENT: 'document.publish', MEDIA_PUBLICATION: 'media.publish' }[updated.kind];
    if (publishPermission) for (const user of await tx.list('users')) if (A.has(user, publishPermission) && user.id !== resource.createdBy) await notify(tx, user.id, `Approved content is ready for publication: ${resource.reference || resource.title}`, updated.kind === 'CMS_PUBLICATION' ? '/portal/cms' : updated.kind === 'MEDIA_PUBLICATION' ? '/portal/media' : '/portal/documents', resource.id);
  }
  if (body.decision === 'ESCALATE') {
    for (const user of await tx.list('users')) if (A.has(user, 'workflow.configure')) await notify(tx, user.id, `Escalation: ${resource.reference || resource.title}`, '/portal/approvals', resource.id);
  }
  return updated;
}
module.exports = { DEFAULT_WORKFLOWS, RESOURCE_TYPES, eligible, notify, startWorkflow, decide, validateDefinition };
