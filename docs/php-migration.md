# PHP conversion and cutover notes

## Scope

The conversion replaces the Node server, server-rendered templates, platform service modules, authentication, persistence/storage adapters, jobs and operator scripts with PHP. Browser scripts and all visual assets are retained. `package.json` now contains optional browser-testing tools only. No Node installation is required to serve the school website or portal.

Routes are unchanged, including `/api/*`, `/portal/*`, public downloads/media, language routes, health endpoints, manifest, sitemap, robots and service worker.

## UI preservation evidence

The original renderer from the starting checkout was run against the same published seed/context as the PHP renderer before removing the server-side JavaScript. Full HTML output was byte-for-byte identical for these 15 main public pages:

- Home, About, Academics, Admissions, Computer Learning
- School Life, Faith, Parents, Students, Gallery
- Contact, Privacy, Downloads, Safeguarding, Talents

`test/render-hashes.json` records hashes of the original page bodies so PHP regressions can continue to check markup parity without retaining the Node runtime. The CSS, fonts, photographs, public browser scripts, offline page and service worker are unchanged.

The English/Kiswahili pages, news listing, staff portal screens and mobile views also pass the browser regression suite. This is a behavioural/markup preservation check, not a claim that every hosting environment has been certified.

## PHP validation performed

- Syntax/extension checks across PHP source, scripts and tests.
- 92 synthetic SQLite checks covering forms/idempotency, permissions, guardian/classroom scopes, attendance/results, admissions/files, leave reservations and approvals, employment-contract stages, controlled documents, independent CMS/media publication, consent withdrawal, private downloads, audit integrity, optimistic locking, encrypted storage, maintenance, backup references, legacy-content quarantine and render hashes.
- Real Chromium smoke tests: public pages, responsive layouts, carousel, parent dialogs, gallery/lightbox, Kids Zone typing, enquiry submission, staff portal screens and accessibility checks on the existing key pages.
- HTTPS cross-site iframe regression: partitioned secure/HttpOnly cookies, privileged login, bounded CSRF retry and blocked-cookie fallback.

The sandbox lacks a native PHP executable; PHP checks and browser-server tests were executed with a PHP 8.5 WebAssembly CLI installed in ignored tooling storage. **That tool is not an application dependency and is not part of the production deployment.** CI is configured for native PHP 8.3, SQLite, a disposable MySQL 8.4 service, and Chromium. Production MySQL/TLS, Supabase S3, actual SMTP/webhook delivery, legacy scrypt verification under sodium and the Apache container require host-specific rehearsal. The sodium regression vector runs automatically where the extension is installed.

## Data compatibility

PDO retains the original SQLite `adsp.sqlite` file and MySQL `adsp_*` tables. Records remain JSON payloads with revisions and code-owned uniqueness/index rules. Private file bytes retain the original AES-256-GCM layout (12-byte IV, ciphertext, 16-byte tag). Backups retain the `ALPHAMYS` envelope (magic, IV, tag, ciphertext); approved import also accepts retired `ALPHAMDB` archives.

Audit signatures use sorted-key canonical JSON and the existing HMAC key. Database triggers and application methods prohibit edits/deletes of audit records. MySQL transaction advisory locks serialize collection-wide validation and audit extension, matching the single-writer semantics used in local SQLite development.

Legacy Node password records use the matching native sodium scrypt parameters. New PHP records use SHA-256-prehashed bcrypt with cost 12, preserving the 12–128 character password policy without truncating at bcrypt's 72-byte boundary. Install sodium before migrating accounts.

## Operational changes

- Use `APP_ENV` instead of `NODE_ENV` in new deployments. `NODE_ENV` remains a compatibility fallback for existing environment files.
- Use PHP/Apache or PHP-FPM rather than `npm start` / `node server.js` in production. The included Render template uses Docker.
- Only `public/` may be web-accessible. The development command must include `router.php` to retain clean routes.
- PHP request lifetimes do not support Node's in-process interval scheduler. The container starts a PHP worker; other hosts must schedule `php scripts/maintenance.php` every minute. Do not enable both scheduling methods.
- MySQL schemas must be pre-created. Production uses verified TLS; supply a provider CA through `MYSQL_TLS_CA_FILE` or `MYSQL_SSL_CA` when it is not in the system trust store. The old `required`, `verify-ca`, connection-pool and socket settings are not used by this PDO configuration.
- SMTP delivery is implemented using verified TLS and the SMTP protocol; S3 storage uses server-side AWS Signature V4. No PHP package installation is needed at runtime.
- Restore uses an explicitly approved isolated `_restore_test` schema and an empty local directory. Supabase archives are restored to local quarantine, not automatically into a bucket. Review them before transferring to a new isolated bucket.
- The authenticated application routes now extract the actual application ID after `/api/my/admissions/`; the old router's shared segment index would select `admissions` instead of that ID.

## Release checklist

1. Pause writes and back up the database/files; escrow app keys separately.
2. Rehearse in isolated storage/schema with the required PHP extensions.
3. Verify password sign-in/change, role separation, guardian scope, leave/contract approvals, document versions and single-use downloads.
4. Verify the full audit chain and decrypt/check representative private files.
5. Reconcile media-consent withdrawals since any backup; never automatically restore public file copies.
6. Configure and test verified database TLS, alerts and maintenance scheduling.
7. Confirm deployment document root, persistent disk ownership, HTTPS origin/proxy settings and health checks.
8. Obtain institutional/privacy/release approval before cutover. Keep the prior release and encrypted backup available for a controlled rollback.

No live deployment, real data migration, role appointment or production publication was performed as part of this code conversion.
