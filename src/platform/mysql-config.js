'use strict';
const fs = require('node:fs');
// Builds validated mysql2 connection options from the environment. Configuration is
// fail-closed: a half-configured MySQL setup is an error, never a silent fallback, so a
// deployment can never believe it is writing to the school database while it is not.
const URL_KEYS = ['MYSQL_URL', 'MYSQL_DATABASE_URL', 'MYSQL_URI'];
const ENV_KEYS = ['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_PASSWORD_FILE', 'MYSQL_DB_NAME', 'MYSQL_DATABASE', 'MYSQL_SOCKET', 'MYSQL_SSL_MODE', 'MYSQL_TLS_CA_FILE', 'MYSQL_TLS_SERVER_NAME', 'MYSQL_POOL_SIZE', 'MYSQL_CONNECT_TIMEOUT_MS', 'MYSQL_CREATE_SCHEMA'];
const SSL_MODES = ['disabled', 'required', 'verify-ca', 'verify-full'];
const SCHEMA_PATTERN = /^[A-Za-z0-9_]{1,63}$/;
const URL_PARAMETERS = ['ssl_mode', 'sslmode'];
function isConfigured(env = process.env) {
  return [...URL_KEYS, ...ENV_KEYS].some(key => env[key] !== undefined && env[key] !== '');
}
function integer(name, value, fallback, min, max) {
  const number = value === undefined || value === '' ? fallback : Number(value);
  if (!Number.isSafeInteger(number) || number < min || number > max) throw new Error(`${name} must be a whole number between ${min} and ${max}.`);
  return number;
}
function readSecret(env, name) {
  if (env[name]) return env[name];
  const file = env[name + '_FILE'];
  if (!file) return '';
  const stat = fs.statSync(file, { throwIfNoEntry: false });
  if (!stat) throw new Error(`${name}_FILE does not exist.`);
  if (!stat.isFile() || stat.mode & 0o077) throw new Error(`${name}_FILE must be a private readable file only by its owner (permissions 600 or stricter).`);
  return fs.readFileSync(file, 'utf8').trim();
}
function fromUrl(url) {
  let parsed;
  try { parsed = new URL(url); }
  catch { throw new Error('MYSQL_URL must be a full mysql:// connection string.'); }
  if (parsed.protocol !== 'mysql:') throw new Error('MYSQL_URL must use the mysql:// scheme.');
  if (parsed.hash) throw new Error('MYSQL_URL must not contain a fragment.');
  const query = new URLSearchParams(parsed.search);
  for (const key of [...query.keys()]) {
    if (!URL_PARAMETERS.includes(key)) throw new Error(`Unsupported MYSQL_URL parameter "${key}"; set the matching MYSQL_* environment variable instead.`);
  }
  return {
    host: parsed.hostname ? decodeURIComponent(parsed.hostname) : '',
    port: parsed.port ? integer('MYSQL_URL port', parsed.port, 0, 1, 65535) : 0,
    user: parsed.username ? decodeURIComponent(parsed.username) : '',
    password: parsed.password ? decodeURIComponent(parsed.password) : '',
    database: parsed.pathname.length > 1 ? decodeURIComponent(parsed.pathname.slice(1)) : '',
    sslMode: (query.get('ssl_mode') || query.get('sslmode') || '').toLowerCase()
  };
}
function sslSettings(mode, env) {
  if (mode === 'disabled') return undefined;
  const ssl = { rejectUnauthorized: mode !== 'required' };
  if (env.MYSQL_TLS_SERVER_NAME) ssl.servername = env.MYSQL_TLS_SERVER_NAME;
  if (env.MYSQL_TLS_CA_FILE) {
    if (!fs.existsSync(env.MYSQL_TLS_CA_FILE)) throw new Error('MYSQL_TLS_CA_FILE does not exist.');
    ssl.ca = [fs.readFileSync(env.MYSQL_TLS_CA_FILE)];
  }
  if (mode !== 'required' && !env.MYSQL_TLS_CA_FILE) ssl.ca = undefined; // Verified against the system trust store.
  return ssl;
}
function mysqlConfig(env = process.env, { production = false } = {}) {
  if (!isConfigured(env)) return null;
  const url = (URL_KEYS.map(key => env[key]).find(value => value && String(value).trim()) || '').trim();
  const parsed = url ? fromUrl(url) : {};
  const explicitDatabase = env.MYSQL_DB_NAME || env.MYSQL_DATABASE || '';
  const database = explicitDatabase || parsed.database || '';
  if (database && !SCHEMA_PATTERN.test(database)) throw new Error('The MySQL schema name must be 1–63 characters of letters, digits or "_", because the application quotes it as an identifier; choose a name without hyphens, dots or spaces.');
  if (explicitDatabase && parsed.database && explicitDatabase !== parsed.database) throw new Error('MYSQL_DB_NAME disagrees with the database in MYSQL_URL; correct the configuration before connecting.');
  const socket = env.MYSQL_SOCKET || '';
  const host = env.MYSQL_HOST || parsed.host || '';
  const user = env.MYSQL_USER || parsed.user || '';
  const password = readSecret(env, 'MYSQL_PASSWORD') || parsed.password || '';
  const missing = [!socket && !host ? 'MYSQL_HOST' : '', !user ? 'MYSQL_USER' : '', !database ? 'MYSQL_DB_NAME (or a path in MYSQL_URL)' : ''].filter(Boolean);
  if (missing.length) throw new Error(`MySQL setup is incomplete; missing: ${missing.join(', ')}. Provide a full MYSQL_URL or a host, user and database.`);
  const sslMode = (env.MYSQL_SSL_MODE || parsed.sslMode || (production ? 'verify-full' : 'disabled')).toLowerCase();
  if (!SSL_MODES.includes(sslMode)) throw new Error(`MYSQL_SSL_MODE must be one of: ${SSL_MODES.join(', ')}.`);
  if (production && sslMode === 'disabled') throw new Error('MySQL connections must be encrypted outside development; set MYSQL_SSL_MODE to required, verify-ca or verify-full.');
  if (sslMode !== 'disabled' && socket) throw new Error('MYSQL_SOCKET cannot be combined with MYSQL_SSL_MODE; a local socket is already machine-local.');
  return {
    database,
    sslMode,
    createSchema: env.MYSQL_CREATE_SCHEMA === 'true',
    requireAuditTriggers: env.MYSQL_REQUIRE_AUDIT_TRIGGERS !== 'false',
    connectionLimit: integer('MYSQL_POOL_SIZE', env.MYSQL_POOL_SIZE, 15, 1, 100),
    options: {
      host: host || 'localhost',
      port: integer('MYSQL_PORT', env.MYSQL_PORT || (parsed.port || ''), 3306, 1, 65535),
      socketPath: socket || undefined,
      user,
      password,
      database,
      ssl: sslSettings(sslMode, env),
      charset: 'utf8mb4',
      timezone: 'Z',
      dateStrings: true,
      multipleStatements: false,
      supportBigNumbers: true,
      enableKeepAlive: true,
      connectTimeout: integer('MYSQL_CONNECT_TIMEOUT_MS', env.MYSQL_CONNECT_TIMEOUT_MS, 10000, 1000, 120000)
    }
  };
}
module.exports = { mysqlConfig, isConfigured, SCHEMA_PATTERN, SSL_MODES };
