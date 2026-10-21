# cPanel installation — PHP and MySQL

## 1. Hosting requirements

Use PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `gd`, `sodium`, and the normal JSON/fileinfo/session extensions. Enable `exif` if available. Select the PHP version in **MultiPHP Manager** or **Select PHP Version**. The command-line PHP executable must use the same compatible version.

Ask your host to confirm:

- MySQL 8 with InnoDB, generated-column indexes, JSON fields and triggers. This release has not been verified against MariaDB.
- Apache rewrite rules and `.htaccess` support.
- A domain document root pointing at the project's `public/` directory.
- Terminal/SSH access, or support assistance to run the PHP setup commands.
- Cron Jobs and outbound access to your authenticated SMTP server.

No application dependency installation or asset build is necessary.

## 2. Upload the files securely

Upload a ZIP using **File Manager**, then extract it into:

```text
/home/USERNAME/alpha-school/
```

This directory should contain `public`, `src`, `scripts`, `data` and the configuration examples. Exclude `.git`, `.arena`, local runtime files, test credentials and old backups from the upload.

In **Domains**, set the domain's document root to:

```text
/home/USERNAME/alpha-school/public
```

Enable **Show Hidden Files** in File Manager and confirm `public/.htaccess` exists. Remove any hosting placeholder `index.html` from that document root if it hides the application.

**If your main domain is fixed to `public_html`, ask your host to configure the document root, or use a domain/subdomain with an editable root. Do not put the entire project into `public_html` or move only `index.php`: the application relies on its private sibling directories.**

Enable HTTPS using **SSL/TLS Status / AutoSSL**, and redirect HTTP to HTTPS.

## 3. Create the MySQL database

Use **MySQL Database Wizard** (sometimes labelled **Manage My Databases**):

1. Create a new, empty database, for example `USERNAME_alpha_school`.
2. Create a dedicated database user with a strong unique password.
3. Assign that user to the database with the privileges required by the application, including table/index/trigger creation and read/write access. Do not use a shared database administrator.

The full prefixed database and user names must be used in `.env`. Do not rename or drop existing databases. Creating the database with cPanel is preferred; `--create-database` is not needed.

## 4. Configure the application

Copy `.env.production.example` to `.env` in `/home/USERNAME/alpha-school/`, not inside `public/`. Replace every placeholder. For a typical same-host database:

```dotenv
APP_ENV=production
BASE_URL=https://your-school-domain.example
DATA_DIR=/home/USERNAME/alpha-school-private
TRUST_PROXY_HOPS=0

MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_DB_NAME=USERNAME_alpha_school
MYSQL_USER=USERNAME_schooluser
MYSQL_PASSWORD=your-private-password
MYSQL_URL=
MYSQL_SSL_MODE=

STORAGE_DRIVER=local
```

Keep the `MYSQL_URL` field blank when using separate host/user/password fields. Do not put credentials in Git, chat or screenshots. The simple `.env` reader supports single-line `KEY=value` values, optionally surrounded by quotes; it does not expand shell variables or support inline comments. Avoid wrapping spaces or quote characters in directly configured secrets; a protected `*_PASSWORD_FILE` preserves those characters if needed.

An empty `MYSQL_SSL_MODE` selects the connection policy automatically: `localhost` or `127.0.0.1` may use a local unencrypted connection. **Remote production MySQL requires `verify-full`**, a matching server hostname and trusted CA. Set `MYSQL_TLS_CA_FILE` to your provider's CA file if necessary; do not disable certificate verification.

Configure all SMTP settings and both approved recipient addresses:

```dotenv
SMTP_HOST=mail.your-school-domain.example
SMTP_PORT=587
SMTP_SECURITY=starttls
SMTP_USERNAME=your-provider-username
SMTP_PASSWORD=your-private-email-password
SMTP_FROM_EMAIL=website@your-school-domain.example
SMTP_FROM_NAME=Alpha School
OFFICE_ALERT_EMAIL=approved-office@your-school-domain.example
PRIVACY_ALERT_EMAIL=approved-privacy@your-school-domain.example
```

Use your real provider's settings. For implicit TLS use port `465` and `SMTP_SECURITY=tls`. Configure SPF/DKIM/DMARC with the provider. Production refuses incomplete SMTP configuration rather than silently losing alerts.

Set the school-approved `FORM_RETENTION_DAYS`, `ADMISSION_RETENTION_DAYS`, and `RETENTION_POLICY_APPROVED=true`. Do not treat sample retention values as approval.

Create `/home/USERNAME/alpha-school-private` outside all domain document roots. Set permissions to `700`, and `.env` to `600`, with ownership allowing the PHP hosting account to use them. Do not use `777`. Application keys are generated once there if not explicitly supplied; escrow them separately and preserve them on every update.

`TRUST_PROXY_HOPS=0` is appropriate for direct Apache HTTPS. Only change it if the host confirms a trusted reverse proxy and its hop count. In **MultiPHP INI Editor**, disable `display_errors`; use `post_max_size=8M` and `upload_max_filesize=5M` or higher as appropriate. Application file uploads remain capped at 5 MB.

## 5. Initialize and verify

In **Terminal**:

```sh
cd /home/USERNAME/alpha-school
php -v
php scripts/check.php
php scripts/init-production.php
php scripts/verify-integrations.php
```

Use the host's PHP 8.2+ executable if `php -v` shows an older version. A common cPanel path is `/opt/cpanel/ea-php83/root/usr/bin/php`, but ask your provider for the actual path. Setup initializes the application tables, indexes and append-only audit triggers in the configured database. It does not create, drop or migrate the database by default.

Provision the approved system administrator and head teacher using the **Initial accounts** procedure in the README. There are no default passwords. If Terminal is unavailable, ask the host to run the commands; do not upload an unauthenticated web setup script.

## 6. Configure Cron Jobs

Choose **Once Per Minute**:

```cron
* * * * * cd /home/USERNAME/alpha-school && /usr/bin/php scripts/maintenance.php
```

If cPanel has separate schedule fields, enter only the command after the five `*` fields. Substitute the actual PHP path confirmed above. Run this once manually first to verify file permissions and configuration. Keep cron failure notifications private and enabled. No background daemon is required.

## 7. Launch checks

- Open the homepage and several routes; ensure CSS, images, menus and portal controls work.
- Check `/readyz`. Check that `/.env`, `/src/php/support.php` and private storage files cannot be downloaded.
- Verify approved staff sign-in, password change and role boundaries.
- Run synthetic form and private upload/download tests without real child data.
- Send explicit synthetic email checks and confirm arrival in both approved inboxes:

  ```sh
  php scripts/verify-integrations.php --check=email --send-test=office
  php scripts/verify-integrations.php --check=email --send-test=privacy
  ```

- Verify cron recency, encrypted storage and coordinated database/file/key backups. Rehearse restore before using live records.

For troubleshooting, inspect protected PHP/server logs. Never enable public stack traces or share credentials. A database setup error may indicate an unsupported server version or missing trigger privileges; ask your host rather than disabling audit protections.
