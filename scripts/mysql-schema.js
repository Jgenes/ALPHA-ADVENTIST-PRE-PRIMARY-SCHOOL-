'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { mysqlSchemaFile } = require('../src/platform/store');

// Writes the importable schema used by cPanel → phpMyAdmin. A test keeps this
// file identical to the DDL the application applies at startup.
const target = path.join(__dirname, '..', 'db', 'schema.mysql.sql');
fs.mkdirSync(path.dirname(target), { recursive: true });
fs.writeFileSync(target, mysqlSchemaFile(), { mode: 0o644 });
console.log(`Wrote ${path.relative(process.cwd(), target)}. Import it in phpMyAdmin, or let the app create the tables on first start.`);
