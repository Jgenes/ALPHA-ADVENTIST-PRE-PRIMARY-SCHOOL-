# Restricted operations manual — sanitised template

**Do not put completed credentials, employee details, guardian evidence, incident details or recovery keys in this repository.** Copy this template into a school-controlled restricted operations system. Record named owners, approval references, contacts and schedules there. This public copy contains procedures, not an operational appointment or a legal compliance certificate.

## 1. Release gates — complete before production

- [ ] School owner accepts the implemented scope and the outstanding items in `delivery-status.md`.
- [ ] Confirm the official domain, DNS ownership, school name/leadership, addresses, office email/phones, academic/day/boarding claims, exam centre PS0603108, legacy news, social accounts and exact Maps pin. No verified pin, new domain mailbox, social account or results PDF is invented by this implementation.
- [ ] Name the safeguarding lead and privacy lead; verify the public reporting channel and incident escalation process. The school/legal adviser must confirm Tanzania’s PDPA 2022/PDPC obligations, registration, lawful bases, processor agreements, cross-border transfers, retention and breach procedures.
- [ ] Review repository history/visibility, forks, clones, previous artifacts and deployed photos; rotate every previously exposed credential. Current-tree deletion and ignore rules alone are insufficient.
- [ ] Reconnect GitHub in Arena if authentication fails. Coordinate any history rewrite with the repository owner and collaborators; do not force-push an unreviewed rewrite or change another branch from this session.
- [ ] Separate development, staging and production databases, storage volumes, secret keys, alert recipients and domains. Never point tests at production or copy real pupil data into development.
- [ ] Rehearse MySQL/MariaDB transactions/concurrency (against a disposable database ending in `_test`), external deliveries and backup/restore on isolated staging. These external paths have not been exercised in this workspace.
- [ ] Approve the risk of accepting PDF and image uploads without malware scanning; train reviewers to accept files only from trusted sources.
- [ ] Appoint independent workflow participants, configure supervisor/departments and entitlements, and complete real school acceptance scenarios.
- [ ] Configure automatic off-site backups/retention, verify the recovery drill, monitoring, disk/database capacity and alert escalation.
- [ ] Approve deployment and rollback. `render.yaml` deliberately disables automatic deployment; the cPanel procedure is in `docs/cpanel-deployment.md`.

## 2. Hosting and environment

The supported production design is **one Node 22.x application instance**, a MySQL/MariaDB database (the cPanel database `alphadve_alpha_school`), and either persistent protected local file storage or the Supabase S3-compatible object-storage adapter. SQLite is for local development/test and the explicitly read-only public preview only. The local file backend is single-instance; do not horizontally scale it without shared storage. The cPanel steps, including schema import and Node.js app environment, are in `cpanel-deployment.md`.

With local storage, `DATA_DIR` holds `storage/private`, `storage/public` and the generated application key file, so it must be persistent. With Supabase, durable private and approved public objects are in the configured bucket, but `DATA_DIR` must still be persistent for keys. Create the bucket as **private** and keep its S3 access keys server-side. Supabase S3 credentials grant broad access to project storage and bypass RLS; use a dedicated Supabase project for this application. The app does not return Supabase object URLs: files are streamed through application authorization, current-version and consent checks. Public copies are separate objects but remain in the private bucket. Never expose storage through bucket listing, direct URLs or a public bucket policy.

PDF and image uploads are accepted without malware scanning. Basic file signatures, PDF active-content patterns, image re-encoding, encryption, access controls and human approval workflows reduce some risks but cannot detect all malicious files. Accept files only from trusted sources, and do not imply an uploaded file is clean.

Use TLS at the trusted reverse proxy, redirect HTTP to HTTPS, disallow direct public access to the Node port, and enable database TLS/encryption-at-rest/backups. Set `TRUST_PROXY_HOPS` to the **actual trusted hop count**, not a guess; the supplied Render template assumes one. Verify client IPs at staging without publishing them. Never enable wildcard trust of arbitrary forwarded headers.

Keep the provider's runtime/environment inventory private. Required startup settings are in `.env.example`. Production/staging require explicit HTTPS origin, MySQL database (`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`), persistent `DATA_DIR`, approved retention, and either a signed alert endpoint or Gmail SMTP with approved office and privacy recipients. The safeguarding contact is managed in the portal’s school-contact settings through independent review and publication; until published, the site directs people to the Head of School’s office. When using Supabase, configure all five `SUPABASE_S3_*` / `SUPABASE_STORAGE_BUCKET` values together. Public content uses the configured origin—not incoming `Host`—for SEO. Non-production responses are marked `noindex`; staging robots also disallow crawling. Protected APIs/portal/downloads are `no-store` and `noindex`.

The HTTPS school site with the **hyphenated** hostname `alpha-adventist-pre-primary-school.onrender.com` was reachable on 30 September 2026. That is a read-only observation, not DNS ownership verification, uptime assurance or deployment of this branch. Configure the school-approved long-term origin explicitly.

## 3. Keys and initial accounts

Keep these independent: `STORAGE_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, the office webhook secret and the backup encryption key. Application keys are random 32-byte values encoded as 64 hex characters. The two application keys cannot be the same. The app can initialize `application-keys.json` under `DATA_DIR` when production key values are not otherwise supplied; this directory **must be a persistent, protected volume**. The cPanel guide uses `/home/<account>/alpha-private`; the Render template mounts its persistent disk at `/var/data`. Escrow the key file separately from backups. Losing a key can make files or audit verification unrecoverable. Legacy encrypted MFA fields are ignored and left untouched.

There is no seeded password. The preferred initial bootstrap requires an owner-approved appointment, a private decision reference and a mode-600 password file. Use different named people/accounts for `system_admin` and `head_teacher`. Usernames are lowercase, 3–64 characters (`a-z`, digits, dot, underscore, hyphen). Passwords must be 12–128 characters with uppercase, lowercase, number and symbol.

1. Prepare the unique temporary password in a protected operator file, never a command argument or chat message.
2. Set `BOOTSTRAP_APPROVED=true`, `BOOTSTRAP_APPROVAL_REFERENCE` and `BOOTSTRAP_PASSWORD_FILE` in that operator environment.
3. Run the appropriate `npm run bootstrap -- --username ... --name ... --role ...` command. It refuses if that initial role already exists.
4. Hand over through the approved channel; first login forces a password change. Remove the temporary password file and bootstrap environment afterwards.
5. Management appoints business roles; ICT may provision ordinary accounts but does not receive HR/finance/contract access or approval rights. Do not combine technical and confidential business roles.
6. HR links employee IDs, departments and supervisors, then records approved leave entitlements. A leave supervisor must hold an appropriate privileged review role (for example Head of Department), not just an ordinary teacher account. Nothing invents a statutory allowance.

If the host has no operator shell, the first `head_teacher` can be created at startup with `HEAD_TEACHER_USERNAME`, `HEAD_TEACHER_NAME`, `HEAD_TEACHER_PASSWORD`, `HEAD_TEACHER_APPROVED=true` and `HEAD_TEACHER_APPROVAL_REFERENCE` in its private environment settings. This is one-time and owner-approved; it never changes an existing account. Remove every `HEAD_TEACHER_*` value immediately after confirming creation. The account must change its temporary password at first sign-in.

Login limits: six failed account attempts and thirty source attempts in ten minutes, durable across restarts. New anonymous sessions are capped at sixty per source per fifteen minutes; upload attempts at thirty per account per ten minutes. Size limits and these application controls complement, but do not replace, hosting-level abuse protection. Sessions: thirty-minute idle, eight-hour absolute; anonymous login sessions are fifteen minutes. Password/role changes revoke existing authority. There are no shared admin passwords or public reset links. Authentication uses username and password only; privileged accounts have no additional factor. This is a deliberate reduction in account security; require unique strong passwords and review hosting-level protections.

**Recovery:** self-service password recovery is not implemented. Use an identity-verified, school-approved operator procedure for lost access. Legacy encrypted MFA fields inside user records are retained but ignored by this version; no `MFA_ENCRYPTION_KEY` is read or required. Do not edit live records ad hoc or use bootstrap as a repeated privilege-grant mechanism.

## 4. Authority and workflow operation

Default routes:

| Process | Sequence |
| --- | --- |
| Leave | Assigned supervisor → HR → Head Teacher |
| Contract | Administration → finance if flagged → management → signatory attestation → employee acknowledgement |
| Controlled document | Administration/authorised reviewer → institutional approval → independent issuance |
| CMS | Editor → approving authority → separate publisher |
| Media | Privacy/consent reviewer → approving authority → publisher |

The author/current-revision editor cannot approve their own work. Contract employee acknowledgement is deliberately an owner action. Several review responsibilities can be held by one authorised person where configured; management must appoint separate people where its segregation policy requires it. Contract management/signature can be the same authorised signatory by default. Do not describe these attestations as certified digital signatures.

Management can create/update workflow definitions and leave-type route/working-week settings. Running requests keep a snapshot of the route/version. Required final authority, contract approval/signature/employee ordering and CMS/media review cannot be removed. Approval operations use revision checks, so duplicate/stale decisions conflict rather than approve twice. Escalation notifies management; it does not silently transfer authority. Delegation and automatic overdue reassignment are not implemented.

Leave return dates are exclusive, non-working weekdays are configurable, and current requests must remain in one calendar year (split cross-year leave with HR guidance). Days, overlaps, reservations and debits are server-calculated. Approval converts the reservation to used days; return/reject/cancel releases it. Approved cancellation is allowed only before the start date. Public holiday calendars/half-days and jurisdiction-specific entitlement rules need further policy work.

Controlled documents have immutable classifications per document, versioned files and published history. Use a new controlled number for a different classification. Superseded issued versions remain accessible only to authorised staff, not via public downloads. Private grants last sixty seconds and bind to user, session, checksum and current authority. They are consumed once. Expiring a link cannot erase a file already downloaded by an authorised person—school device/export handling rules still apply.

## 5. Publication and safeguarding

- Edit/create CMS records in **Website content**, submit, review and approve with the proper independent accounts, then publish or schedule with a publisher. The old published snapshot stays live during revisions.
- Use **School contact details** for telephone, email, postal/location text, verified Maps link and official Facebook/Instagram/YouTube links. These changes require review/publication too. Domain and canonical-origin changes are deployment configuration, not CMS authority.
- The original repository UI is retained. The optimized `public/img/site-*.webp` photos are static public assets, not consent-managed media records; keep the school's release approval with deployment records. School facts and legacy public text still require verification.
- Before uploading any photograph, identify **every** depicted child with a private reference. Record a separately verified guardian grant/refusal for each of website, Facebook, Instagram, YouTube, print and external promotion, with confidential signed evidence, dates and annual expiry.
- The media editor attests to subject identification and no child names in caption/alt text; the privacy officer independently reviews. Human verification is essential; this software does not recognise faces, verify guardians by itself, or certify lawful consent.
- Withdrawal immediately blocks dynamic `/media/` images and removes their public copies. Static `public/img/site-*.webp` photos do not participate in per-request consent checks; remove a withdrawn photo from the site assets and deploy/cache-purge the change. Reconsent does not automatically republish withdrawn dynamic media. Per-request checks block expired consent for dynamic media even if a background job is delayed.
- External posts/printed promotion require human follow-up: document removal within the forty-eight-hour deadline and record completion. Social APIs do not automatically delete content. Purge legacy hosting/CDN/search caches and contact processors where needed.
- Restoring a backup must never resurrect consent withdrawn after its snapshot. Public copies are intentionally not recreated by the restore tool. Reconcile withdrawals before publishing any restored media.
- Restricted privacy requests/incidents are available to designated privacy authority, not the ordinary enquiry desk or ICT. Public safeguarding reports must not be used instead of emergency assistance.

## 6. Office-alert contract

The signed webhook remains supported. As an alternative, Gmail SMTP uses `GMAIL_SMTP_USER`, `GMAIL_SMTP_APP_PASSWORD`, `OFFICE_ALERT_EMAIL` and `PRIVACY_ALERT_EMAIL`. Enable Google 2-Step Verification and create a dedicated app password; revoke any app password exposed in chat or source control and enter a replacement directly in Render's secret settings. Do not commit or send it to operators over chat. SMTP uses `smtp.gmail.com` on port 465 with TLS. Gmail acceptance is not proof of delivery or readership. Emails contain only an alert type, reference, timestamp and authenticated portal link, never form bodies. Safeguarding/privacy alerts go only to `PRIVACY_ALERT_EMAIL`; configure the same mailbox for both recipient settings only if the school has approved that person for both duties.

Set `OFFICE_ALERT_WEBHOOK_URL` to an approved HTTPS integration and use a distinct secret of at least 32 characters. The application sends:

```text
POST to the configured endpoint
Content-Type: application/json
Idempotency-Key: <outbox ID>
X-Alpha-Timestamp: <Unix milliseconds>
X-Alpha-Signature: sha256=<HMAC-SHA256(secret, timestamp + '.' + raw body)>

body fields: id, school, reference, type, receivedAt, officeUrl
```

The receiving integration must validate the signature in constant time, enforce a short clock tolerance/replay protection, deduplicate the idempotency key and validate the expected office-link origin. Keep provider keys/recipient addresses there, not in public JavaScript. Private/safeguarding request references go only to the authorised privacy recipients; do not forward every category to a general staff WhatsApp group.

The sender has a five-second timeout, forbids redirects, leases jobs for sixty seconds, and retries with exponential backoff up to eight attempts. Missing configuration remains PENDING. ICT can inspect delivery metadata/retry jobs, but not form contents. A success code records integration acceptance. Monitor actual downstream deliveries separately; provider callbacks, parent SMS/email acknowledgements and bulk messaging are not implemented. Parents receive an on-screen reference today.

## 7. Monitoring and retention

`/healthz` and `/readyz` check database availability and return no private diagnostics. Monitor HTTPS availability, latency, TLS certificate expiry, disk space, MySQL connection/storage/backup health, app process restarts and backup age. These probes alone do **not** verify deliverability, storage durability or recovery readiness.

The authorised System health page exposes last maintenance success, integration configuration and outbox status without family contents. Maintenance runs on startup and about every minute, expires sessions/grants, applies form retention, publishes eligible schedules, withdraws expired media and sends contract reminders at the nearest 90/60/30/14/7-day threshold. Reminders are idempotent. Monitor job recency and pending/failed alerts; a silent school inbox is not proof of zero submissions.

Record the approved enquiry/application retention periods. Development defaults (90/365 days) are examples, not legal advice. Application retention also applies to enrolled application forms; longer-lived student records do not exist in this phase. Privacy requests have the enquiry-period schedule. Employment documents, consent evidence, audit records and backup copies need a separately approved retention/disposal process; broad automated erasure/legal-hold handling is not implemented. Do not equate removal from the website with erasure from restricted evidence/backups.

Audit events are HMAC-chained, with a transactional sequence/head and no application update/delete route. SQLite and MySQL/MariaDB add append-only triggers. This is tamper evidence, **not protection from a database superuser holding the audit key**. Use least-privilege DB accounts and independent protected audit exports/checkpoints. Do not log passwords, bearer/download tokens, webhook bodies or family form contents at the proxy/provider.

## 8. Backup, restore and rollback

Assign an owner, recovery-time/recovery-point objectives and a monitored schedule. A reasonable starting proposal is nightly encrypted off-site backups, provider point-in-time database protection where available, and a monthly restore drill; the school must approve frequency/retention. **No scheduler or off-site account has been configured by this code change.**

For the bounded archive tool (under 512 MB; use managed/streaming snapshots for larger datasets):

1. Put the site into an approved maintenance window; stop **all** application/job writers. The `BACKUP_WRITES_PAUSED` flag is an operator attestation, not a mechanism that stops the web service.
2. Use explicit `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DATA_DIR`, `DB_BACKUP_DIR`, and the separate backup key/key file. For Supabase file storage, also configure its endpoint, region, server-only S3 keys and private bucket. Set `BACKUP_WRITES_PAUSED=true` only after writers are stopped.
3. Run `npm run backup:mysql`. It reads every `adsp_*` table in one repeatable-read transaction, includes every referenced encrypted private file from local storage or Supabase and produces an AES-256-GCM archive with a checksum. A missing referenced file fails the backup. App keys and public copies are not included. Also schedule the provider-level cPanel/MySQL backup; two independent copies are expected.
4. Resume the service, verify readiness, transfer the archive to approved encrypted off-site storage, verify checksum/upload and apply approved retention. Monitor failed runs and aged/missing archives. A backup on the same application disk is not a recovery strategy.
5. Store app/storage/audit/backup keys separately under controlled escrow. Do not attach them to the archive or Git.

Restore drill:

1. Use a non-production operator environment, `RESTORE_APPROVED=true`, a **separate empty** cPanel database named `<name>_restore_test` (for example `alphadve_alpha_school_restore_test`) selected with `DB_RESTORE_NAME`, and empty `RESTORE_DATA_DIR` distinct from live storage. For a Supabase archive, configure `SUPABASE_RESTORE_BUCKET` as a separate empty bucket, different from both the source and live buckets; provide the S3 endpoint/region/credentials for that isolated project.
2. Run `npm run restore:mysql -- /protected/archive.json.enc`. The tool authenticates the archive/checks file hashes, refuses a non-empty target database, verifies table counts and restores private files to local storage or the separate Supabase restore bucket. Sessions, login limits and download grants are deliberately cleared. A failed partial target must remain isolated; investigate and choose a fresh approved database and bucket rather than overwriting it.
3. Start the recovered app only on the isolated network, with the correct separately escrowed application keys, no real alert recipients and jobs disabled for the initial inspection. Never run restored outbox work against live recipients by accident.
4. Verify audit integrity, known record counts/checksums, private-file decryption and all role/workflow denial scenarios. Reconcile writes/withdrawals after the snapshot. Reissue verified public documents and review photographs; the tool does not automatically restore public availability.
5. Record evidence, actual RPO/RTO and approval. Recovery cutover/production DNS or database switching is a separate reviewed operation, not this restore command.

The automated tests include a real isolated SQLite/file archive restoration with intact audit chain, wrong-key/corruption failures and file-decryption checks, plus a MySQL adapter rehearse with an in-memory statement double. A real MySQL restore rehearsal requires `npm run test:mysql` against a disposable `_test` database; neither substitutes for certifying the hosting provider’s disaster recovery.

For deployment rollback, keep the previous code release and an approved compatible data/storage backup. Do not run the legacy insecure application against the new private store or roll back security fixes while exposing old photos/files. A schema/data rollback needs an approved maintenance/reconciliation procedure.

## 9. Legacy data

The `adsp_` namespace does not automatically trust any earlier `users`, `platform_state`, JSON files or static uploads. The previous MongoDB importer and MongoDB backup scripts were removed when the project moved to MySQL/MariaDB; there is no supported automatic import from the old MongoDB deployment.

If old school data must be carried over, treat it as a one-off project: inventory and lawfully retain the source, export only the fields that are needed, obtain school/legal approval, import through a reviewed script into a disposable MySQL database, and compare counts and consent status before any cutover. Never import old password hashes, old accounts or unverified photographs, and never assume previous consent. Public text must enter the CMS as reviewable `DRAFT` content.

## 10. Acceptance evidence and private handover

Retain private evidence for: request references/actual office receipt for all five forms, staff cross-record denials, multi-stage leave and contract workflows, controlled-version issuance, scoped notices, CMS author/publisher separation, school-setting changes, consent expiry/withdrawal, restricted incidents, unscanned upload risk acceptance, PWA cache inspection, mobile use, provider failures, backup/restoration and independent security review.

Automated checks are useful evidence, not a penetration-test report, legal approval, successful deployment or full screen-reader/manual-accessibility assessment. Store completed owner appointments, approvals, provider agreements, incident procedures and recovery credentials outside this public repository.

### Embedded-preview sign-in

Development-only HTTPS e2b.app preview cookies are Secure, SameSite=None and Partitioned, allowing login inside the Arena frame without changing production SameSite=Lax protection. The browser refreshes CSRF before authentication operations and retries a rejected login token once. For browsers that still block embedded cookies, use the login page’s **Open the portal in a new tab** link. Do not disable CSRF or origin checks to work around a browser-cookie issue. Development preview accounts/storage are ephemeral; they are not production provisioning.
