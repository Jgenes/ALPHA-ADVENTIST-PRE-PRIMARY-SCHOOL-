# School operations and release checklist

This is a template, not a legal opinion, security certification, completed recovery drill or approval to publish school data. Keep completed appointments, provider agreements, incident procedures and credentials in a controlled private location.

## Ownership and approvals

- [ ] Confirm the school's name, contacts, public facts and content before publication.
- [ ] Appoint a system administrator and a head teacher through recorded owner approval. Do not give technical administrators business access by default.
- [ ] Assign independent content authors/publishers and scoped staff/guardian roles.
- [ ] Approve lawful consent, retention schedules, safeguarding/privacy recipients and file-publication rules.
- [ ] Assign a backup/recovery owner, incident-response contacts and recovery objectives.
- [ ] Review limitations and API/access rules in [API and governance](api-and-governance.md).

## Hosting and configuration

Follow [cPanel installation](cpanel.md). Use PHP 8.2+, MySQL 8, Apache HTTPS, private local disk and standard SMTP. Serve only `public_html/`. Keep `config.php`, application source, backups, private records and keys outside the document root. Disable public error display; never use permissions `777`.

Production requires an HTTPS `BASE_URL`, writable private `DATA_DIR`, dedicated MySQL database, approved retention settings and complete SMTP configuration. Use the full cPanel database name. Same-host loopback MySQL can use a local connection; remote production MySQL requires verified TLS. Use the actual hosting PHP path in both CLI commands and cron.

Keep storage/audit/backup keys independent. Back them up separately, never rotate them casually, and never put them in Git or support screenshots. A technical stack change does not authorize data migration or new account appointments.

## Staff and family access

- [ ] Provision approved initial authorities with a protected password file and first-sign-in password change. Remove temporary files afterward.
- [ ] Check role separation, cross-record denials, guardian/learner scopes and staff classroom assignments using synthetic records.
- [ ] Review staff changes and revoke former users promptly. Never share administrator accounts.
- [ ] Check consent expiry/withdrawal and independent review before publishing photographs, results or documents.
- [ ] Review private upload handling and malware-scanning risk. The application does not provide an antivirus service; restrict upload/download authority and obtain appropriate risk review.

## Scheduled work and monitoring

Run `php alpha-school/scripts/maintenance.php` every minute through the hosting account's cron. Monitor job recency, PHP errors, failed/pending outbox work, HTTPS availability, TLS expiry, disk space, database capacity and backup age. Keep operational logs private and exclude personal form contents/passwords/tokens.

`/healthz` and `/readyz` check database availability. They do not certify mail delivery, storage durability, recovery readiness or institutional compliance. Verify both approved email inboxes, not just an SMTP success response. Privacy/safeguarding email contains a reference/link and goes to the restricted mailbox, never a general staff group.

Document approved enquiry/application retention periods. Development defaults are examples, not legal advice. Employment documents, consent evidence, audit logs, backups and legal holds need separately approved handling; removing a website copy is not erasure from restricted evidence/backups.

Audit events are HMAC-chained and protected by append-only MySQL triggers. This is tamper evidence, not protection against a database superuser who also holds the audit key. Restrict database administrators and keep independent protected audit checkpoints.

## Backup and recovery

Follow [backup and isolated restore](mysql-migration.md). Stop all writers for coordinated snapshots, verify file/database/key coverage, transfer encrypted backups off-site and rehearse restore into isolated empty storage/schema. Never run restored jobs against live recipients during inspection. Monitor failed/aged backups and record actual recovery time. Code updates alone do not establish an off-site backup schedule.

## Launch and handover

- [ ] Verify public pages, mobile layouts, menus, galleries, bilingual content and portal screens in real browsers.
- [ ] Verify synthetic forms, scoped notices, staff workflows, consent withdrawal and private downloads.
- [ ] Confirm both real mailboxes, cron recency, retries and encrypted bytes on private disk.
- [ ] Verify `.env`, source, scripts, backups and private files cannot be downloaded.
- [ ] Rehearse restore, record limitations and obtain a release decision.
- [ ] Review accessibility and security independently; automated checks are not a full audit.

Existing externally stored files or a different database require a separately reviewed migration before switching to this PHP/MySQL-only release. No legacy import or publication is automatic.
