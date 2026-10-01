# Alpha Adventist Digital School Platform

A governed public website and staff workspace for **Alpha Adventist Pre & Primary School, Kigoma**. This implementation follows the September 2026 blueprint’s order: secure the existing site, establish editorial governance, then add staff/HR workflows and an admissions review desk.

**This is an implementation for staged acceptance—not a declaration of production readiness or completion of every later phase.** See [delivery status](docs/delivery-status.md) and the [operations template](docs/operations-template.md).

## What is included

- Original GitHub homepage layout and navy/gold design system; English/Kiswahili Home, Admissions and Contact; Downloads and Portal navigation; mobile Call/WhatsApp/Apply; child-friendly, local-only Kids Zone.
- Central `BASE_URL` for canonical, Open Graph, structured data and sitemap URLs. Unapproved photo slots use the existing school crest; only consented, approved media can replace it.
- Private `/portal`: dashboards, profiles, HR records, leave balances/applications, employment contracts, controlled documents, notices/acknowledgements, calendars, requests, notifications and authorised search/reports.
- CMS drafts, independent editorial/authority review, separate publication, scheduling and historical published snapshots. School contacts, verified map and official social links use the same review process.
- Configurable, versioned approval routes with per-stage authority, comments, return/reject/cancel states, escalation notifications and append-only audit events.
- Admissions state transitions and private enquiry queues. All five original public forms produce references and durable office-alert jobs; safeguarding/privacy requests have separate restricted queues.
- Guardian media consent by pupil reference and channel, annual expiry, withdrawal enforcement and external-removal follow-up. Published images are checked on every request.
- Scrypt passwords, persistent account/IP login limits, session rotation/revocation, mandatory privileged MFA, CSRF/origin checks, server-side resource authorisation, encrypted private files and one-use download grants.
- Limited PWA/offline shell. **No private records, API responses, submissions or photographs are cached by the service worker.**

## UI source

The public homepage, navigation, typography and cards use the original repository UI from commit `82757d3`. The portal reuses its original centred CMS login and navy admin layout. New features extend those components rather than replacing them with another template. See [UI source and integration](docs/ui-source.md).

## Run locally

Use **Node.js 22.13+ within the 22.x line**; tested on 22.22.3.

```sh
npm ci
cp .env.example .env
npm start
```

Open `http://localhost:3000`; the staff workspace is `/portal`. The server binds to `0.0.0.0`. Browser-facing requests use relative URLs, so a proxied preview works without calling the visitor’s localhost.

With no Mongo URI, development uses transactional SQLite in ignored `.runtime/`, not a JSON-file database. Development keys are generated locally with restrictive permissions. Do not reuse them outside development. There are **no default login credentials, demo users or invented employment balances**.

### Initial access

Appoint **separate people** for technical administration and school business authority. Provide a strong temporary password through a mode-600 file outside source control, then use an approved operator terminal:

```sh
npm run bootstrap -- --username YOUR_NAMED_USERNAME --name "Authorised person's name" --role system_admin
# A separate, owner-approved first appointment:
npm run bootstrap -- --username ANOTHER_NAMED_USERNAME --name "Authorised school leader" --role head_teacher
```

Replace the uppercase command placeholders with chosen lowercase usernames; they are not credentials. Each invocation requires `BOOTSTRAP_APPROVED=true`, a private `BOOTSTRAP_APPROVAL_REFERENCE`, and `BOOTSTRAP_PASSWORD_FILE` in the operator environment. No password belongs in the command line or this README. The first sign-in requires a password change and authenticator enrolment. Bootstrap refuses to repeat an existing initial authority.

Management can then appoint HR, departmental approvers, CMS authors/editors/publishers and the privacy officer. HR records staff IDs, departments, supervisors and entitlements. ICT accounts **do not inherit HR/contract/financial access or business approvals**.

## Checks

```sh
npm run check             # syntax, actual PWA assets, safe seed/artifact guard
npm test                  # isolated synthetic SQLite/security/workflow/recovery tests
npm audit --omit=dev
# UI regressions also require OpenSSL (included on the Linux CI runner).
npx playwright install --with-deps chromium
npm run test:ui           # isolated browser workflow, mobile and accessibility checks
```

`CHROMIUM_EXECUTABLE` can point to an existing compatible Chromium. Tests do not read a production database. UI screenshots contain synthetic data and are written only to ignored `.runtime/ui-test/`. Test scripts never create accounts in the running preview.

## Production and staging

Use separate databases, storage, keys, origins and alert recipients for each environment. Outside development, startup requires:

- An explicitly configured, verified HTTPS `BASE_URL`.
- A transaction-capable MongoDB replica set/Atlas database and persistent `DATA_DIR` for files.
- Three distinct, separately escrowed 32-byte keys: MFA, private storage and audit HMAC.
- A signed office-alert endpoint/secret, an explicitly approved retention schedule, and a named safeguarding contact.

The public site at **https://alpha-adventist-pre-primary-school.onrender.com** was reachable during a read-only check on **30 September 2026**. The similarly spelled hostname without the hyphen between `alpha` and `adventist` returned “Not Found.” The reachable URL is the development fallback only; production still requires an explicit `BASE_URL`, and the school must confirm its long-term official domain. No live deployment was performed in this implementation session.

For a **free, read-only public preview**, create a separate Render service and set `NODE_ENV=staging`, `PUBLIC_PREVIEW=true`, `BASE_URL` to that service's HTTPS origin, and `DATA_DIR=/tmp/alpha-school-preview`. Do not copy MongoDB environment variables into this service; preview uses temporary SQLite and generated local keys. It displays a preview notice, rejects all `/api` and `/portal` requests, removes public submission forms and staff links, and returns `noindex`. Its database and temporary files can disappear on restart. This mode is not for real submissions, staff work or school records. Keep the live service in strict production mode with its approved MongoDB, persistent storage and alert configuration.

`render.yaml` is a **single-instance deployment template**, with a persistent disk, readiness probe and automatic deployment disabled. MongoDB is not a substitute for file storage. A scanner-enabled host/image is needed for PDF and image uploads; without a working scanner these uploads fail closed. The template does not install ClamAV or configure a messaging provider, database, backups, DNS, analytics or Search Console on your behalf.

## Notifications

Public forms are saved transactionally with an in-app notice and durable outbox entry. A configured HTTPS integration receives a reference, request type and secure office link, signed with HMAC-SHA256. It must perform the approved email/SMS/WhatsApp delivery. No child/guardian form body is sent to that endpoint.

Unconfigured jobs remain **PENDING**; non-success responses retry with backoff, then become **FAILED**. A 2xx response means the integration accepted the job—not proof that an SMS or email reached its recipient. Provider acknowledgements, parent-channel messaging and bulk campaigns need further integration.

## Backups and legacy migration

- `npm run backup:mongodb` creates an encrypted, bounded database-and-private-file archive during a confirmed maintenance window.
- `npm run restore:mongodb -- /protected/path/archive.ejson.enc` restores only to an explicitly approved, empty isolated database ending in `_restore_test`; it never overwrites production.
- `npm run migrate:mongodb` imports supported legacy text and form fields from a fresh encrypted backup, without importing old passwords, accounts or unverified photographs. Public imports become reviewable drafts; unrecognised schemas need a reviewed mapping.

See the operations template before using these commands. The tests rehearse an actual isolated SQLite/file restore and archive-integrity checks. **Live Mongo transactions, Mongo restore/migration, external-provider delivery and production recovery have not been verified in this workspace.**

## Repository and child-data safety

Tracked runtime records, uploads, internal artifacts and unverified photographs were removed from the current checkout. **They may still exist in Git history, forks, clones, deployment caches and the previously deployed site. `.gitignore` does not remove them.** Coordinate repository visibility/history cleanup with the owner, rotate previously exposed secrets, and confirm photo-consent/removal obligations before publication. An earlier GitHub access check returned an authentication error. If it recurs, reconnect GitHub in Arena before retrying remote work.

Keep real credentials, private operations records, guardian evidence, exports, backups and child images out of Git. The public operations document is a sanitised template; completed procedures and approval records belong in the school’s restricted operations system.

## Later phases

Parent/authorised-child portals, student academic records, attendance/fees/payments, admissions document collection, native apps, formal e-signatures, teacher-managed quiz authoring, provider-specific messaging, advanced HR casework and additional CMS/menu/profile tooling remain later or separate work. No native app was built ahead of the portals.
