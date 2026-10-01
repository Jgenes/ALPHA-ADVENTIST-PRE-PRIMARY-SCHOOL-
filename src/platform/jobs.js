'use strict';
const crypto = require('node:crypto');
const A = require('./access');
const { audit } = require('./audit');
const { notify } = require('./workflows');
const { validMediaConsent, mediaIsPublic } = require('./privacy');
async function maintenance(platform) {
  const { store, config, files } = platform;
  const now = Date.now();
  await store.run(async tx => {
    for (const name of ['sessions', 'login_limits', 'download_grants']) {
      for (const record of await tx.list(name)) if (record.expiresAt <= now) await tx.remove(name, record.id);
    }
    for (const record of await tx.list('system_settings')) if (record.id.startsWith('submission-key:') && record.expiresAt <= now) await tx.remove('system_settings', record.id);
    for (const name of ['submissions', 'admissions', 'privacy_requests']) {
      let purged = 0;
      for (const record of await tx.list(name)) {
        if (record.expiresAt > now) continue;
        await tx.remove(name, record.id); purged++;
        for (const alert of await tx.list('outbox')) if (alert.reference === record.id) await tx.remove('outbox', alert.id);
      }
      if (purged) await audit(tx, config, { userId: 'system' }, 'retention.purge', name, '', 'success', { count: purged });
    }
    for (const content of await tx.list('cms_content')) {
      if (content.status !== 'SCHEDULED' || Date.parse(content.publishAt) > now) continue;
      const publisher = await tx.get('users', content.publishedBy);
      const flow = await tx.get('workflow_instances', content.workflowId);
      if (!A.has(publisher, 'cms.publish') || flow?.status !== 'APPROVED' || content.mediaId && !await mediaIsPublic(tx, content.mediaId)) {
        await tx.update('cms_content', { ...content, status: 'APPROVED', publishAt: null });
        await notify(tx, content.createdBy, 'Scheduled publication needs a new publisher or media consent check', '/portal/cms', content.id);
        await audit(tx, config, { userId: 'system' }, 'cms.scheduled_publish', 'cms_content', content.id, 'denied');
        continue;
      }
      if (content.published) content.history.push(content.published);
      content.published = { kind: content.kind, slug: content.slug, title: content.title, body: content.body, content: content.body.split(/\n\s*\n/), excerpt: content.excerpt, category: content.category, language: content.language, mediaId: content.mediaId, seoTitle: content.seoTitle, seoDescription: content.seoDescription, eventDate: content.eventDate, version: content.version, publishedAt: content.publishAt };
      await tx.update('cms_content', { ...content, status: 'PUBLISHED' });
      await audit(tx, config, { userId: 'system' }, 'cms.scheduled_publish', 'cms_content', content.id);
    }
    for (const media of await tx.list('media')) {
      if (media.status === 'PUBLISHED' && !await validMediaConsent(tx, media)) {
        await tx.update('media', { ...media, status: 'WITHDRAWN' });
        await files.unpublish(media.file);
        await audit(tx, config, { userId: 'system' }, 'media.consent_expired', 'media', media.id);
      }
    }
    const users = await tx.list('users');
    for (const contract of await tx.list('contracts')) {
      if (contract.status !== 'ACTIVE') continue;
      const days = Math.ceil((Date.parse(contract.endDate + 'T23:59:59Z') - now) / 86400000);
      const applicable = [90, 60, 30, 14, 7].filter(threshold => days <= threshold);
      if (!applicable.length) continue;
      const threshold = Math.min(...applicable);
      const recipients = new Set([contract.ownerId, contract.supervisorId, ...users.filter(user => user.active && user.roles.some(role => ['hr_officer', 'school_admin'].includes(role))).map(user => user.id)].filter(Boolean));
      for (const userId of recipients) {
        // Only a reminder reference is sent to Administration/supervisors, not
        // contract contents. Opening the record still requires its own permission.
        await notify(tx, userId, days > 0 ? `Contract ${contract.reference} expires in ${days} days` : `Contract ${contract.reference} has expired`, '/portal/notifications', contract.id, `contract-reminder:${contract.id}:${threshold}:${userId}`);
      }
      if (days <= 0) {
        await tx.update('contracts', { ...contract, status: 'EXPIRED' });
        await audit(tx, config, { userId: 'system' }, 'contract.expire', 'contract', contract.id);
      }
    }
    const old = await tx.get('system_settings', 'maintenance');
    const record = { id: 'maintenance', lastSuccessAt: new Date(now).toISOString() };
    if (old) await tx.update('system_settings', { ...old, ...record }); else await tx.insert('system_settings', record);
  });
}
async function deliverAlerts(platform, fetcher = fetch, mailer = null) {
  const { store, config } = platform;
  if (!config.alertUrl && !mailer) return; // Persisted pending state is visible to operators.
  for (let index = 0; index < 10; index++) {
    const alert = await store.run(async tx => {
      const record = (await tx.list('outbox')).find(item => (['PENDING', 'RETRY'].includes(item.status) && item.nextAttemptAt <= Date.now()) || item.status === 'SENDING' && item.lockedUntil <= Date.now());
      if (!record) return null;
      return tx.update('outbox', { ...record, status: 'SENDING', attempts: record.attempts + 1, lockedUntil: Date.now() + 60000 });
    });
    if (!alert) break;
    let delivered = false, httpStatus = 0;
    try {
      if (config.alertUrl) {
        const timestamp = String(Date.now());
        const payload = JSON.stringify({ id: alert.id, school: 'Alpha Adventist Pre & Primary School', reference: alert.reference, type: alert.type, receivedAt: alert.createdAt, officeUrl: config.baseUrl + alert.href });
        const signature = crypto.createHmac('sha256', config.alertSecret).update(timestamp + '.' + payload).digest('hex');
        const response = await fetcher(config.alertUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': alert.id, 'X-Alpha-Timestamp': timestamp, 'X-Alpha-Signature': 'sha256=' + signature }, body: payload, signal: AbortSignal.timeout(5000), redirect: 'error' });
        httpStatus = response.status; delivered = response.ok;
        if (response.body?.cancel) await response.body.cancel();
      } else {
        const privateAlert = ['Safeguarding concern', 'Privacy request'].includes(alert.type);
        const recipient = privateAlert ? config.smtp.privacyEmail : config.smtp.officeEmail;
        const result = await mailer.sendMail({
          from: config.smtp.from,
          to: recipient,
          subject: `[Alpha school alert] ${alert.type} ${alert.reference}`,
          text: [
            'An authorised school team member should review this new request.',
            `Type: ${alert.type}`,
            `Reference: ${alert.reference}`,
            `Received: ${alert.createdAt}`,
            `Open the secure record: ${config.baseUrl + alert.href}`,
            'The request details are not included in this email.'
          ].join('\n')
        });
        delivered = (result.accepted || []).some(address => String(address).toLowerCase() === recipient.toLowerCase());
        httpStatus = delivered ? 250 : 550;
      }
    } catch { /* No response body, secret, child or parent details are logged. */ }
    await store.run(async tx => {
      const current = await tx.get('outbox', alert.id);
      if (!current || current.status !== 'SENDING' || current.attempts !== alert.attempts) return;
      await tx.update('outbox', { ...current, status: delivered ? 'DELIVERED' : current.attempts >= 8 ? 'FAILED' : 'RETRY', lastStatus: httpStatus, nextAttemptAt: Date.now() + Math.min(24 * 60, 2 ** current.attempts) * 60000, deliveredAt: delivered ? new Date().toISOString() : null, lockedUntil: 0 });
      await audit(tx, config, { userId: 'system' }, 'notification.delivery', 'outbox', alert.id, delivered ? 'success' : 'failure', { httpStatus, attempt: current.attempts });
    });
  }
}
function startJobs(platform) {
  let pending = null, stopped = false;
  const mailer = platform.config.smtp ? require('nodemailer').createTransport({
    host: 'smtp.gmail.com', port: 465, secure: true,
    auth: { user: platform.config.smtp.user, pass: platform.config.smtp.password },
    connectionTimeout: 5000, greetingTimeout: 5000, socketTimeout: 10000
  }) : null;
  const run = () => {
    if (stopped) return Promise.resolve();
    if (pending) return pending;
    pending = (async () => {
      try { await maintenance(platform); await deliverAlerts(platform, fetch, mailer); }
      catch (error) { console.error('[maintenance] job failed; review health and storage access.', error.name); }
    })().finally(() => { pending = null; });
    return pending;
  };
  const timer = setInterval(run, 60000);
  timer.unref();
  return { run, async stop() { stopped = true; clearInterval(timer); if (pending) await pending; } };
}
module.exports = { maintenance, deliverAlerts, startJobs };
