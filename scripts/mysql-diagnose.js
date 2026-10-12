'use strict';
// Temporary CI diagnostic: reports what the MySQL server accepts, as annotations.
const { mysqlConfig } = require('../src/platform/mysql-config');
const { COLLECTIONS, table } = require('../src/platform/collections');
const { createTableSql, auditTriggers } = require('../src/platform/mysql-store');
const say = line => console.log('::error::' + String(line).slice(0, 500).replace(/\n/g, ' | '));
(async () => {
  const mysql = require('mysql2/promise');
  const settings = mysqlConfig(process.env);
  if (!settings) { say('MYSQL_URL is not configured; nothing to diagnose.'); return; }
  say('settings ' + JSON.stringify({ ...settings.options, password: '***', ssl: settings.options.ssl ? 'set' : undefined }));
  const admin = await mysql.createConnection({ ...settings.options, database: undefined });
  const [variables] = await admin.query('SELECT VERSION() AS version, @@transaction_isolation AS isolation, @@sql_mode AS sqlMode, @@log_bin AS logBin, @@enforce_gtid_consistency AS gtid, @@innodb_buffer_pool_size AS pool');
  say('server ' + JSON.stringify(variables[0]));
  await admin.query('CREATE DATABASE IF NOT EXISTS adsp_diagnostic CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
  await admin.end();
  const connection = await mysql.createConnection({ ...settings.options, database: 'adsp_diagnostic' });
  for (const name of COLLECTIONS) {
    try { await connection.query(createTableSql(name)); }
    catch (error) { say('DDL ' + table(name) + ' -> ' + error.code + ' ' + error.sqlMessage); }
  }
  for (const trigger of auditTriggers()) {
    try { await connection.query(trigger.sql); }
    catch (error) { say('TRIGGER ' + trigger.name + ' -> ' + error.code + ' ' + error.sqlMessage); }
  }
  try {
    await connection.query('INSERT INTO adsp_users (id, revision, payload) VALUES (?, ?, ?)', ['diag-1', 1, JSON.stringify({ id: 'diag-1', revision: 1, username: 'dup' })]);
    await connection.query('INSERT INTO adsp_users (id, revision, payload) VALUES (?, ?, ?)', ['diag-2', 1, JSON.stringify({ id: 'diag-2', revision: 1, username: 'dup' })]);
    say('unique generated-column index did NOT reject a duplicate username');
  } catch (error) { say('duplicate rejected as expected: ' + error.code); }
  try {
    await connection.query('UPDATE adsp_audit_logs SET revision = 5 WHERE id = ?', ['nope']);
    say('audit UPDATE trigger did NOT fire');
  } catch (error) { say('audit trigger enforced: ' + error.sqlMessage); }
  await connection.query('DROP DATABASE adsp_diagnostic');
  await connection.end();
  say('diagnostic finished');
})().catch(error => { say('diagnostic crashed: ' + error.name + ' ' + error.message); process.exitCode = 1; });
