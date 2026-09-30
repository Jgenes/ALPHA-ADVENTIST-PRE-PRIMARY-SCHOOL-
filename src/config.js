'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const LIVE_URL = 'https://alpha-adventist-pre-primary-school.onrender.com';
function positive(value, fallback, max = 3650) {
  const number = value === undefined || value === '' ? fallback : Number(value);
  if (!Number.isSafeInteger(number) || number < 1 || number > max) throw new Error('Invalid positive configuration value.');
  return number;
}
function createConfig(env = process.env) {
  const production = ['production', 'staging'].includes(env.NODE_ENV);
  if (production && !env.BASE_URL) throw new Error('Set an explicitly verified BASE_URL outside development.');
  if (production && !env.DATA_DIR) throw new Error('Set DATA_DIR to persistent, protected storage.');
  const base = new URL(env.BASE_URL || LIVE_URL);
  if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.pathname !== '/' || base.search || base.hash) throw new Error('BASE_URL must be an origin, without a path or credentials.');
  if (production && base.protocol !== 'https:') throw new Error('BASE_URL must use HTTPS outside development.');
  const dataDir = path.resolve(env.DATA_DIR || path.join(__dirname, '..', '.runtime'));
  const publicDir = path.resolve(__dirname, '..', 'public');
  if (dataDir === publicDir || dataDir.startsWith(publicDir + path.sep)) throw new Error('DATA_DIR must be outside the public web root.');
  fs.mkdirSync(dataDir, { recursive: true, mode: 0o700 });
  const keyFile = path.join(dataDir, 'development-keys.json');
  let localKeys = {};
  if (!production) {
    if (fs.existsSync(keyFile)) localKeys = JSON.parse(fs.readFileSync(keyFile, 'utf8'));
    else {
      for (const key of ['MFA_ENCRYPTION_KEY', 'STORAGE_ENCRYPTION_KEY', 'AUDIT_HMAC_KEY']) localKeys[key] = crypto.randomBytes(32).toString('hex');
      fs.writeFileSync(keyFile, JSON.stringify(localKeys), { flag: 'wx', mode: 0o600 });
    }
  }
  function secret(name) {
    const value = env[name] || (env[name + '_FILE'] && fs.readFileSync(env[name + '_FILE'], 'utf8').trim()) || localKeys[name];
    if (!/^[a-f0-9]{64}$/i.test(value || '')) throw new Error(`${name} must be a separately managed 32-byte hexadecimal key.`);
    return Buffer.from(value, 'hex');
  }
  const mongoUri = env.MONGODB_URI || env.MONGO_URI;
  const mongoDb = env.MONGODB_DB_NAME || env.MONGO_DB_NAME;
  if (production && (!mongoUri || !mongoDb)) throw new Error('An isolated MongoDB replica-set database is required outside development.');
  if (production && (!env.FORM_RETENTION_DAYS || !env.ADMISSION_RETENTION_DAYS || env.RETENTION_POLICY_APPROVED !== 'true')) throw new Error('Configure and approve the retention schedule before production/staging startup.');
  if (production && (!env.OFFICE_ALERT_WEBHOOK_URL || !env.OFFICE_ALERT_WEBHOOK_SECRET)) throw new Error('Configure the signed office-alert integration before production/staging startup.');
  if (env.OFFICE_ALERT_WEBHOOK_URL) {
    const endpoint = new URL(env.OFFICE_ALERT_WEBHOOK_URL);
    if (endpoint.protocol !== 'https:' || endpoint.username || endpoint.password) throw new Error('Office alerts require a trusted HTTPS endpoint.');
  }
  if (production && (!env.SAFEGUARDING_NAME || !env.SAFEGUARDING_PHONE)) throw new Error('Confirm the named safeguarding contact before production/staging startup.');
  if (env.OFFICE_ALERT_WEBHOOK_URL && (env.OFFICE_ALERT_WEBHOOK_SECRET || '').length < 32) throw new Error('Office alerts require a separate secret of at least 32 characters.');
  const keys = { mfaKey: secret('MFA_ENCRYPTION_KEY'), storageKey: secret('STORAGE_ENCRYPTION_KEY'), auditKey: secret('AUDIT_HMAC_KEY') };
  if (new Set(Object.values(keys).map(value => value.toString('hex'))).size !== 3) throw new Error('MFA, storage and audit keys must be different.');
  const port = Number(env.PORT || 3000);
  if (!Number.isInteger(port) || port < 0 || port > 65535) throw new Error('Invalid PORT.');
  const proxyHops = Number(env.TRUST_PROXY_HOPS || 0);
  if (!Number.isSafeInteger(proxyHops) || proxyHops < 0 || proxyHops > 5) throw new Error('TRUST_PROXY_HOPS must be an explicit proxy hop count (0–5).');
  return {
    production, environment: env.NODE_ENV || 'development', baseUrl: base.origin, dataDir,
    port, mongoUri, mongoDb, ...keys,
    proxyHops, sessionIdleMs: 30 * 60 * 1000, sessionAbsoluteMs: 8 * 60 * 60 * 1000,
    formRetentionDays: positive(env.FORM_RETENTION_DAYS, 90), admissionRetentionDays: positive(env.ADMISSION_RETENTION_DAYS, 365),
    alertUrl: env.OFFICE_ALERT_WEBHOOK_URL || '', alertSecret: env.OFFICE_ALERT_WEBHOOK_SECRET || '',
    safeguardingName: env.SAFEGUARDING_NAME || '', safeguardingPhone: env.SAFEGUARDING_PHONE || '',
    scanCommand: env.UPLOAD_SCAN_COMMAND || '', maxUploadBytes: 5 * 1024 * 1024,
    adminUsername: env.ADMIN_USERNAME || 'system-admin', adminName: env.ADMIN_NAME || 'System Administrator', adminPassword: env.ADMIN_PASSWORD,
    policyVersion: 'privacy-2026-09-30-v2'
  };
}
module.exports = { createConfig, LIVE_URL };
