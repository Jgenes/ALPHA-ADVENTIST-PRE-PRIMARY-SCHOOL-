# Alpha Adventist Pre & Primary School

A standard **HTML, PHP, CSS and MySQL** school website and staff/family portal.
PHP renders the HTML and handles forms, authentication and database access using PDO. Stylesheets, fonts and photographs are served directly. Small browser JavaScript files power menus, galleries, forms and portal interactions; they do not need a build step.

**No Docker, Node.js/npm, Composer, frontend framework, cloud-storage service or deployment platform is required.** Upload the files to PHP hosting and configure MySQL. No dependency-install or compilation command is needed.

## Requirements

- PHP **8.2+**, with `pdo_mysql`, `openssl`, `mbstring`, `gd`, `sodium`, and standard JSON/fileinfo/session support. `exif` is recommended for image orientation.
- **MySQL 8**, InnoDB, and a dedicated database user. Schema setup requires table/index/trigger privileges. MariaDB compatibility has not been verified.
- Apache with `mod_rewrite` and `.htaccess` support for production (typical cPanel hosting).
- HTTPS, private writable storage outside the document root, standard authenticated SMTP for production email, and one cron job for maintenance.

## Deploy on cPanel

Follow **[the cPanel installation guide](docs/cpanel.md)**. In brief:

1. Upload the project outside `public_html` and set the domain's document root to the project's `public/` directory.
2. Create an empty database and user through cPanel's Database Wizard. Full cPanel-prefixed names such as `account_alpha_school` are supported.
3. Copy `.env.production.example` to `.env` and replace all placeholders. Keep `.env` private with permissions `600`.
4. Run these commands from the project directory, using the hosting account's PHP 8.2+ executable:

   ```sh
   php scripts/check.php
   php scripts/init-production.php
   php scripts/verify-integrations.php
   ```

5. Create the approved initial staff accounts and schedule `php scripts/maintenance.php` once a minute.

Use `MYSQL_HOST=localhost` for a same-host cPanel MySQL service. TLS can be disabled only for local loopback production database connections; remote production connections must use verified TLS. Never expose `.env`, `src/`, `data/`, scripts, backups or private storage on the web.

## Local development

Install PHP and MySQL (for example through a local PHP hosting stack). Create a dedicated empty `alpha_school` database with phpMyAdmin, or use `database/create-alpha-school.sql` under a database-owner account.

```sh
cp .env.example .env
```

Edit `.env` with your MySQL user/password, then:

```sh
php scripts/check.php
php scripts/init-production.php
php -S 0.0.0.0:3000 -t public router.php
```

Open `http://localhost:3000`. The PHP built-in server is for development only. The application uses MySQL in both development and production; there is no alternate database fallback.

## Initial accounts

There are **no default login passwords or public registration endpoints**. Use an approved appointment and a protected password file outside the public directory:

```sh
chmod 600 /home/USERNAME/initial-password.txt
export BOOTSTRAP_APPROVED=true
export BOOTSTRAP_APPROVAL_REFERENCE='your-approved-appointment-reference'
export BOOTSTRAP_PASSWORD_FILE=/home/USERNAME/initial-password.txt
php scripts/bootstrap.php --username=system-admin --name='System Administrator' --role=system_admin
```

Create the password file privately with a strong, unique password, not a command-line password argument. Repeat with the separately approved `head_teacher` appointment and password file. Delete the temporary password files after secure handover. Users must change their passwords at first sign-in. Management grants business roles through the portal; a technical administrator does not automatically receive access to children's records.

## Project structure

```text
public/             Only web-accessible directory
  index.php         PHP entry point
  .htaccess         Apache routing
  css/              Stylesheets
  js/               Browser interactions (no build tools)
  img/, fonts/      Website assets
src/php/            PHP templates, authentication and school business logic
src/content/        Homepage slide content
data/seed.json      Initial website/workflow content (not live records)
database/           Optional local database-creation SQL
scripts/            PHP setup, maintenance, verification and backup tools
test/               PHP tests
.env                Private database/email configuration (not committed)
router.php          Local PHP development router
```

The existing website design, bilingual pages, school forms, staff/family portal, role checks, consent workflows, audit chain and encrypted private uploads are retained. Application records use the existing `adsp_*` MySQL tables. Setup does not drop, rename or migrate an existing database.

## Maintenance and recovery

- Cron: run `php scripts/maintenance.php` every minute under the hosting account. No continuously running worker is needed.
- [Email, encrypted files and production checks](docs/production-integrations.md).
- [Database backup and isolated restore](docs/mysql-migration.md).
- [Operational safety checklist](docs/operations-template.md).
- [API and access governance](docs/api-and-governance.md).

**Existing installation:** preserve your MySQL data, private files and original encryption/audit keys. Do not generate new keys for existing encrypted records. Deploy a rehearsed backup first. Storage adapters and legacy archive import tools from previous deployments are no longer included; move any externally stored files through a separately reviewed migration before using this release.

## Tests

No browser-testing packages or package manager are required. Run:

```sh
php scripts/check.php
php test/integrations.php --configuration-only
```

The second command checks configuration, encrypted disk storage and simulated SMTP without connecting to a database or sending email.

Full business/outbox/recovery tests require **three separate, empty disposable MySQL databases** whose names end in `_test`. Set `TEST_MYSQL_URL`, `TEST_MYSQL_RESTORE_URL`, and `TEST_MYSQL_INTEGRATIONS_URL` in your private test environment, then run:

```sh
php test/run.php
php test/integrations.php
```

Never point tests at live school data. Tests refuse existing tables and do not automatically clear databases. Use fresh disposable schemas for each run. The PHP/MySQL CI workflow uses the runner's normal MySQL service, not container services. Live hosting, email inbox delivery, backups and manual browser/accessibility checks must still be verified before launch.
