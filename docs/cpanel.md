# Deploy directly to cPanel public_html

You do **not** need to change your domain's document root, edit PHP include paths, install packages, build assets or use Terminal for the first installation.

## 1. Check hosting and domain

In **Select PHP Version / MultiPHP Manager**, select PHP 8.2+ and enable `pdo_mysql`, `openssl`, `mbstring`, `gd`, `sodium`, `fileinfo` and `session` (plus `exif` if available). Ask the host to confirm MySQL 8, InnoDB/JSON/generated-column/trigger support, Apache rewrites, Cron Jobs and outbound SMTP. MariaDB compatibility has not been verified.

Your domain must point to the hosting account. If the browser says `DNS_PROBE_FINISHED_NXDOMAIN`, ask the registrar/host to verify nameserver delegation and the authoritative DNS zone/A record. Uploading PHP files does not fix DNS. Enable the SSL certificate with **SSL/TLS Status / AutoSSL** and HTTPS redirection before setup.

## 2. Upload the two folders

Download the updated source ZIP. In **File Manager → Home**, you should see your existing `public_html` folder. Back up any previous website before replacing files.

- Upload the repository's **`alpha-school` folder** into Home, beside `public_html`.
- Upload the **contents** of the repository's **`public_html` folder** into your existing `public_html`.
- Enable **Settings → Show Hidden Files** and include the `.htaccess` files.
- Do not upload `.git`, `.github`, `.arena`, old credentials, private runtime data or backups. `README.md`, `docs` and the local `router.php` do not need to be uploaded to production.

The final layout must be:

```text
/home/YOUR_USERNAME/
├── alpha-school/
│   ├── config.example.php
│   ├── src/
│   ├── scripts/
│   ├── data/
│   ├── database/
│   ├── test/
│   └── .htaccess
└── public_html/
    ├── index.php
    ├── setup.php
    ├── .htaccess
    ├── css/
    ├── js/
    ├── img/
    ├── fonts/
    ├── offline.html
    └── service-worker.js
```

Avoid an extra ZIP-created nesting folder. `alpha-school/src` and `public_html/index.php` must exist at these exact relative locations. The supplied `index.php` already loads its sibling application. **Do not edit its require path.** Keep all private source/configuration outside `public_html`; the private folder's `.htaccess` is only defense in depth, not a reason to put it on the web.

Remove a hosting placeholder `public_html/index.html` if it hides the PHP application. Only do this after backing up any existing site. This layout assumes `public_html` is your domain's web root; addon domains with a different root need their own separate deployment arrangement.

## 3. Create the database in cPanel

Open **MySQL Database Wizard / Manage My Databases**:

1. Create a new empty database, for example `account_alpha_school`.
2. Create a dedicated user, for example `account_schooluser`, with a strong unique password.
3. Add the user to that database with the required privileges, including tables/indexes/triggers and read/write access. cPanel's **All Privileges** for this dedicated schema is suitable for initial setup. Do not use a shared database administrator.

Use the **full names including the cPanel prefix**. You do not need to import a full SQL dump for a new site: the protected installer creates application tables. Do not drop, rename or point first-install setup at an existing school's database.

## 4. Edit one private PHP configuration file

In File Manager, copy:

```text
alpha-school/config.example.php → alpha-school/config.php
```

Edit **`alpha-school/config.php` only**. It is a PHP array, so keep the quotes, commas and `return [...]` structure. If a password contains an apostrophe or backslash, escape it inside a PHP single-quoted string (`\'` or `\\`). Do not paste real secrets into chat/screenshots or Git.

Enter:

- `BASE_URL`: your exact HTTPS origin, e.g. `https://alphadventist.ac.tz`, with no path.
- `MYSQL_HOST`, `MYSQL_DB_NAME`, `MYSQL_USER`, `MYSQL_PASSWORD`: your actual database details.
- Complete SMTP host, port, security, username/password, verified sender and both school-approved recipient addresses. Typical combinations are `587` / `starttls` or `465` / `tls`; use your provider's actual values. If using cPanel Email Accounts, **Connect Devices / Set Up Mail Client** normally shows the SMTP details. Ensure SPF/DKIM/DMARC through the email provider.
- `FORM_RETENTION_DAYS` and `ADMISSION_RETENTION_DAYS`: approved integer periods, 1–3650. Set `RETENTION_POLICY_APPROVED` to `true` only after actual school approval.
- `SETUP_TOKEN`: a unique random secret of at least **32 characters**, generated with cPanel's password generator or a password manager. This is not an account password. Do not use a predictable phrase or put it in the URL.

For a **new installation**, leave `DATA_DIR` as `__DIR__ . '/private'`: private storage and keys are created automatically inside the private application folder. No cPanel username/path edits are needed. Blank encryption/audit keys are generated once; never lose or overwrite them. Set the real `config.php` to permissions **600**. The hosting PHP account must be able to read it and create private storage. Directories are `700`; do not use `777`.

Leave `MYSQL_SSL_MODE` blank for automatic policy. Same-host `localhost`/`127.0.0.1` may use a local unencrypted connection. Remote production MySQL requires verified TLS with a matching hostname and provider/system CA. Do not disable certificate verification. Leave `TRUST_PROXY_HOPS=0` unless the host confirms a trusted reverse proxy and the actual hop count.

In **MultiPHP INI Editor**, disable `display_errors`, use an adequate memory limit and permit an 8 MB request body. The application still limits each upload to 5 MB. Keep logs private.

## 5. Run the protected browser installer

Visit:

```text
https://YOUR_DOMAIN/setup.php
```

1. Enter the private setup token from `config.php`. It is sent in a POST body, never in the URL. The owner unlock lasts 15 minutes; ten token attempts are allowed per 15-minute window across sessions.
2. Enter the separately approved **system administrator** and **head teacher** names and distinct usernames.
3. Use different strong passwords for each account (12–128 characters, uppercase, lowercase, number and symbol), and confirm them.
4. Enter the recorded appointment approval reference and confirm authority to create both accounts.
5. Submit. Setup checks PHP requirements and production configuration, initializes the MySQL tables/indexes/audit triggers/workflows, creates both accounts atomically and writes a private completion lock. It never creates/renames/drops the database itself and refuses any database that already has user accounts.

Passwords are hashed; the form never reprints them. A password change is required at first sign-in. The technical administrator does not automatically receive business access to children's records. Management grants business roles through the portal.

**After success:** delete **`public_html/setup.php`** and clear **`SETUP_TOKEN`** in `config.php`. Leave `private/setup-complete.lock` in place. Setup is not a password-reset tool. Protect the generated private keys and take coordinated backups.

The installer requires HTTPS and same-origin requests, uses CSRF protection/private HttpOnly sessions, rejects oversized POSTs and cannot overwrite accounts even if the completion marker is missing. If Terminal is unavailable, none is needed for these first-install steps.

## 6. Add the maintenance cron job

Open **Cron Jobs**, select **Once Per Minute**, and enter only this in the **Command** field:

```sh
/usr/local/bin/php "$HOME/alpha-school/scripts/maintenance.php"
```

`$HOME` supplies your account directory automatically. Confirm your host's PHP 8.2+ CLI path: it may instead be `/usr/bin/php` or `/opt/cpanel/ea-php83/root/usr/bin/php`. Keep private cron failure notifications enabled. This single PHP command sends queued alerts and runs retention/scheduled workflows; no daemon is required.

## 7. Test before entering live records

- Open the homepage, images/CSS, several pages, bilingual content and portal on desktop/mobile.
- Check `/readyz`, approved sign-in/password change, account separation and guardian/classroom scopes.
- Test synthetic forms, encrypted uploads and private download authorization.
- Confirm cron recency and real delivery to both approved inboxes; SMTP acceptance is not proof of inbox arrival.
- Verify `config.php`, `.env`, private files, PHP source and backups cannot be downloaded. Rehearse a coordinated database/files/keys restore.
- Review consent, retention, recipient approval and manual accessibility/security checks.

### Optional Terminal-based setup

From your account home, these tools read the same private `config.php`:

```sh
php alpha-school/scripts/check.php
php alpha-school/scripts/init-production.php
php alpha-school/scripts/verify-integrations.php
```

For CLI initial accounts instead of browser setup, privately create a strong password file outside the web root and use:

```sh
chmod 600 "$HOME/initial-password.txt"
export BOOTSTRAP_APPROVED=true
export BOOTSTRAP_APPROVAL_REFERENCE='your-approved-appointment-reference'
export BOOTSTRAP_PASSWORD_FILE="$HOME/initial-password.txt"
php alpha-school/scripts/bootstrap.php --username=system-admin --name='System Administrator' --role=system_admin
```

Repeat for the separately approved head teacher with a different password file and `--role=head_teacher`. Remove temporary files after secure handover, clear setup/provisioning secrets and delete the public installer. Never put passwords in command-line arguments.

For explicit synthetic email tests:

```sh
php alpha-school/scripts/verify-integrations.php --check=email --send-test=office
php alpha-school/scripts/verify-integrations.php --check=email --send-test=privacy
```

## Updating an existing installation

**Back up first. Do not use first-install setup for an existing database.**

- Preserve the private `config.php` or existing `.env`, the original `DATA_DIR`, encrypted storage, application/audit keys and coordinated MySQL backup. Do not copy a new example over real settings.
- The old private app's `src`, `data`, `scripts`, `database` and `test` now live under the repository's `alpha-school` folder. Place them in your existing account-home `alpha-school` directory, not an extra nested `alpha-school/alpha-school`.
- Old installations may use `alpha-school-private`, `.runtime` or a different data path. **Keep the old absolute DATA_DIR.** Do not accept the new default `alpha-school/private` for existing encrypted records.
- If converting an old `.env`, copy every real setting into `config.php`; PHP config takes precedence and `.env` is not combined with it. Operator environment variables override either file. Use one settings location, and keep existing backup/provisioning settings if required.
- Upload current public files into `public_html`; no duplicate `alpha-school/public` assets are needed anymore. Remove obsolete copies only after a backup and verification. Keep the new public entry point unchanged. Do not re-upload `setup.php` on routine updates.
- Run an approved isolated rehearsal before live cutover. No database records are automatically migrated, renamed or erased by this folder-layout change.

## Troubleshooting

- **NXDOMAIN:** contact registrar/hosting support about DNS, not PHP files.
- **Upload incomplete:** `alpha-school` and `public_html` are not siblings, or the private source was not uploaded.
- **Configure your school website:** copy/edit private `config.php`; never place it in `public_html`.
- **Setup disabled:** supply a random token of at least 32 characters. A completed marker locks the installer; use normal sign-in instead of deleting locks to reset accounts.
- **HTTPS required:** enable SSL/HTTPS. If HTTPS is terminated by a reverse proxy, confirm its trusted hop count with the host.
- **Form expires/reloads:** allow first-party cookies and open setup directly, not inside an embedded preview frame.
- **Setup could not finish:** check the PHP syntax of `config.php`, database credentials/MySQL version/trigger privileges, complete SMTP settings and retention approval. Infrastructure details stay in protected server logs, not the page. Ask support to run the optional checker/verification command if necessary.
- **Routes 404:** ensure `.htaccess` was copied and Apache rewrite overrides are supported.

Never enable public stack traces, send passwords to support chat, use `777`, weaken audit triggers or disable CSRF/TLS to work around a configuration problem.
