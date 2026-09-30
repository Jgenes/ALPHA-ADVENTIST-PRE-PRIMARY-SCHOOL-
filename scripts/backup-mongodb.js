'use strict';
require('dotenv').config();
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { MongoClient } = require('mongodb');
const { EJSON } = require('bson');

function required(name, value) {
  if (!value) throw new Error(`${name} is required.`);
  return value;
}

async function main() {
  const uri = required('MONGODB_URI', process.env.MONGODB_URI || process.env.MONGO_URI);
  const dbName = required('MONGODB_DB_NAME', process.env.MONGODB_DB_NAME || process.env.MONGO_DB_NAME);
  const keyHex = required('MONGO_BACKUP_ENCRYPTION_KEY or MONGO_BACKUP_ENCRYPTION_KEY_FILE', process.env.MONGO_BACKUP_ENCRYPTION_KEY || (process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE && fs.readFileSync(process.env.MONGO_BACKUP_ENCRYPTION_KEY_FILE, 'utf8').trim()));
  if (!/^[a-f0-9]{64}$/i.test(keyHex)) throw new Error('MONGO_BACKUP_ENCRYPTION_KEY must be 64 hexadecimal characters.');

  const outputDir = path.resolve(process.env.MONGO_BACKUP_DIR || path.join(os.homedir(), '.local', 'share', 'alpha-adventist', 'backups'));
  fs.mkdirSync(outputDir, { recursive: true, mode: 0o700 });
  fs.chmodSync(outputDir, 0o700);
  const dirMode = fs.statSync(outputDir).mode & 0o777;
  if (dirMode !== 0o700) throw new Error('Backup directory must have permissions 700.');

  const client = new MongoClient(uri, { serverSelectionTimeoutMS: 10000, appName: 'AlphaAdventistBackup' });
  try {
    await client.connect();
    const database = client.db(dbName);
    const definitions = await database.listCollections({}, { nameOnly: false }).toArray();
    const collections = [];
    for (const definition of definitions.filter(item => !item.name.startsWith('system.'))) {
      let indexes = [];
      try {
        indexes = (await database.collection(definition.name).listIndexes().toArray())
          .filter(index => index.name !== '_id_')
          .map(({ key, name, unique, sparse, expireAfterSeconds, partialFilterExpression, collation }) => ({ key, name, unique, sparse, expireAfterSeconds, partialFilterExpression, collation }));
      } catch (error) {
        if (definition.type !== 'view') throw error;
      }
      collections.push({
        name: definition.name,
        indexes,
        options: {
          validator: definition.options.validator,
          validationLevel: definition.options.validationLevel,
          validationAction: definition.options.validationAction
        },
        documents: await database.collection(definition.name).find({}).toArray()
      });
    }

    const payload = Buffer.from(EJSON.stringify({ database: dbName, exportedAt: new Date(), collections }, { relaxed: false }));
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(keyHex, 'hex'), iv);
    const encrypted = Buffer.concat([cipher.update(payload), cipher.final()]);
    const archive = Buffer.concat([Buffer.from('ALPHAMDB'), iv, cipher.getAuthTag(), encrypted]);
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const filePath = path.join(outputDir, `${dbName}-${stamp}.ejson.enc`);
    const fd = fs.openSync(filePath, 'wx', 0o600);
    try {
      fs.writeFileSync(fd, archive);
      fs.fsyncSync(fd);
    } finally {
      fs.closeSync(fd);
    }
    fs.chmodSync(filePath, 0o600);
    const count = collections.reduce((total, collection) => total + collection.documents.length, 0);
    console.log(`Encrypted MongoDB backup created: ${filePath} (${collections.length} collections, ${count} documents)`);
  } finally {
    await client.close();
  }
}

main().catch(error => {
  console.error('[backup] failed; verify configuration, directory permissions, and MongoDB access.', error.name, error.code || '');
  process.exitCode = 1;
});
