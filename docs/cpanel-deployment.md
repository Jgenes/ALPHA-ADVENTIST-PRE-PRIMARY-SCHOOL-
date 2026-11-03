# cPanel deployment with MySQL (`alphadve_alpha_school`)

This is the supported production path for the Alpha Adventist Pre & Primary School platform: **cPanel + Node.js 22 + MySQL/MariaDB**. All persistent school records (accounts, sessions, admissions, workflows, audit chain) are stored in the cPanel database **`alphadve_alpha_school`**. MongoDB is not used anywhere in this project.

> This guide is a procedure, not proof of readiness. Complete the release gates in [operations-template.md](operations-template.md), use synthetic data for rehearsals, and have the school approve retention, access and safeguarding arrangements before real records are entered.

---

## 0. What you need

| Item | Value used in this guide | Notes |
| --- | --- | --- |
| cPanel account | `alphadve` | cPanel prefixes database and user names with it |
| Database | `alphadve_alpha_school` | Create it in cPanel → *MySQL® Databases* |
| Database user | `alphadve_alpha_school` (or another name you choose) | Use a generated strong password |
| Node.js | **22.x** (22.13 or newer) | Required by `package.json`; the app uses `node:sqlite` for local development only |
| Application root | `/home/alphadve/alpha-school` | **Outside** `public_html` |
| Private data directory | `/home/alphadve/alpha-private` | Keys and uploaded files; **outside** `public_html` |
| Public origin | `https://school.example` | Must be HTTPS (`AutoSSL` or a valid certificate) |

MySQL 5.7+/MariaDB 10.2+ is sufficient. The schema uses InnoDB, `utf8mb4`, `VARCHAR(191)` unique keys and two `SIGNAL` triggers, so very old 5.6-era servers are not supported.

---

## 1. Put the code outside the web root

In cPanel → *File Manager* (or SSH):

```sh
cd ~
git clone https://github.com/Jgenes/ALPHA-ADVENTIST-PRE-PRIMARY-SCHOOL-.git alpha-school
cd alpha-school
npm ci --omit=dev
```

Never extract the project inside `public_html`: `.env`, `data/`, scripts and source code would then be downloadable. The Node.js app serves only `public/` through its own router.

If you must use the cPanel *Git™ Version Control* feature, set the clone path to `/home/alphadve/alpha-school`, not the document root.

---

## 2. Create the MySQL database and user

1. cPanel → **MySQL® Databases**.
2. *Create New Database*: enter `alpha_school`. cPanel creates **`alphadve_alpha_school`**.
3. *Add New User*: choose a user name (for example `alpha_school`) and use **Password Generator** to create a long random password. Copy it to your password manager; cPanel creates **`alphadve_alpha_school`** (or `alphadve_<user>`).
4. *Add User To Database*: select both, choose **ALL PRIVILEGES**, then *Make Changes*.

The full names — with the `alphadve_` prefix — are what belong in `DB_NAME` and `DB_USER`. The prefix is chosen by the hosting account and can differ from `alphadve`; read it from cPanel.

### 2a. Import the schema (recommended)

1. cPanel → **phpMyAdmin** → select `alphadve_alpha_school` in the left column.
2. Open the **Import** tab → *Choose File* → `db/schema.mysql.sql` from the project → **Go**.
3. Confirm 37 `adsp_*` tables and two triggers (`adsp_audit_logs_no_update`, `adsp_audit_logs_no_delete`) exist — the triggers are under the *Triggers* tab.

Alternatively, leave `DB_AUTO_SCHEMA=true` (default) and let the app create the tables on its first start. After a successful import, set `DB_AUTO_SCHEMA=false` so the production application does not issue DDL.

> If phpMyAdmin refuses the import because of grant restrictions, ask the hosting provider for `CREATE`, `ALTER`, `INDEX` and `TRIGGER` privileges on this database. The app can start without trigger privileges but logs a warning, and only the application-level append-only rule remains.

---

## 3. Create the private data directory

```sh
mkdir -p /home/alphadve/alpha-private
chmod 700 /home/alphadve/alpha-private
```

`DATA_DIR` stores `application-keys.json` (storage-encryption and audit-HMAC keys), the local encrypted upload area and development SQLite (development only). It must be persistent, backed up separately and **never** web-accessible. Losing or replacing the keys makes existing encrypted uploads and the audit chain unreadable.

---

## 4. Create the Node.js application in cPanel

cPanel → **Setup Node.js App** → *Create Application*:

| Field | Value |
| --- | --- |
| Node.js version | 22.x |
| Application mode | Production |
| Application root | `alpha-school` |
| Application URL | your school domain (or a subdomain) |
| Application startup file | `server.js` |

Then open **Environment variables** and add the values below. cPanel passes `PORT` to the app automatically; do not hard-code a port.

### Required application variables

| Variable | Example | Why |
| --- | --- | --- |
| `NODE_ENV` | `production` | Enables production safety gates, HTTPS enforcement and `noindex` rules |
| `BASE_URL` | `https://school.example` | Canonical, Open Graph, sitemap and form-origin checks; must be the exact HTTPS origin |
| `DATA_DIR` | `/home/alphadve/alpha-private` | Keys and files, outside the web root |
| `DB_HOST` | `localhost` | Use `127.0.0.1` only if `localhost` resolves incorrectly on your host |
| `DB_PORT` | `3306` | Default |
| `DB_NAME` | `alphadve_alpha_school` | The cPanel database |
| `DB_USER` | `alphadve_alpha_school` | The cPanel database user |
| `DB_PASSWORD` | *(generated)* | Never commit, email or paste into chat |
| `DB_AUTO_SCHEMA` | `false` | After importing `db/schema.mysql.sql`; use `true` for the very first start if you skipped the import |
| `FORM_RETENTION_DAYS` | school-approved, e.g. `90` | Enquiry retention |
| `ADMISSION_RETENTION_DAYS` | school-approved, e.g. `365` | Application retention |
| `RETENTION_POLICY_APPROVED` | `true` | Only after written school/legal approval |

### Keys

Generate two different 32-byte keys (64 hex characters) on your own machine or in *Terminal*:

```sh
node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
```

Set them as `STORAGE_ENCRYPTION_KEY` and `AUDIT_HMAC_KEY`. If you leave both empty, the app generates them once into `DATA_DIR/application-keys.json`; **escrow that file** in a separate, access-controlled store. Never change either key after real use.

### Alerts (required outside development)

Configure **either**:

- `OFFICE_ALERT_WEBHOOK_URL` **and** `OFFICE_ALERT_WEBHOOK_SECRET` (https endpoint, secret ≥ 32 characters), or
- `GMAIL_SMTP_USER`, `GMAIL_SMTP_APP_PASSWORD`, `OFFICE_ALERT_EMAIL`, `PRIVACY_ALERT_EMAIL` (Gmail app password, not the account password).

### Optional

- `TRUST_PROXY_HOPS=1` when cPanel/Apache is the only trusted proxy in front of Node.
- `DB_POOL_SIZE` (default `3`) when the host caps database connections per user.
- `DB_SOCKET` when the host provides a MySQL Unix socket instead of TCP.
- `DB_SSL`, `DB_SSL_CA_FILE` only for a remote MySQL server (not for cPanel localhost).
- `SUPABASE_S3_*` + `SUPABASE_STORAGE_BUCKET` to keep uploaded files in a private Supabase bucket instead of `DATA_DIR`.

`.env` in the application root also works (loaded by `dotenv`), but cPanel's environment-variable UI avoids leaving a secrets file on disk. Either way the file must stay outside `public_html`, mode `600`.

---

## 5. First start and first account

1. Click **Restart** in *Setup Node.js App*.
2. Open `https://school.example/readyz` — it must return `{"ok":true}`. `/healthz` is equivalent.
3. Open `https://school.example/` — the public site must load over HTTPS.
4. Provision the first accounts with **separate people** for technical and school authority:
   - Preferred: an approved operator terminal runs
     ```sh
     cd ~/alpha-school
     BOOTSTRAP_APPROVED=true \
     BOOTSTRAP_APPROVAL_REFERENCE="<private decision reference>" \
     BOOTSTRAP_PASSWORD_FILE=/home/alphadve/private/initial-password.txt \
     npm run bootstrap -- --username named.admin --name "Named Administrator" --role system_admin
     ```
     The password file must be mode `600`; delete it after handover.
   - Or set the one-time `HEAD_TEACHER_*` variables in the cPanel environment, restart, confirm creation, then remove all five values.
5. Sign in at `/portal`, change the password, and create the remaining staff accounts through the portal.

There are no default logins. Do not create real records until the school's access, retention and safeguarding approvals are recorded.

---

## 6. Verify the database

- cPanel → phpMyAdmin → `alphadve_alpha_school`: tables `adsp_users`, `adsp_audit_logs`, `adsp_cms_content`, … must exist and grow as the school uses the portal.
- `SELECT COUNT(*) FROM adsp_users;` should match the appointed accounts.
- The portal's **System** panel shows the active store as `mysql`.
- The database password must never appear in browser output, logs or screenshots. Startup errors are redacted.

### Optional live rehearsal

On your own machine (not on the live database), point the integration test at a disposable cPanel database whose name **ends in `_test`**:

```sh
DB_HOST=localhost DB_NAME=alphadve_alpha_school_test \
DB_USER=alphadve_school DB_PASSWORD='…' \
TEST_DB_HOST=localhost TEST_DB_NAME=alphadve_alpha_school_test \
TEST_DB_USER=alphadve_school TEST_DB_PASSWORD='…' \
npm run test:mysql
```

The test refuses any database name that does not end in `_test`, then exercises schema creation, revisions, unique keys and the append-only audit triggers. It deletes its own synthetic rows and recreates the triggers afterwards. Never run it against `alphadve_alpha_school`.

---

## 7. Backups on cPanel

Use **both** layers:

1. **Provider/panel backups** — cPanel → *Backup Wizard* or your host's scheduled MySQL backup. Confirm the retention and where the copies are stored.
2. **Application archive** — during a confirmed maintenance window:
   ```sh
   cd ~/alpha-school
   BACKUP_WRITES_PAUSED=true \
   BACKUP_ENCRYPTION_KEY_FILE=/home/alphadve/private/backup-key.txt \
   DB_BACKUP_DIR=/home/alphadve/private/backups \
   npm run backup:mysql
   ```
   This produces an AES-256-GCM `.json.enc` archive containing every `adsp_*` table plus every referenced encrypted private file. Keep the encryption key **separate** from the archive.

Restore rehearsals use a separate empty cPanel database ending in `_restore_test`, with `RESTORE_APPROVED=true`, `DB_RESTORE_NAME`, and an empty `RESTORE_DATA_DIR`:

```sh
npm run restore:mysql -- /home/alphadve/private/backups/alphadve_alpha_school-….json.enc
```

Sessions, login limits and download grants are deliberately not restored. Never point a restore at the live database; the command refuses it.

---

## 8. Updates

```sh
cd ~/alpha-school
git pull --ff-only
npm ci --omit=dev
npm run db:schema        # refresh db/schema.mysql.sql if the schema changed
```

Import any new statements from `db/schema.mysql.sql` in phpMyAdmin (or briefly allow `DB_AUTO_SCHEMA=true`), then **Restart** the Node.js application. Review `docs/operations-template.md` for the release gates.

---

## 9. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `A MySQL/MariaDB database is required outside development` | `DB_HOST`, `DB_USER` or `DB_PASSWORD` missing; all three plus `DB_NAME` are required in production |
| `ER_ACCESS_DENIED_ERROR` | Wrong user/password, or the user is not attached to the database in *MySQL Databases* |
| `ER_BAD_DB_ERROR` | `DB_NAME` is wrong — it must include the cPanel prefix (`alphadve_alpha_school`) |
| `MySQL database … is missing the adsp_ tables` | Import `db/schema.mysql.sql` or set `DB_AUTO_SCHEMA=true` for one start |
| `MySQL schema setup failed … TRIGGER` | Grant the `TRIGGER` privilege, or start once without it (warning only) and ask the host to enable it |
| `Public preview must use isolated temporary storage` | `PUBLIC_PREVIEW=true` must not receive production `DB_*` values |
| `502`/passenger error page | Check the startup file is `server.js`, Node is 22.x, and `npm ci` completed in the application root |
| Site loads on HTTP but forms fail | `BASE_URL` must match the exact HTTPS origin used in the browser |
| App restarts lose CMS content | The app fell back to SQLite because `DB_HOST`/`DB_USER` are unset — check the cPanel environment variables |

Never disable `NODE_ENV=production`, retention approvals or HTTPS to "make it work"; fix the configuration instead. Startup fails closed by design.
