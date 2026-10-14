# Alpha Adventist Digital School Platform — PHP

The school website and governed staff/family portal now run on **PHP 8.2+** (the production container uses PHP 8.3). There is no Node.js application server and no frontend build step. Existing CSS, JavaScript, photographs, fonts, routes, forms and portal interactions are retained.

## Run locally

Install PHP with `pdo_sqlite`, `openssl`, `mbstring`, `curl`, `gd` and `sodium`. Production additionally needs `pdo_mysql`; `exif` preserves image orientation while stripping metadata.

```sh
cp .env.example .env
php scripts/check.php
php -S 0.0.0.0:3000 -t public router.php
```

Visit `http://localhost:3000`. SQLite is created in the protected, ignored `DATA_DIR` (`.runtime` by default). No database credentials are needed for local public-page development. **Do not use PHP's built-in server in production.**

Composer is optional: `composer check`, `composer test`, `composer start` and `composer maintenance` are convenience commands. The application has no third-party PHP runtime packages.

### Initial accounts

There are no default passwords or public registration endpoints. Provision initial authorities using an approved appointment and a private password file:

```sh
# Create this file privately, outside the public directory; never commit it.
chmod 600 /secure/initial-password.txt
export BOOTSTRAP_APPROVED=true
export BOOTSTRAP_APPROVAL_REFERENCE='your-approved-appointment-reference'
export BOOTSTRAP_PASSWORD_FILE=/secure/initial-password.txt
php scripts/bootstrap.php --username=system-admin --name='System Administrator' --role=system_admin
# Repeat with the separately approved head_teacher appointment and password file.
```

An initial account must change its password on first sign-in. Management grants business roles through the portal. Technical administrators cannot grant themselves business access. Remove the temporary password files after secure handover.

Environment provisioning through `ADMIN_PASSWORD` and the complete owner-approved `HEAD_TEACHER_*` settings remains supported. Never place real credentials in source control.

## What is preserved

- The public school website, English/Kiswahili pages, carousel, gallery filters/lightbox, parent dialogs and Kids Zone games.
- Public enquiry, visit, admission, computer-training, safeguarding and privacy forms with consent, throttling and submission receipts.
- Staff profiles, leave balances/approvals, employment contracts and controlled document versioning.
- Verified guardian/learner/classroom scopes, attendance, independently published results and learning materials, and family communication.
- Independent CMS/media review and publication, child consent checks and withdrawals, notices, acknowledgements, calendars and notifications.
- Session rotation/expiry, CSRF and same-origin checks, role permissions, audit integrity, encrypted files and single-use private download grants.
- Office-alert queues, retention maintenance, scheduled publication and contract reminders.
- Public manifests, offline support, robots, sitemap and health endpoints.

**Browser JavaScript remains JavaScript intentionally.** PHP handles HTTP routing, rendering, persistence and business rules; keeping the browser scripts is necessary to preserve the original UX.

## Project layout

| Location | Purpose |
| --- | --- |
| `public/index.php` | Production front controller |
| `router.php` | Local development router |
| `src/php/pages_*.php`, `layout.php` | PHP versions of the original page templates |
| `src/php/platform_*.php` | Permissions, family/staff services and governed workflows |
| `src/php/runtime.php` | PHP collection/date/formatting helpers used by the migrated code; no JS evaluation |
| `src/php/support.php`, `auth.php`, `store.php`, `files.php`, `http.php` | Native PHP configuration, security, PDO persistence, encrypted storage and HTTP services |
| `src/php/jobs.php`, `platform_jobs.php` | Alert delivery and scheduled maintenance |
| `src/php/operations.php`, `legacy-migration.php` | Guarded backup/restore and legacy archive migration |
| `public/css`, `public/js`, `public/img`, `public/fonts` | Original assets, unchanged |
| `data/seed.json`, `src/content/home-slides.json` | Existing published information and homepage slides |
| `scripts/*.php` | PHP operator commands |
| `test/run.php`, `test/integrations.php` | Synthetic business/security and SMTP/storage regressions |
| `scripts/*ui*.js` | Optional browser-test tooling only |

The migrated templates and service methods keep the original structure and use shared PHP helpers to preserve the old collection semantics. They are ordinary PHP files: no generator, Node subprocess, JS interpreter or transpilation step is needed to run or modify the application.

## Persistence and existing installations

The PHP PDO store retains the original `adsp_*` table names, `id/revision/payload` records, MySQL generated-column indexes, SQLite file name, encryption envelope and audit-chain algorithm.

For a Node-to-PHP cutover:

1. Pause writes and take an encrypted backup; separately escrow the application-key file.
2. Rehearse on an isolated schema/storage directory. Never point test fixtures at live data.
3. Preserve `DATA_DIR`, the storage encryption/audit keys and the existing schema. **Do not regenerate application keys.**
4. Install PHP `sodium`. Existing `scrypt-N32768-r8-p1` passwords are verified using the matching native scrypt parameters. New passwords use SHA-256-prehashed bcrypt to avoid the bcrypt 72-byte truncation issue.
5. Invalidate old sessions/download grants during a recovery, reconcile consent withdrawals, verify the audit chain and test private downloads before an approved release.
6. Remove the Node start command and use the PHP production deployment below.

Changing the language is not an approval to deploy to production. Confirm institutional facts, access appointments, retention schedules, lawful consent and recovery procedures with the school.

## Production deployment

### Docker / Render

`Dockerfile` uses PHP 8.3 + Apache with `public/` as the only web root. It also starts a PHP maintenance worker under the web-service account. `render.yaml` is a **Docker** deployment template with a persistent `/var/data` disk and `/readyz` health check.

```sh
docker build -t alpha-school-php .
# Use a verified production environment file and persistent protected data volume.
docker run --env-file /secure/alpha-production.env -p 8080:80 \
  -v alpha-school-data:/var/data alpha-school-php
```

Set `APP_ENV=production`, a verified HTTPS `BASE_URL`, persistent `DATA_DIR`, **MySQL database `alpha_school`**, `STORAGE_DRIVER=local`, approved retention periods and your authenticated SMTP provider with approved office/privacy recipients. MySQL production connections require `MYSQL_SSL_MODE=verify-full`; use the system CA trust store or supply `MYSQL_TLS_CA_FILE` / `MYSQL_SSL_CA` as appropriate for the provider.

### Database, email and storage setup

Use [the production integration runbook](docs/production-integrations.md) and `.env.production.example` to supply hosting secrets, initialize `alpha_school`, verify encrypted disk storage and explicitly test both SMTP recipients. Setup does not silently rename existing databases or send email.

```sh
# After the provider has created alpha_school and the protected environment is ready:
php scripts/init-production.php
php scripts/verify-integrations.php
# Explicit opt-in synthetic email, not an automatic test:
php scripts/verify-integrations.php --check=email --send-test=office
```

The application integration is implemented; live MySQL/TLS and inbox delivery still require your real provider endpoint/secret settings and operator verification.

### PHP hosting / Apache

Point Apache's document root at `public/`, enable `mod_rewrite`, and allow the included `.htaccess`. Never expose the repository root, `.env`, `src/`, `data/` or `DATA_DIR`. Configure PHP to disable public error display and allow an 8 MB request body (uploads are still limited to 5 MB by the application).

For installations not using the container worker, schedule maintenance every minute under the application account:

```cron
* * * * * cd /srv/alpha-school && /usr/bin/php scripts/maintenance.php
```

The cron job must receive the same protected environment as the web process. Maintenance is not an HTTP endpoint. Use only one scheduling method; database transactions and outbox claims prevent overlapping workers from delivering the same claim concurrently. External delivery remains at-least-once; webhook receivers should honour the idempotency key.

## Operator tools

All operational commands are PHP:

```sh
php scripts/check.php
php scripts/bootstrap.php --username=... --name=... --role=...
php scripts/maintenance.php
php scripts/backup-mysql.php
php scripts/restore-mysql.php /secure/backup.json.enc
php scripts/import-mongodb.php /secure/legacy-backup.json.enc
php scripts/migrate-mysql.php /secure/legacy-backup.json.enc
```

Backup requires `BACKUP_WRITES_PAUSED=true` and a separate protected backup encryption key. The `ALPHAMYS` AES-GCM archive format remains compatible with existing archives. Restore requires an explicit approved, pre-created isolated MySQL schema ending in `_restore_test` and an empty `RESTORE_DATA_DIR`; existing records are never overwritten. Restored sessions and grants are cleared and public file copies are not recreated.

Object-storage archives restore into isolated **local** storage; review private files before transferring them to a new bucket. Legacy import requires explicit approval and a separate MySQL target. Legacy public text becomes a draft, and retired accounts/media are not automatically granted access or republished. See `docs/php-migration.md` for cutover details.

## Tests

```sh
php scripts/check.php
php test/run.php
php test/integrations.php
# Optional MySQL regression against a disposable schema, never a live schema:
TEST_MYSQL_URL='mysql://user:password@127.0.0.1:3306/alpha_school_test' php test/run.php
```

The PHP suite covers public submissions/idempotency, permissions and family scopes, applications/files, leave/contract/document/CMS/media workflows, consent withdrawal, audit integrity, optimistic conflicts, encrypted storage, maintenance and original-renderer markup hashes. It uses synthetic records only.

Optional browser tests need Node **only as a development tool**, not as an application dependency:

```sh
npm ci
npx playwright install --with-deps chromium
npm run test:ui
```

They launch an isolated PHP server and check public pages, responsive layouts, original interactions, forms, portal screens, accessibility, cross-site HTTPS iframe sign-in, partitioned cookies and bounded CSRF recovery. `PHP_BINARY` and `CHROMIUM_EXECUTABLE` can override the testing executables.

CI runs PHP checks, SQLite/disposable MySQL tests, synthetic SMTP/storage tests and the optional browser checks. Live MySQL/TLS, SMTP inbox delivery, persistent-disk durability and the deployment container must still be rehearsed with the actual hosting configuration before release.
