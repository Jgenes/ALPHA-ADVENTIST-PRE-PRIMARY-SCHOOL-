'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const paths = ['server.js'];
function walk(dir) {
  for (const entry of fs.readdirSync(path.join(root, dir), { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(file);
    else if (file.endsWith('.js')) paths.push(file);
  }
}
for (const dir of ['src', 'scripts', 'test', 'public/js']) walk(dir);
paths.push('public/service-worker.js');
for (const file of paths) execFileSync(process.execPath, ['--check', file], { cwd: root, stdio: 'pipe' });
const sw = fs.readFileSync(path.join(root, 'public/service-worker.js'), 'utf8');
const assets = JSON.parse(sw.match(/const ASSETS = (\[[^;]+\]);/)[1].replace(/'/g, '"'));
for (const asset of assets) assert.ok(fs.existsSync(path.join(root, 'public', asset === '/offline' ? 'offline.html' : asset.slice(1))), 'Missing precache asset: ' + asset);
const seed = JSON.parse(fs.readFileSync(path.join(root, 'data/seed.json')));
for (const key of ['users', 'submissions', 'gallery', 'hero']) assert.equal((seed[key] || []).length, 0, 'No private records or unverified media in seed');
const files = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], { cwd: root }).toString().split('\0').filter(file => file && fs.existsSync(path.join(root, file)));
for (const file of files) {
  if (file === '.env.example') continue;
  assert.ok(!/^(?:\.env(?:\.|$)|\.runtime\/|private\/|work\/|uploads\/|backups\/|data\/(?!seed\.json$))/.test(file), 'Sensitive/runtime path must not be tracked: ' + file);
  assert.ok(!/\.(?:sqlite(?:-\w+)?|ejson\.enc|pdf)$/i.test(file), 'Review and remove internal binary artifact: ' + file);
}
const YAML = require('yaml');
for (const name of ['render.yaml', '.github/workflows/ci.yml']) {
  const doc = YAML.parseDocument(fs.readFileSync(path.join(root, name), 'utf8'), { uniqueKeys: true });
  assert.equal(doc.errors.length, 0, 'Invalid deployment/CI YAML: ' + name);
  const data = doc.toJS();
  if (name === 'render.yaml') for (const service of data.services) assert.equal(new Set(service.envVars.map(item => item.key)).size, service.envVars.length, 'Duplicate environment keys');
}
execFileSync('git', ['diff', '--check'], { cwd: root, stdio: 'pipe' });
console.log(`Checks passed: ${paths.length} JavaScript files, ${assets.length} real PWA assets, deployment/CI YAML, sanitized seed and current-tree artifact guard. Git history must be reviewed separately.`);
