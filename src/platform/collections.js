'use strict';
// The physical layout of the audited platform store. Names are code-owned and are
// never interpolated from a request. The prefix keeps the audited platform separate
// from legacy tables until an explicit migration.
const COLLECTIONS = ['users', 'sessions', 'login_limits', 'departments', 'staff_profiles', 'student_profiles', 'student_guardians', 'classes', 'class_teachers', 'attendance_records', 'student_results', 'learning_materials', 'family_messages', 'admission_documents', 'leave_types', 'leave_requests', 'leave_balances', 'contracts', 'documents', 'document_versions', 'workflow_definitions', 'workflow_instances', 'notices', 'notice_acknowledgements', 'cms_content', 'admissions', 'submissions', 'notifications', 'outbox', 'calendar', 'media', 'media_consents', 'privacy_requests', 'incidents', 'audit_logs', 'system_settings', 'download_grants'];
const TABLE_PREFIX = 'adsp_';
// Records that may never be edited or deleted once written.
const APPEND_ONLY = ['audit_logs'];
// Payload fields that carry a hard uniqueness rule. Both store backends enforce
// these in the database, because a check performed only in application code races.
const UNIQUE_KEYS = {
  users: [['username']],
  audit_logs: [['sequence']],
  staff_profiles: [['staffId']],
  student_profiles: [['studentRef'], ['userId']],
  student_guardians: [['studentId', 'guardianUserId']],
  class_teachers: [['classId', 'teacherUserId']],
  attendance_records: [['studentId', 'date']],
  cms_content: [['kind', 'slug']]
};
// Non-unique indexes. The application reads whole collections, so these exist for
// operator queries and clean-up, not for the request path.
const SECONDARY_KEYS = {
  sessions: [['expiresAt']],
  login_limits: [['expiresAt']],
  download_grants: [['expiresAt']],
  family_messages: [['studentId', 'createdAt']]
};
function table(name) {
  if (!COLLECTIONS.includes(name)) throw new Error('Unknown collection.');
  return TABLE_PREFIX + name;
}
function fieldColumn(field) {
  if (!/^[a-zA-Z][a-zA-Z0-9]*$/.test(field)) throw new Error('Unsupported indexed field name.');
  return 'idx_' + field.toLowerCase();
}
// Every indexed field of a collection, in declaration order.
function indexedFields(name) {
  const fields = [];
  for (const group of [...(UNIQUE_KEYS[name] || []), ...(SECONDARY_KEYS[name] || [])]) {
    for (const field of group) if (!fields.includes(field)) fields.push(field);
  }
  return fields;
}
function indexGroups(name) {
  return [
    ...(UNIQUE_KEYS[name] || []).map(fields => ({ fields, unique: true })),
    ...(SECONDARY_KEYS[name] || []).map(fields => ({ fields, unique: false }))
  ];
}
function appendOnly(name) { return APPEND_ONLY.includes(name); }
module.exports = { COLLECTIONS, TABLE_PREFIX, UNIQUE_KEYS, SECONDARY_KEYS, table, fieldColumn, indexedFields, indexGroups, appendOnly };
