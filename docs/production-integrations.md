# Production: alpha_school, SMTP and persistent storage

## Selected deployment

- **MySQL 8**, database **`alpha_school`**, with verified TLS. SQLite is development-only; production fails rather than silently falling back.
- **Your authenticated SMTP provider**, not Gmail. Use STARTTLS (normally port 587) or implicit TLS (normally 465). Certificate/hostname verification and TLS 1.2+ are required; plaintext SMTP is not supported. The provider must support password-based `AUTH LOGIN` or `AUTH PLAIN` over TLS; OAuth-only services need a separate adapter.
- **Persistent server disk** (one application instance), `STORAGE_DRIVER=local`, normally `DATA_DIR=/var/data`. Private uploads use authenticated AES-256-GCM encryption outside `public/`. An explicitly approved public media copy is served through the application's existing publication route, not by exposing the storage directory.

The code and configuration templates implement these choices. They **do not provision a hosted MySQL service, register an email domain, mount a provider disk, or supply credentials**. No live provider has been connected or emailed during development.

## 1. Enter hosting secrets

Use `.env.production.example` as a checklist, not a populated source-controlled file. Set values in the hosting dashboard/secret manager and give the web process, setup CLI and maintenance worker the same configuration.

Required MySQL values:

- `MYSQL_URL` using your provider endpoint and the path `/alpha_school`; URL-encode special characters in its username/password. Alternatively use `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER` and `MYSQL_PASSWORD` or `MYSQL_PASSWORD_FILE`.
- `MYSQL_DB_NAME=alpha_school`, `MYSQL_SSL_MODE=verify-full`.
- Provider CA via `MYSQL_TLS_CA_FILE` or `MYSQL_SSL_CA` if it is not trusted by the system CA store. The negotiated connection must actually be encrypted. Self-signed/unverified fallback is forbidden.
- A dedicated database account, not a shared administrator. Schema initialization needs table/index/trigger DDL privileges plus application read/write access. The one-time database creation needs separate `CREATE DATABASE` permission; normally use the provider's SQL console for this.

Required SMTP values:

- `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURITY=starttls` or `tls`.
- `SMTP_USERNAME` and `SMTP_PASSWORD` **or** `SMTP_PASSWORD_FILE`. The authentication username need not be an email address.
- Provider-verified `SMTP_FROM_EMAIL`; optional single-line `SMTP_FROM_NAME` (default Alpha School).
- Approved `OFFICE_ALERT_EMAIL` and `PRIVACY_ALERT_EMAIL`. Safeguarding/privacy alerts go only to the latter; other office alerts go to the former.

Password files must be regular files, not symlinks, with permissions **600 or stricter**. Do not put passwords in commands, Git, screenshots or chat. Clear old `GMAIL_SMTP_*`, `OFFICE_ALERT_WEBHOOK_*` and `SUPABASE_*` settings for this selected deployment. If a legacy webhook is left enabled, the existing worker prioritizes it rather than SMTP. Generic SMTP and Gmail settings cannot be mixed.

Also configure the verified HTTPS `BASE_URL`, institutional retention periods and `RETENTION_POLICY_APPROVED=true` only after actual approval. Provider domain verification/SPF/DKIM/DMARC and sending limits must be configured with your email provider; application code cannot establish these DNS records on its own.

## 2. Create and initialize alpha_school

For a new installation, run `database/create-alpha-school.sql` in the approved provider SQL console, then:

```sh
php scripts/init-production.php
```

Alternatively, if your operator account has approved database-creation privileges:

```sh
MYSQL_SCHEMA_CREATE_APPROVED=true php scripts/init-production.php --create-database
```

Remove the creation approval/elevated privilege afterwards. The command only creates the fixed **alpha_school** schema if absent, then initializes the existing `adsp_*` application tables, indexes, audit triggers and seed workflows. It does not drop, rename, copy or migrate another database. Table initialization also remains idempotent during normal startup, but **requests never create the database itself**.

**Existing installation:** do not simply change its schema setting. Pause writes, back up and rehearse an approved data/key/file migration into `alpha_school`; preserve the existing application keys and audit chain. Production rejects other schema names and mismatching `MYSQL_URL`/`MYSQL_DB_NAME` rather than silently writing into the wrong database. Isolated staging/recovery schemas retain their existing guarded procedures.

Provision initial staff authorities with the approved `scripts/bootstrap.php` procedure in the README. These setup commands never print or invent account passwords.

## 3. Persistent disk ownership and recovery

Mount the persistent disk at `/var/data` before starting PHP. Run the CLI and web worker as the same application account; with the provided Docker image this is **www-data**. Otherwise a root-owned setup directory may prevent web requests from accessing storage. Parent paths must be real, private, application-controlled directories, not shared writable paths or symlinks.

The selected disk directories `${DATA_DIR}/storage/{private,public}` are created owner-only (0700); stored objects are 0600. Setting `STORAGE_DRIVER=local` prevents residual S3 configuration from silently selecting another provider. Do not use an ephemeral container filesystem or mount `DATA_DIR` under the Apache document root.

`STORAGE_ENCRYPTION_KEY` and `AUDIT_HMAC_KEY` must be distinct 64-hex secrets. If blank, they are generated once in `${DATA_DIR}/application-keys.json` on the persistent disk. Escrow these keys separately; preserve them across deployments and backups. Lost keys make encrypted files unreadable. Database backups alone do not recover files: take coordinated database/disk snapshots and rehearse the protected restore procedure. Monitor disk space, backups and ownership.

## 4. Verify without personal data

```sh
# Database/schema + temporary encrypted-file round-trip + SMTP config validation.
# Does NOT send email; the storage probe deletes its synthetic file.
php scripts/verify-integrations.php

# Diagnose one integration at a time with the same complete environment.
php scripts/verify-integrations.php --check=database
php scripts/verify-integrations.php --check=storage
php scripts/verify-integrations.php --check=email

# Explicit opt-in: send one synthetic reference-only message to the approved mailbox.
php scripts/verify-integrations.php --check=email --send-test=office
php scripts/verify-integrations.php --check=email --send-test=privacy
```

`--send-test` accepts only `office` or `privacy`, never an arbitrary address. Sending uses the real provider's TLS authentication and selected configured recipient, but contains no child names or form details. **SMTP acceptance is not proof of inbox arrival:** confirm both messages in the approved mailboxes and check spam/provider logs. The sender must be authorized by your provider.

The database probe uses the application store, so it can initialize missing schema objects; use the approved setup environment, not a read-only monitoring credential. It never clears existing records. Config-only email checking does not make a network connection. Live MySQL/TLS, SMTP and native container behavior must still be validated on the real hosting system.

## 5. Worker and release checks

The Docker container already runs the maintenance worker. For non-container hosting, run `php scripts/maintenance.php` once a minute under the application account with the same protected environment; do not run both schedulers.

Alerts are persisted in the MySQL outbox; failed SMTP attempts use exponential backoff and stop after eight attempts. Connection deadlines stay below the outbox lease. Emails contain a type/reference/time and authenticated portal link, not submitted personal details. A lost QUIT reply after SMTP acceptance does not trigger a duplicate retry. Overall external delivery is still **at-least-once**, not exactly-once: network/process failure after acceptance but before a database commit can still cause duplicates. System administrators can inspect redacted delivery status through the existing system API; credentials are never returned.

Before release, verify `/readyz`, approved authority sign-in/password change, synthetic public forms, both mailboxes, private download authorization, encrypted bytes on the persistent disk, worker retries and backup/restore. Do not enable automatic deployment or migrate live school records solely because the local tests pass.

## Development regression checks

```sh
php scripts/check.php
php test/run.php
php test/integrations.php
```

The integration tests use scripted SMTP replies and a disposable private disk directory. They check configuration rejection, credential-file permissions, TLS-before-auth ordering, LOGIN/PLAIN, rejected recipients/authentication, MIME/privacy routing, retry exhaustion and audit integrity. They never contact an external SMTP host or the live `alpha_school` schema. CI separately exercises the general MySQL suite on disposable **alpha_school_test**.
