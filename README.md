# Alpha Adventist Pre & Primary School

A normal **PHP, HTML, CSS, plain JavaScript and MySQL** school website and staff/family portal. No Docker, Node.js, npm, Composer or asset build is required.

## cPanel: upload two folders, edit one file

**No domain document-root changes or manual PHP path edits are needed.**

```text
/home/YOUR_CPANEL_USERNAME/
├── public_html/                 Website files only
│   ├── index.php                Already wired to the private application
│   ├── setup.php                Protected one-time browser installer
│   ├── .htaccess
│   ├── css/, js/, img/, fonts/
│   ├── offline.html
│   └── service-worker.js
└── alpha-school/                Private application — NOT inside public_html
    ├── config.example.php       Copy to config.php and edit
    ├── src/, data/, scripts/
    ├── database/, test/
    └── private/                 Created automatically; never overwrite on updates
```

1. Back up any existing website. Upload the **contents** of this repository's `public_html` folder into cPanel's existing `public_html`. Upload the `alpha-school` folder alongside it in your account's home directory. Include hidden `.htaccess` files. Do not upload the whole repository into `public_html`.
2. Create an empty MySQL database and user using cPanel's Database Wizard. Assign the user privileges including tables, indexes and triggers. Prefixed names such as `account_alpha_school` work.
3. Copy `alpha-school/config.example.php` to **`alpha-school/config.php`**. Enter the domain, database credentials, SMTP settings, school-approved retention periods and a random setup token. This is the only settings file needed. The private storage path is automatic—no username/path edits.
4. Enable HTTPS, open **`https://yourdomain.com/setup.php`**, enter the setup token and provision the approved system administrator and head teacher. Tables, seed workflows and encrypted file storage are initialized automatically. No Terminal is needed for first installation.
5. Delete `public_html/setup.php`, clear `SETUP_TOKEN` in the private configuration, and add the maintenance cron job from the guide. Test sign-in, forms, email and backups before using live school records.

**Full click-by-click instructions: [cPanel installation](docs/cpanel.md).**

The installer uses HTTPS, a private random token, CSRF checks, same-origin checks, a disk-backed attempt limit and private sessions. It refuses existing accounts, creates both accounts in one serialized transaction, and writes a completion lock. It cannot reset passwords or overwrite existing users. Initial users must change passwords at first sign-in; technical administrators do not automatically receive access to children's records.

## Requirements

- PHP **8.2+** with `pdo_mysql`, `openssl`, `mbstring`, `gd`, `sodium`, `fileinfo`, `session` and normal JSON support. `exif` is recommended for image orientation.
- **MySQL 8** with InnoDB, JSON, generated-column indexes and triggers. MariaDB compatibility has not been verified.
- Standard cPanel Apache rewrite/`.htaccess` support, HTTPS, private writable disk and authenticated SMTP for production alerts.
- One maintenance cron job per minute. No persistent background application process.

DNS must point your registered domain to the hosting server. `NXDOMAIN` is a DNS/registration/delegation problem, not a PHP problem; changing the website files cannot fix it.

## One configuration location

**`alpha-school/config.php`** is a plain PHP file returning a settings array. It holds database/email settings and the temporary setup token, never public assets. The template is `alpha-school/config.example.php`. Keep the real file private (`600`) and never commit/share it.

Environment variables remain available for operators/tests, and an existing private `.env` is accepted only when `config.php` is absent. New installations do not need `.env`. Do not maintain two competing configuration files.

**Existing installation:** do not use the first-install wizard. Preserve the current database, original `DATA_DIR`, encrypted uploads and encryption/audit keys. Keep your existing private configuration or copy its exact settings into `config.php` before switching. The new default private path is only for fresh installs. Read the [upgrade instructions](docs/cpanel.md#updating-an-existing-installation) before uploading.

## Local development

Install PHP and MySQL, and create an empty database (with phpMyAdmin or `alpha-school/database/create-alpha-school.sql`). Copy the PHP configuration template and edit:

- `APP_ENV` → `development`
- `BASE_URL` → `http://localhost:3000`
- Your local MySQL credentials.
- Leave **all** SMTP fields empty if testing without email.
- Set a random `SETUP_TOKEN` if using the browser installer.

From the repository root:

```sh
php -S 127.0.0.1:3000 -t public_html router.php
```

Visit `http://localhost:3000/setup.php`. HTTP setup is allowed only for loopback development, never remote production. The PHP development server is not a production server.

## Optional PHP command-line tools

These run from the repository/account home, not from `public_html`:

```sh
php alpha-school/scripts/check.php
php alpha-school/scripts/init-production.php
php alpha-school/scripts/verify-integrations.php
php alpha-school/scripts/maintenance.php
```

For approved CLI account provisioning instead of the browser installer, use `alpha-school/scripts/bootstrap.php` with a protected password file and `BOOTSTRAP_APPROVED`, `BOOTSTRAP_APPROVAL_REFERENCE`, and `BOOTSTRAP_PASSWORD_FILE` in the private operator environment. There are no default passwords. Do not put passwords in command arguments. See [cPanel installation](docs/cpanel.md#optional-terminal-based-setup).

## Tests

```sh
php alpha-school/test/cpanel.php
php alpha-school/test/integrations.php --configuration-only
php alpha-school/scripts/check.php
```

The first two commands use no live database or email provider. The checker also verifies production PHP extensions. For full business/outbox/recovery tests, provide **three separate empty disposable MySQL schemas** ending in `_test` through `TEST_MYSQL_URL`, `TEST_MYSQL_RESTORE_URL`, and `TEST_MYSQL_INTEGRATIONS_URL`, then:

```sh
php alpha-school/test/run.php
php alpha-school/test/integrations.php
```

For the first-install MySQL regression, provide an additional empty schema through `TEST_MYSQL_SETUP_URL` and run `php alpha-school/test/setup-mysql.php`.

Tests refuse existing tables and do not clear databases automatically. Never use live school data. Real cPanel/Apache, MySQL, email inbox delivery, backups and manual browser/accessibility checks still require hosting verification.

## Features retained and operations

The bilingual website, existing design, forms, staff/family portal, role permissions, consent workflows, append-only audit chain, encrypted private uploads and standard SMTP outbox remain in native PHP/MySQL. Browser scripts preserve menus, galleries, forms and portal interactions without frameworks or builds. Application records retain the `adsp_*` tables.

- [Email and production verification](docs/production-integrations.md)
- [Backup and isolated restore](docs/mysql-migration.md)
- [Operational safety checklist](docs/operations-template.md)
- [API and access governance](docs/api-and-governance.md)

Production still needs approved school appointments, retention values, recipients and recovery procedures. Easier deployment is not permission to publish personal data or weaken security.
