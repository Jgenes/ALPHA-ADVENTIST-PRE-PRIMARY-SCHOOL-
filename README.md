# Alpha Adventist Pre & Primary School — Official Website Platform

Production-ready official digital platform for **Alpha Adventist Pre & Primary School**,
a Seventh-day Adventist educational institution within the **Western Tanzania Conference – Kigoma**.

Identity line: **Education • Faith • Technology • Talent • Character • Future**

---

## 1. Run the platform

```bash
node server.js          # Node.js 20.19+, port 3000; loads .env automatically
PORT=8080 node server.js
```

Run `npm install` first, then open `http://localhost:3000`. Set `MONGO_URI` in
`.env` to use MongoDB for CMS data. `MONGODB_URI` and `MONGODB_DB_NAME` are the
preferred names; the older `MONGO_URI` and `MONGO_DB_NAME` keys remain supported.
The current CMS database supports MongoDB collections and retains the JSON file
as a local fallback when no Mongo URI is configured. Copy `.env.example` to
`.env` and use separate development, staging, and production database names.

Every public enquiry now receives a reference number shown in the confirmation
and the private CMS inbox. To send the school an external alert, configure
`OFFICE_ALERT_WEBHOOK_URL` with a trusted webhook that can forward the reference
and enquiry type to the office by email, SMS or WhatsApp. Personal form details
are not sent to the webhook. Without it, submissions remain available in
**CMS → Form Submissions**.

Public enquiry forms require a privacy acknowledgement and store its policy
version and timestamp. This is not permission to publish child photographs.
Automatic form-data deletion is disabled until management approves a retention
period and sets `FORM_RETENTION_DAYS` in the environment.
     
### CMS sign-in (first run)

Set `ADMIN_USERNAME` and a unique strong `ADMIN_PASSWORD` in the environment
before first startup. There is no built-in administrator password. Existing
super-admins are required to change their password after this security update;
they sign in with their current password and are redirected to the change form.
Set `MFA_ENCRYPTION_KEY` to a separately backed-up random 32-byte key encoded as
64 hexadecimal characters, or set `MFA_ENCRYPTION_KEY_FILE` to a mode-600 file containing it. Generate one with `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"` and store it only in your secret manager or ignored `.env`. Privileged `super` and `admin` accounts must enroll an authenticator after password rotation; sign-in then requires its six-digit TOTP code. Losing this key prevents decryption of enrolled MFA secrets.
- MongoDB backups: enable daily Atlas backups/continuous cloud backup for production and retain backups outside the application account. The encrypted application-level backup can also be run daily with `node scripts/backup-mongodb.js`; set `MONGO_BACKUP_DIR` to a dedicated mode-700 directory and provide `MONGO_BACKUP_ENCRYPTION_KEY` or a mode-600 `MONGO_BACKUP_ENCRYPTION_KEY_FILE`. Keep this 32-byte key separate from the encrypted archives. Schedule backups with a managed job/cron and copy archives to off-host storage.

Sessions expire after 10 hours; login is limited by source and account; admin
POSTs are CSRF-protected.

## 1.1 Deploy to Render

This repository includes a Render Blueprint in `render.yaml`. It installs the declared Node.js dependencies and attaches a persistent disk at `/var/data` for JSON fallback storage. Render persistent disks require a paid web-service plan; do not remove the disk unless you have another persistent database/storage plan, or CMS edits can be lost on redeploy.

1. Push this project to a GitHub or GitLab repository.
2. In Render, choose **New → Blueprint**, connect the repository, and select its branch. Render reads `render.yaml` and creates the web service and persistent disk.
3. When prompted for `ADMIN_PASSWORD`, enter a unique, strong password and keep it private. On the disk's first initialization, the site copies the content database without its development admin account, then creates the `admin` user using this password.
4. Set `MONGODB_URI`, `MONGODB_DB_NAME`, `ADMIN_PASSWORD`, and `MFA_ENCRYPTION_KEY` as private Render environment variables. Use a least-privilege MongoDB application user restricted to the Render network and TLS. Set `TRUST_PROXY=true` only because Render is the trusted TLS proxy. Optionally set `OFFICE_ALERT_WEBHOOK_URL` for office notifications. Never commit these values.
5. After deployment, open the service URL and sign in at `/admin` with username `admin` and the password you supplied.
6. Set `BASE_URL` in the Render service environment to the deployed Render URL, or to your custom HTTPS domain after connecting it. Redeploy for canonical URLs and the sitemap to reflect the selected domain.
7. Add the custom domain in Render if needed and follow Render's DNS instructions. Render provides HTTPS for connected domains.

The service listens on Render's assigned `PORT`. MongoDB is the production source of truth; the `/var/data/db.json` store is only a local/fallback option. Changing `ADMIN_PASSWORD` later does not reset an existing account.
The existing Mongo `platform_state` record stays in compatibility mode by default. The web server never migrates it implicitly. Before changing its layout, back up MongoDB, test the migration against a separate staging database, verify counts and sample records, and only then schedule production deployment.

---

## 2. What was built

### Public website (server-side rendered, SEO-ready)
| Route | Purpose |
|---|---|
| `/` | Homepage — 20-section flow: contact bar, nav, 4-slide hero, trust cards, who we are, six pillars, academics, why Alpha, Head of School, computer learning, school life, faith, talent, admissions CTA, news, gallery, parent corner, kids zone, contact, footer |
| `/about` | Who we are, official Mission/Vision/Philosophy cards, full Head of School message, whole-learner development |
| `/academics` | KG I–II, Standard I–VII, day & boarding, teaching/assessment/monitoring features |
| `/admissions` | 5-step process, levels, boarding/day, visit booking form, application-request form, FAQs, call/WhatsApp CTAs |
| `/computer-learning` | Pupil ICT pathway (KG→Std VII), staff ICT development, **COMING SOON** community training centre + interest register |
| `/school-life` | Worship, choir, Pathfinder, ICT, academic exhibits, service; talent showcase |
| `/faith` | Spiritual life and Christian principles in practice |
| `/parents` | Parent Corner: calendar/exams/rules status, announcements, communication, future Parent Portal (no fake login) |
| `/students` | Alpha Kids Zone: working typing game, maths quiz, ICT revision, internet-safety corner |
| `/news`, `/news/:slug` | News system with categories, featured article, related articles, WhatsApp/Facebook/copy-link sharing |
| `/gallery` | Filterable masonry gallery with accessible lightbox (keyboard + captions) |
| `/contact` | Address, phones, WhatsApp, email, directions, enquiry form |
| `/privacy` | Website privacy & child-safeguarding notice |
| `/robots.txt`, `/sitemap.xml`, `/site.webmanifest` | SEO & PWA basics |

### CMS (`/admin`) — role-based
- **Roles:** `super` (all + users) · `admin` (all content/settings) · `editor` (news, announcements, gallery, courses, submissions) · `contributor` (news & announcements).
- **Editable:** mission/vision/philosophy, leadership (name, title, portrait path, messages), hero slides (text, photo, CTAs, enable), news, announcements, gallery (captions/categories/alt), computer courses (status current vs coming-soon), contact settings, WhatsApp line, email publication flag, form-submissions inbox, users.
- Storage: MongoDB collections for users, news, events, submissions, announcements, gallery, courses, and school settings. The original `platform_state` document is retained as a migration/rollback snapshot; normalized collection writes do not overwrite it.

### Security & child privacy (implemented)
HTTPS-ready headers (HSTS via proxy, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy), secure HttpOnly SameSite cookies, scrypt password hashing, CSRF tokens on all admin POSTs, login + form rate limiting, honeypot spam protection, input sanitisation, output escaping everywhere, `noindex` on admin, and a published privacy/safeguarding notice. No pupil records, results, medical or fee data are ever exposed; gallery captions never identify children.

---

## 3. Source-of-truth rules honoured

- Official **Mission / Vision / Philosophy** wording is preserved verbatim and stored separately from explanatory copy (CMS-editable only by admin/super).
- Contact data used: **P.O. Box 891, Kigoma – Tanzania · +255 613 807 200 · +255 747 128 120 · alphaadventistschool@yahoo.com · Msimba area, Kigoma** (from the school's 2026 graduation programme and Head of School's office documents). WhatsApp line set to **+255 747 128 120** per school instruction; both numbers remain callable.
- Head of School: **Christopher Mashaka James** — displayed with a dignified crest placeholder until the school supplies an official portrait (set it in *CMS → Leadership*).
- Nothing invented: no fees, results, staff rosters, sports teams, social links or office hours are published. Missing items appear as honest "published by the school office / coming soon" states, all CMS-ready.
- Examination centre **PS0603108** is cited only as the publicly recorded 2026 Form One selection identifier.
- Only the photographs supplied by the school are used (optimised WebP/JPEG renditions in `public/img/`); the logo is unmodified.

## 4. Design system
Brand colours sampled from the official crest: navy `#0A1E59`, gold `#EAB239`, SDA green `#166428`; warm paper/sand neutrals. Type: **Source Serif 4** (display) + **Inter** (text), self-hosted as WOFF2 (`public/fonts/`). Mobile-first, sticky nav, bottom quick-action bar (WhatsApp / Call / Apply), reduced-motion support, visible focus states, semantic headings, skip link, alt text on every image.

## 5. Roadmap alignment
- **Phase 1 (delivered):** public website, academics, admissions info, school life, faith, computer learning, news, gallery, contact, CMS.
- **Phase 2 (ready to wire):** online admissions workflow, computer-course registration, events calendar, certificate management — submission endpoints and DB schema already accept them.
- **Phase 3 (designed, not faked):** secure Parent / Student / Teacher portals behind authentication; e-learning, payments, certificate verification, digital library.

## 6. Maintenance notes
- MongoDB backups: enable daily Atlas backups/continuous cloud backup for production and retain backups outside the application account. The encrypted application-level backup can also be run daily with `node scripts/backup-mongodb.js`; set `MONGO_BACKUP_DIR` to a dedicated mode-700 directory and `MONGO_BACKUP_ENCRYPTION_KEY` to a separately managed 32-byte key encoded as 64 hex characters. Schedule it with a managed job/cron and copy the encrypted archive to off-host storage.
- Restore test: set `NODE_ENV=staging`, `MONGODB_RESTORE_URI` to the staging cluster, and `MONGODB_RESTORE_DB_NAME` to an empty database different from the backup source, then run `node scripts/restore-mongodb.js /secure/path/to/archive.ejson.enc`. The restore utility refuses non-empty targets. Verify collection counts, sign-in, CMS rendering and form handling in staging. Production recovery should use the approved Atlas backup restore workflow after authorization, not the staging utility. Test and record a staging restore at least quarterly.
- Collection migration: create and verify a fresh encrypted backup, point the environment at the separate staging database, set `MONGO_MIGRATION_BACKUP_FILE` to that archive and `MONGO_MIGRATION_APPROVED=true`, then run `node scripts/migrate-mongodb.js`. For production, additionally set `MONGO_MIGRATION_STAGE_VERIFIED=true` only after recording staging results. The migration is resumable and leaves `platform_state` in place; remove the approval flags after it completes.
- For self-hosted MongoDB, use `mongodump --archive --gzip` with a mode-600 tools config file; never put credentials in command arguments or repository files. Retain encrypted backups off-host and document the restore operator and key custodian.
- Collection migration: the first startup copies the legacy `platform_state` content into named collections and writes a migration marker. It does not delete or replace `platform_state`; retain a verified backup before upgrading.
- Keep `public/img/` and any approved external media storage in a separate backup plan. Do not back up uploads or private records into a public repository.
- Add photographs: drop optimised `-480/-800/-1200/-1600` WebP+JPEG renditions into `public/img/`, add the key to `IMG_POOL` in `src/pages/admin.js`, then publish via *CMS → Gallery/Hero*.
- Change the public domain: edit `BASE_URL` env var (canonical URLs, sitemap, OG tags).
