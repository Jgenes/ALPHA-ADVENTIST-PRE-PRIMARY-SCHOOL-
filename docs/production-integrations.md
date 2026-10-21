# PHP production email, files and verification

Start with [cPanel installation](cpanel.md). All integrations here are implemented in native PHP; no provider SDK or hosted application platform is required.

## MySQL

Create a dedicated MySQL 8 database/user with cPanel and set its full name in `alpha-school/config.php`. Prefixed names are supported. `MYSQL_URL` is an optional alternative to individual connection fields; URL-encode special characters in URL credentials, and keep its database consistent with `MYSQL_DB_NAME`.

```sh
php alpha-school/scripts/init-production.php
php alpha-school/scripts/verify-integrations.php --check=database
```

Initialization is idempotent and does not drop, rename or copy existing data. The user needs schema/table/index/trigger privileges as well as application read/write access. On local installations only, an approved database owner may use `MYSQL_SCHEMA_CREATE_APPROVED=true php alpha-school/scripts/init-production.php --create-database` to create the configured schema. This is not needed on cPanel. Remove elevated privileges/approval after setup where possible without preventing application schema checks.

Same-host loopback MySQL (`localhost` or `127.0.0.1`) can disable database TLS. Remote production MySQL must use verified TLS, a matching hostname, and the system trust store or the provider CA specified in `MYSQL_TLS_CA_FILE` / `MYSQL_SSL_CA`. There is no unverified-certificate fallback.

## Standard SMTP

Configure these in the private `alpha-school/config.php`:

- `SMTP_HOST`, `SMTP_PORT` (normally `587`), `SMTP_SECURITY=starttls` or `tls` (normally port `465`).
- `SMTP_USERNAME`, `SMTP_PASSWORD` or `SMTP_PASSWORD_FILE` (not both).
- Provider-verified `SMTP_FROM_EMAIL` and optional `SMTP_FROM_NAME`.
- School-approved `OFFICE_ALERT_EMAIL` and `PRIVACY_ALERT_EMAIL`.

The provider must support password-based `AUTH LOGIN` or `AUTH PLAIN` over verified TLS 1.2+. OAuth-only email needs a separately reviewed adapter. Configure the domain's SPF/DKIM/DMARC and sending limits with the provider. The application cannot establish these DNS records itself.

A secret file must be a regular non-symlink file, permissions `600` or stricter. Do not put passwords in commands, screenshots, Git or chat.

```sh
php alpha-school/scripts/verify-integrations.php --check=email
php alpha-school/scripts/verify-integrations.php --check=email --send-test=office
php alpha-school/scripts/verify-integrations.php --check=email --send-test=privacy
```

Checking configuration alone does not send mail or connect to SMTP. The explicit send commands use synthetic reference-only messages, not child details. SMTP acceptance is not proof of inbox arrival: check both inboxes and spam/provider logs.

Office alerts remain in a MySQL outbox. The cron job claims them and sends messages containing a type/reference and an authenticated portal link, not submitted form contents. Privacy and safeguarding alerts go only to the privacy recipient. Failures back off exponentially and stop after eight attempts. Delivery remains at-least-once; a process failure after provider acceptance can still cause a duplicate. Monitor pending/failed alerts and maintenance recency.

## Local encrypted files

Use `STORAGE_DRIVER=local` and a persistent `DATA_DIR` outside every domain document root. PHP and CLI commands must run under the same account or compatible ownership. Directories are private (`700`), files are `600`. Do not use shared writable folders or symlinks.

Private uploads use AES-256-GCM. Distinct `STORAGE_ENCRYPTION_KEY` and `AUDIT_HMAC_KEY` values are 64-hex secrets; if blank, they are generated once in `DATA_DIR/application-keys.json`. Escrow them separately. Lost keys make files unreadable. Preserve keys, database and private files together during updates and migrations.

```sh
php alpha-school/scripts/verify-integrations.php --check=storage
```

The synthetic storage probe tests an encrypted round trip and removes its test file. It does not verify off-site durability. The database probe may initialize missing schema objects; run it only with approved setup credentials.

## Maintenance and release

Schedule `php alpha-school/scripts/maintenance.php` once a minute through Cron Jobs. There is no daemon or alternative scheduler to install. Keep cron output/failure notifications private.

Before launch, verify `/readyz`, approved staff sign-in/password changes, synthetic forms, both real mailboxes, private download authorization, encrypted bytes on disk and a complete [backup/restore rehearsal](mysql-migration.md). Production needs HTTPS and approved institutional retention values; passing automated tests is not permission to publish school records.
