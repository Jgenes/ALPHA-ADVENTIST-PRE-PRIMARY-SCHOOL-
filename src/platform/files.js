'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFile } = require('node:child_process');
const { promisify, TextDecoder } = require('node:util');
const { HttpError, encrypt, decrypt, sha256 } = require('../lib');
const execute = promisify(execFile);
class Files {
  constructor(config) { this.config = config; this.root = path.join(config.dataDir, 'storage'); }
  async init() { for (const area of ['private', 'public', 'quarantine']) await fs.mkdir(path.join(this.root, area), { recursive: true, mode: 0o700 }); }
  keyPath(area, key) {
    if (!['private', 'public', 'quarantine'].includes(area) || !/^[a-f0-9]{48}$/.test(key)) throw new Error('Invalid storage key.');
    return path.join(this.root, area, key);
  }
  async scan(buffer) {
    if (!this.config.scanCommand) throw new HttpError(503, 'Binary uploads are disabled until the school configures its malware scanner. Plain-text documents are supported.', 'SCANNER_REQUIRED');
    const key = crypto.randomBytes(24).toString('hex');
    const file = this.keyPath('quarantine', key);
    try {
      await fs.writeFile(file, buffer, { mode: 0o600, flag: 'wx' });
      // Only an operator-configured executable, never a client command or URL.
      await execute(this.config.scanCommand, ['--no-summary', file], { timeout: 30000, maxBuffer: 64000, windowsHide: true });
    } catch (error) {
      throw new HttpError(422, 'The file could not be verified as safe. It was not accepted.', 'SCAN_FAILED');
    } finally { await fs.rm(file, { force: true }); }
  }
  async prepare(input, imageOnly = false) {
    if (!input || typeof input.content !== 'string' || typeof input.name !== 'string') throw new HttpError(400, 'Choose a file.');
    if (input.content.length > 4 * Math.ceil(this.config.maxUploadBytes / 3) || input.content.length % 4 !== 0 || !/^[A-Za-z0-9+/]*={0,2}$/.test(input.content)) throw new HttpError(413, 'Invalid file encoding or file over 5 MB.');
    let buffer = Buffer.from(input.content, 'base64');
    if (buffer.toString('base64') !== input.content) throw new HttpError(400, 'Invalid base64 encoding.');
    if (!buffer.length || buffer.length > this.config.maxUploadBytes) throw new HttpError(413, 'Choose a nonempty file up to 5 MB.');
    let name = path.basename(input.name).replace(/[^a-zA-Z0-9._ -]/g, '_').slice(0, 100);
    const extension = path.extname(name).toLowerCase();
    let mime; let scanStatus;
    if (imageOnly && ['.jpg', '.jpeg', '.png'].includes(extension)) {
      const png = buffer.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex'));
      const jpeg = buffer.subarray(0, 3).equals(Buffer.from('ffd8ff', 'hex'));
      if (!(png && extension === '.png' || jpeg && extension !== '.png')) throw new HttpError(400, 'Image type does not match its filename.');
      await this.scan(buffer);
      try {
        // Re-encoding drops EXIF/GPS, profiles and ancillary metadata by default.
        buffer = await require('sharp')(buffer, { limitInputPixels: 25000000, animated: false }).rotate().resize(1600, 1600, { fit: 'inside', withoutEnlargement: true }).jpeg({ quality: 84 }).toBuffer();
      } catch { throw new HttpError(400, 'This image is invalid or too large.'); }
      mime = 'image/jpeg'; name = 'school-activity.jpg'; scanStatus = 'CLEAN_REENCODED';
    } else if (!imageOnly && extension === '.txt') {
      let decoded;
      try { decoded = new TextDecoder('utf-8', { fatal: true }).decode(buffer); } catch { throw new HttpError(400, 'Text documents must use UTF-8.'); }
      if (/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(decoded)) throw new HttpError(400, 'Binary data is not a text document.');
      mime = 'text/plain; charset=utf-8'; scanStatus = 'PLAIN_TEXT';
    } else if (!imageOnly && extension === '.pdf') {
      if (!buffer.subarray(0, 5).equals(Buffer.from('%PDF-')) || !buffer.subarray(-1024).includes(Buffer.from('%%EOF'))) throw new HttpError(400, 'Invalid PDF signature.');
      if (/\/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA|RichMedia)\b/i.test(buffer.toString('latin1'))) throw new HttpError(400, 'Active or embedded PDF content is not supported.');
      await this.scan(buffer); mime = 'application/pdf'; scanStatus = 'CLEAN';
    } else throw new HttpError(415, imageOnly ? 'Use a JPEG or PNG image.' : 'Use a plain-text (.txt) or scanned PDF document.');
    const storageKey = crypto.randomBytes(24).toString('hex');
    await fs.writeFile(this.keyPath('private', storageKey), encrypt(buffer, this.config.storageKey), { mode: 0o600, flag: 'wx' });
    return { storageKey, name, mime, size: buffer.length, checksum: sha256(buffer), scanStatus };
  }
  async read(file, area = 'private') {
    const data = await fs.readFile(this.keyPath(area, file.storageKey));
    const buffer = area === 'private' ? decrypt(data, this.config.storageKey) : data;
    if (sha256(buffer) !== file.checksum) throw new Error('File integrity check failed.');
    return buffer;
  }
  async publish(file) {
    const buffer = await this.read(file);
    await fs.writeFile(this.keyPath('public', file.storageKey), buffer, { mode: 0o600, flag: 'wx' }).catch(error => { if (error.code !== 'EEXIST') throw error; });
  }
  async unpublish(file) { if (file?.storageKey) await fs.rm(this.keyPath('public', file.storageKey), { force: true }); }
  async discard(file) { if (file?.storageKey) await fs.rm(this.keyPath('private', file.storageKey), { force: true }); }
}
function publicFileMetadata(file) { return file ? { name: file.name, mime: file.mime, size: file.size, checksum: file.checksum, scanStatus: file.scanStatus } : null; }
module.exports = { Files, publicFileMetadata };
