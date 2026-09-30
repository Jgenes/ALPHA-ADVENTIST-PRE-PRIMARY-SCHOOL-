'use strict';
const crypto = require('node:crypto');
const { sha256 } = require('../lib');
function canonical(value) {
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  if (value && typeof value === 'object') return '{' + Object.keys(value).sort().map(key => JSON.stringify(key) + ':' + canonical(value[key])).join(',') + '}';
  return JSON.stringify(value);
}
function signature(record, key) { return crypto.createHmac('sha256', key).update(canonical(record)).digest('hex'); }
async function audit(tx, config, actor, action, resourceType, resourceId, result = 'success', metadata = {}) {
  const head = await tx.get('system_settings', 'audit-head');
  const sequence = (head?.sequence || 0) + 1;
  const record = {
    id: 'AUD-' + String(sequence).padStart(12, '0'), sequence, previousHash: head?.hash || 'GENESIS',
    at: new Date().toISOString(), userId: actor?.user?.id || actor?.userId || 'anonymous', action,
    resourceType, resourceId: String(resourceId || ''), result,
    session: actor?.session?.id ? sha256(actor.session.id).slice(0, 20) : '',
    ip: actor?.ip || '', userAgent: String(actor?.userAgent || '').slice(0, 200), metadata
  };
  record.hash = signature(record, config.auditKey);
  await tx.insert('audit_logs', record);
  const next = { id: 'audit-head', sequence, hash: record.hash };
  if (head) await tx.update('system_settings', { ...head, ...next }); else await tx.insert('system_settings', next);
  return record;
}
function verifyAudit(records, key, head) {
  let hash = 'GENESIS'; let sequence = 0;
  for (const entry of [...records].sort((a, b) => a.sequence - b.sequence)) {
    const { hash: actual, revision, ...record } = entry;
    if (record.sequence !== ++sequence || record.previousHash !== hash || signature(record, key) !== actual) return false;
    hash = actual;
  }
  return !head ? sequence === 0 : sequence === head.sequence && hash === head.hash;
}
module.exports = { audit, verifyAudit };
