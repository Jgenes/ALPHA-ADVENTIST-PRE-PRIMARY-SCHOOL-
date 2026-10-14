# Delivery status — PHP conversion

## Implemented

- PHP public/portal rendering and clean routing, preserving the original assets and markup.
- Native PHP authentication, PDO SQLite/MySQL persistence, encrypted local/private S3 storage and alert delivery.
- PHP staff, family, applications, controlled-document, CMS/media, consent, workflow and governance services.
- PHP bootstrap, maintenance, backup, isolated restore and legacy archive import commands.
- PHP/Apache Docker deployment, PHP Render configuration and native-PHP CI jobs.
- PHP synthetic tests and optional browser regression tests against a PHP server.
- Selected production `alpha_school` schema, authenticated non-Gmail SMTP, pinned encrypted persistent disk and explicit setup/verification commands.

## Verified in the coding environment

- PHP syntax and extension checks.
- 92 SQLite synthetic regressions.
- 56 synthetic production-integration checks: SMTP/TLS ordering, MIME/privacy routing, rejected settings/replies, retry/audit behavior and local encrypted disk.
- Byte-identical original/public-renderer comparison for 15 main pages.
- Chromium public/mobile/interaction/portal/accessibility regressions.
- Cross-site HTTPS iframe authentication regression and bounded CSRF recovery.

## Release gates still open

The conversion is **not** a production-release approval. Verify native PHP 8.3 deployment, sodium-based legacy password sign-in, MySQL/TLS, SMTP inbox delivery (S3/webhook validation only if those alternatives are enabled), persistent-disk ownership, maintenance scheduling, backup/restore rehearsal, institutional contacts, approved account appointments and lawful retention/media consent before release.

No live data, credentials, role appointments, deployment or publication was changed. See [PHP migration](php-migration.md) for the complete conversion record.
