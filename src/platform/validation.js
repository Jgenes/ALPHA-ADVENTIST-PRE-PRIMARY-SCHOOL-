'use strict';
const { HttpError } = require('../lib');
const A = require('./access');
function choose(value, values, label = 'option') {
  if (!values.includes(value)) throw new HttpError(400, `Select a valid ${label}.`);
  return value;
}
function date(value, optional = false) {
  if (!value && optional) return '';
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || !Number.isFinite(Date.parse(value)) || new Date(value).toISOString().slice(0, 10) !== value) throw new HttpError(400, 'Use a valid calendar date (YYYY-MM-DD).');
  return value;
}
function revision(record, body) {
  if (Number(body.revision) !== record.revision) throw new HttpError(409, 'This record changed. Refresh and try again.', 'VERSION_CONFLICT');
}
function strings(values, max = 100) {
  if (values == null) return [];
  if (!Array.isArray(values) || values.length > max || values.some(value => typeof value !== 'string' || value.length > 100)) throw new HttpError(400, 'Invalid selection list.');
  return [...new Set(values)];
}
async function audience(tx, input, publicAllowed = false) {
  const value = input || { type: 'ALL_STAFF' };
  choose(value.type, publicAllowed ? ['PUBLIC', 'ALL_STAFF', 'SELECTED'] : ['ALL_STAFF', 'SELECTED'], 'audience');
  const userIds = strings(value.userIds), roles = strings(value.roles, 20), departmentIds = strings(value.departmentIds, 30);
  if (roles.some(role => !A.ROLE_PERMISSIONS[role])) throw new HttpError(400, 'Unknown audience role.');
  for (const id of userIds) if (!(await tx.get('users', id))?.active) throw new HttpError(400, 'Unknown audience user.');
  for (const id of departmentIds) if (!await tx.get('departments', id)) throw new HttpError(400, 'Unknown audience department.');
  if (value.type === 'SELECTED' && !userIds.length && !roles.length && !departmentIds.length) throw new HttpError(400, 'Choose at least one audience.');
  return { type: value.type, userIds, roles, departmentIds };
}
function activeDate(record) { return (!record.publishAt || Date.parse(record.publishAt) <= Date.now()) && (!record.expiresAt || Date.parse(record.expiresAt + 'T23:59:59Z') >= Date.now()); }
function safeRecord(record) {
  if (record === null || record === undefined || record === false) return null;
  if (Array.isArray(record)) return record.map(safeRecord);
  if (typeof record !== 'object') return record;
  return Object.fromEntries(Object.entries(record).filter(([key]) => !['storageKey', 'mfaSecretEnc', 'salt', 'hash', 'algorithm'].includes(key)).map(([key, value]) => [key, key === 'file' ? require('./files').publicFileMetadata(value) : value && typeof value === 'object' ? safeRecord(value) : value]));
}

module.exports = { choose, date, revision, strings, audience, activeDate, safeRecord };
