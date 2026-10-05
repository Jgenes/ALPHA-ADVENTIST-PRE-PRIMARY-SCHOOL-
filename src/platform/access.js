'use strict';
const { HttpError } = require('../lib');
const STAFF = ['staff.view_self', 'leave.create', 'leave.view_self', 'contract.view_self', 'document.read', 'notice.read', 'calendar.read', 'notification.read', 'search', 'request.read'];
const ROLE_PERMISSIONS = Object.freeze({
  system_admin: ['user.create', 'user.manage', 'system.read', 'notification.read', 'workflow.read'],
  ict_officer: [...STAFF, 'system.read', 'cms.configure', 'document.create', 'document.review'],
  head_teacher: [...STAFF, 'staff.view_all', 'staff.manage', 'student.manage', 'student.read', 'student.guardian.verify', 'student.attendance.read', 'student.results.read', 'student.results.manage', 'student.material.manage', 'class.manage', 'student.attendance.manage', 'student.results.publish', 'student.material.publish', 'leave.review', 'leave.approve', 'contract.view_all', 'contract.review', 'contract.approve', 'contract.sign', 'document.create', 'document.review', 'document.approve', 'document.publish', 'document.archive', 'document.confidential', 'document.high_confidential', 'notice.create', 'notice.publish', 'cms.create', 'cms.edit', 'cms.edit_any', 'cms.review', 'cms.approve', 'cms.publish', 'cms.archive', 'admission.read', 'admission.manage', 'submission.read', 'submission.manage', 'workflow.configure', 'workflow.read', 'role.grant', 'user.create', 'user.manage', 'audit.read', 'report.read', 'calendar.manage', 'privacy.manage', 'media.review', 'media.publish'],
  school_admin: [...STAFF, 'staff.view_all', 'student.manage', 'student.read', 'student.attendance.read', 'student.results.read', 'student.results.manage', 'student.material.manage', 'class.manage', 'student.attendance.manage', 'student.results.publish', 'student.material.publish', 'leave.review', 'contract.review', 'document.create', 'document.review', 'document.publish', 'document.archive', 'notice.create', 'notice.publish', 'cms.create', 'cms.edit', 'cms.edit_any', 'cms.review', 'admission.read', 'admission.manage', 'submission.read', 'submission.manage', 'calendar.manage', 'report.read', 'workflow.read'],
  hr_officer: [...STAFF, 'staff.view_all', 'staff.manage', 'leave.review', 'contract.create', 'contract.review', 'contract.view_all', 'document.create', 'document.review', 'document.confidential', 'document.high_confidential', 'notice.create', 'report.hr', 'workflow.read'],
  finance_officer: [...STAFF, 'contract.review', 'document.create', 'document.review'],
  academic_coordinator: [...STAFF, 'staff.view_department', 'student.manage', 'student.read', 'student.attendance.read', 'student.results.read', 'student.results.manage', 'student.material.manage', 'student.guardian.verify', 'class.manage', 'student.attendance.manage', 'student.results.publish', 'student.material.publish', 'leave.review', 'document.create', 'document.review', 'notice.create', 'calendar.manage'],
  head_of_department: [...STAFF, 'staff.view_department', 'leave.review', 'document.create', 'document.review', 'notice.create'],
  teacher: [...STAFF, 'cms.create', 'document.create', 'notice.read', 'calendar.read', 'student.read', 'student.attendance.read', 'student.attendance.manage', 'student.results.read', 'student.results.manage', 'student.material.manage', 'student.communication.read', 'student.communication.send'],
  supporting_staff: STAFF,
  dpo: [...STAFF, 'privacy.manage', 'audit.read', 'document.create', 'document.review', 'document.confidential', 'document.high_confidential', 'media.review'],
  cms_author: [...STAFF, 'cms.create', 'cms.edit'],
  cms_editor: [...STAFF, 'cms.create', 'cms.edit', 'cms.edit_any', 'cms.review'],
  cms_publisher: [...STAFF, 'cms.publish', 'cms.archive', 'media.publish'],
  media_manager: [...STAFF, 'media.create', 'cms.create'],
  auditor: ['audit.read', 'notification.read'],
  parent: ['notification.read', 'notice.read', 'calendar.read', 'document.read', 'search', 'student.read', 'student.profile.read', 'student.results.read', 'student.attendance.read', 'student.communication.read', 'student.communication.send'],
  student: ['notification.read', 'notice.read', 'calendar.read', 'document.read', 'search', 'student.read', 'student.profile.read', 'student.results.read', 'student.attendance.read', 'student.communication.read']
});
const ROLE_LABELS = { system_admin: 'System Administrator', ict_officer: 'ICT Officer', head_teacher: 'Head Teacher', school_admin: 'School Administrator', hr_officer: 'HR Officer', finance_officer: 'Finance Officer', academic_coordinator: 'Academic Coordinator', head_of_department: 'Head of Department', teacher: 'Teacher', supporting_staff: 'Supporting Staff', dpo: 'Privacy Officer', cms_author: 'Content Author', cms_editor: 'Content Editor', cms_publisher: 'CMS Publisher', media_manager: 'Media Manager', auditor: 'Auditor', parent: 'Parent / Guardian', student: 'Student' };
function permissions(user) {
  if (!user || user.active === false) return [];
  return [...new Set((user.roles || []).flatMap(role => ROLE_PERMISSIONS[role] || []))];
}
function has(user, action) { return permissions(user).includes(action); }
function requirePermission(user, action) {
  if (!has(user, action)) throw new HttpError(403, 'You are not authorised for this action.', 'FORBIDDEN');
}
function isStaff(user) { return has(user, 'staff.view_self'); }
function audienceAllows(user, audience = {}) {
  if (!user) return false;
  if (audience.type === 'ALL_STAFF') return isStaff(user);
  if (audience.type === 'PUBLIC') return true;
  return (audience.userIds || []).includes(user.id) || (audience.roles || []).some(role => user.roles.includes(role)) || (user.departmentId && (audience.departmentIds || []).includes(user.departmentId));
}
function canReadStaff(user, profile) {
  return profile.id === user.id ? has(user, 'staff.view_self') : has(user, 'staff.view_all') || (has(user, 'staff.view_department') && !!user.departmentId && user.departmentId === profile.departmentId);
}
function canReadLeave(user, record) {
  return (record.ownerId === user.id && has(user, 'leave.view_self')) || has(user, 'staff.view_all') && has(user, 'leave.review') || has(user, 'leave.review') && (record.supervisorId === user.id || has(user, 'staff.view_department') && !!user.departmentId && user.departmentId === record.departmentId);
}
function canReadContract(user, record, assigned = false) {
  return (record.ownerId === user.id && has(user, 'contract.view_self') && ['AWAITING_EMPLOYEE', 'ACTIVE', 'EXPIRED', 'ARCHIVED', 'SUPERSEDED'].includes(record.status)) || has(user, 'contract.view_all') || assigned && has(user, 'contract.review');
}
const CLASSIFICATIONS = ['PUBLIC', 'INTERNAL', 'RESTRICTED', 'CONFIDENTIAL', 'HIGHLY_CONFIDENTIAL'];
function canReadDocument(user, record) {
  if (!has(user, 'document.read')) return false;
  // Employment files are authorised through their contract, never by a generic
  // library ACL. Child records are confined to specifically assigned custodians.
  if (record.contractId) return false;
  if (record.classification === 'PUBLIC') return true;
  if (record.classification === 'INTERNAL') return isStaff(user);
  const acl = record.access || {};
  const named = record.ownerId === user.id || (acl.userIds || []).includes(user.id) || (acl.roles || []).some(role => user.roles.includes(role));
  if (record.classification === 'RESTRICTED') return named || !!user.departmentId && (acl.departmentIds || []).includes(user.departmentId);
  if (record.classification === 'CONFIDENTIAL') return named && has(user, 'document.confidential');
  if (record.classification === 'HIGHLY_CONFIDENTIAL') return named && has(user, 'document.high_confidential');
  return false;
}
function validRoles(roles) {
  if (!Array.isArray(roles) || !roles.length || roles.length > 5 || roles.some(role => typeof role !== 'string' || !Object.hasOwn(ROLE_PERMISSIONS, role))) throw new HttpError(400, 'Select valid roles.');
  if (roles.some(role => ['system_admin', 'ict_officer'].includes(role)) && roles.some(role => !['system_admin', 'ict_officer'].includes(role))) throw new HttpError(400, 'Technical administration and business roles must use separate accounts.');
  return [...new Set(roles)];
}
module.exports = { ROLE_PERMISSIONS, ROLE_LABELS, CLASSIFICATIONS, permissions, has, requirePermission, isStaff, audienceAllows, canReadStaff, canReadLeave, canReadContract, canReadDocument, validRoles };
