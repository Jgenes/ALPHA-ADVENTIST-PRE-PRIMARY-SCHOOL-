# Blueprint delivery status

**Implementation/review date: 30 September 2026.** This is a substantial governed-platform foundation and staged delivery, not completion of every item in the master blueprint. No production deployment was performed. Publishing the implementation branch is separate from deploying the site or cleaning repository history.

## Scope matrix

| Blueprint area | Implemented in this checkout | Still required / boundary |
| --- | --- | --- |
| P0 URLs and visibility | Central origin, canonical/OG/structured data, sitemap, crawlable production pages, protected-route exclusions and non-production noindex; corrected the hyphenated Render fallback | Owner-approved official domain/mailbox, DNS/HTTPS deployment verification, Search Console, analytics selection and real indexing |
| P0 repository safety | Removed tracked runtime DB/uploads/work/internal artifacts and unverified public photographs; safe text seed; artifact/ignore guards | Existing Git history/forks/clones/deployed assets still need owner-coordinated cleanup and secret rotation; GitHub connection returned an authentication error |
| Public experience | Original repository homepage/slideshow/sections (news and gallery conditional on published content), EN/SW Home/Admissions/Contact, six pillars, programmes, faith/talents, Downloads and Portal, mobile contact bar, school-crest fallbacks for unapproved images | School confirmation of every institutional claim, leadership/news, verified map/social accounts and usable approved downloads/photos; broader translation |
| CMS | News/pages/events/vacancies/FAQ/banner and structured school contacts/map/social settings; own-author draft boundary, editorial review, authority approval, separate publish/schedule/archive, published snapshots/history | Arbitrary menu/layout builder, public teacher-directory/profile editor, structured admissions content blocks and editing every legacy static page; timed automatic archive rules |
| Staff and HR | Real account-linked profiles, departments/supervisors, greeting/balances/own contracts, HR views and entitlements, controlled attachments, scoped reports | Advanced employment cases, appraisals, attendance/payroll, bulk imports, organisation/department editor and richer staff compliance rules |
| Leave | Eight types, working-day calculation, handover/emergency contact/attachment, balances/reservations, configurable staged review, return/reject/cancel and CAS protection | Public-holiday calendars, half-days, cross-year requests (currently split), approved local entitlement rules and bulk leave calendars |
| Contracts | HR draft/revision/renewal, administration, optional finance, management approval, signatory attestation, employee acknowledgement, ownership restrictions and renewal supersession; reminder jobs | Real legal templates, e-signature provider/certification, real reminder delivery beyond in-app, independent production renewal acceptance |
| Controlled documents | Five classifications, ACLs, metadata/dates, encrypted originals, unscanned PDF uploads with basic active-content checks, version review/issuance/supersession/archive, public metadata, expiring single-use private grants | Malware scanning; more document formats; approved disposal/legal holds and external object storage. Uploaded binaries may contain malware. Retired unapproved drafts do not become broadly visible issued history |
| Notices/calendar | Audiences by role/department/named user, notice category/priority/expiry, independent publication, revision-specific read/ack receipts with session evidence and completion reports; private calendars | Notice file attachments, scheduled notices, recurring meetings and calendar integration. Public events use reviewed CMS |
| Governance/RBAC | Code-owned action permission catalogue, management role assignment, fresh-session/resource/state checks, ICT separation, versioned workflow definitions/instances/decisions/comments, denial audit | Custom role/permission builder, formal delegation, automatic overdue reassignment/escalation policy and independent security review |
| Forms/admissions | Five public forms, consent/minimisation, idempotency/reference, private queues, controlled admission stages through enrolment, in-app and durable external-alert outbox | Guardian-authenticated application drafts/tracking, secure admissions document upload, assessment scheduling, offer letters, enrolment-to-student-record conversion and family access |
| Messaging | Signed HTTPS webhook, minimal reference/type/link payload, durable attempts/leases/backoff/retry controls; in-app staff notifications | Configured email/SMS/WhatsApp integration and verified recipients, actual delivery callbacks, parent-channel messages, bulk campaigns and provider contracts |
| Privacy/media | Separate per-channel grants/refusals, verified guardian evidence/reference, annual expiry, deterministic latest consent, independent media review, immediate web withdrawal and forty-eight-hour external removal task; private requests/incidents | Legal/PDPC review, real guardian verification/consent, external-provider removal actions, general subject export/erasure/legal holds and retention beyond form queues |
| Audit/search/reports | Transactional HMAC audit chain, no application log edits/deletes, scoped auditor access, authorised filtered search and basic HR/admissions/document/CMS/security summaries/export | Independent protected audit anchoring, analytics/BI, pagination and scale tuning; not DBA-proof immutability |
| Operations | SQLite development store, transaction-capable Mongo adapter, production configuration gates, health probes, graceful jobs/shutdown, approved bootstrap, encrypted backup/isolated restore/guarded legacy import scripts, CI definition | Real Mongo/hosting tests, provider/AV integration, automatic off-site backup schedule, monitored restore drills, secrets escrow, tested break-glass support and production rollout |
| PWA/Kids Zone | Installable manifest, local assets, bilingual generic offline contact fallback, no private/API/media/download caching; local games/Kiswahili activities without ads/chat/data submission | Not an offline private portal; formal curriculum/teacher-assigned quiz authoring and supervised learner services remain future work |
| Parent/student/native apps | Reserved least-privilege roles only; no inappropriate staff inheritance | Verified guardian-child relationships, academic records/attendance/fees/results/payments, parent/student portals and then native mobile apps. No native app was built prematurely |

## Verification actually performed

### Automated application and recovery checks

`npm test` currently reports **45 passing tests** (Node’s count includes the three parent test groups). These use isolated synthetic data and real HTTP/cookie/CSRF flows, plus lower-level recovery/operator checks. Coverage includes:

- Default-deny access, retired legacy endpoints, static-file exposure protection, source/account login limits, bounded anonymous session creation and persistence across restart.
- Role/technical-authority boundaries, author ownership, CSRF/origin checks, password rotation and session revocation. MFA is not implemented; privileged access relies on passwords and the remaining account protections.
- Staff scope, server-calculated leave/overlap/reservations, required approval order, concurrent double decisions, return/resubmit and cancellation accounting.
- Contract sequencing, finance’s stage-specific access, employee-only visibility, wrong-user/wrong-session/expired/replayed download grants, fresh-session revocation inside transactions.
- All five original public forms, references/deduplication, private queues, status transitions/CAS, signed reference-only alert payloads, retry/success states and retention.
- CMS independent review, escaped content, old-published-snapshot isolation, school-settings review and scheduled publication.
- Encrypted files, type/size limits, explicit unscanned-file status, clean chunked-request 413 handling, controlled-version publication, approved-version visibility and retired-draft non-disclosure.
- Scoped notices/calendar/notifications, version acknowledgement, audit integrity/tamper detection/scoped auditing.
- Guardian channels/evidence, immediate withdrawal, deterministic consent order, missing-child denial, annual expiry checked without waiting for jobs and no automatic republication.
- Approved one-time bootstrap, AES-GCM archive integrity/wrong-key failures, restore target/path/checksum guards, **actual isolated SQLite + private-file restoration**, intact recovered audit chain and file decryption; synthetic idempotent legacy import.

`npm run check` verifies JavaScript syntax, all seven actual PWA precache resources, safe seed/current-tree artifact rules and diff whitespace. This does not scan or remove old Git history.

Production dependency audit returned **zero known vulnerabilities** at the latest check. This is a point-in-time package advisory result, not a general security certification.

### Browser checks

`npm run test:ui` passed in headless Chromium at **1440×1000** and **390×844**, with synthetic role fixtures:

- Home/EN–SW navigation, public contact submission/reference, Kids Zone local activity.
- Real login, dashboard, leave creation/submission, notice reading/acknowledgement.
- Additional HTTPS cross-site-iframe auth regression: secure partitioned cookies, an expired form, one bounded CSRF retry, password-only login and blocked-cookie/new-tab guidance. Backend CSRF checks remain enforced.
- Controlled-document upload/submission, independent API-based reviewer decisions, then actual publisher UI issuance and public download.
- Structured school-contact draft form and authorised staff/management views.
- No horizontal overflow/broken images or JavaScript/CSP/resource errors in the exercised views.
- Service-worker installation, real offline fallback and cache inspection showing no private routes, records, photos or documents; no private local/session storage.
- Automated WCAG A/AA/2.1 AA checks on representative Home, Contact, Swahili Admissions, Login and staff overview views, including mobile Home/overview. Contrast defects found during testing were corrected. This is not exhaustive manual/screen-reader accessibility certification.

The ordinary Playwright CDN download was unavailable in this sandbox. A compatible Chromium executable was obtained for the checks; standard Playwright installation remains the documented/CI path. Synthetic screenshots are ignored local test artifacts, not school/staff records.

### External observations, not deployment

Read-only retrieval confirmed the school site was reachable at `https://alpha-adventist-pre-primary-school.onrender.com/` on 30 September 2026. The non-hyphenated alternative returned “Not Found.” The existing live site still has its previous content/assets; changes in this branch have **not** been deployed there.

An earlier GitHub repository-access check returned HTTP 401. No visibility change, history rewrite or remote history cleanup was performed. If authentication errors recur, reconnect GitHub in Arena; coordinate history remediation separately with the owner.

## Explicitly unverified

- A live Mongo replica-set transaction/concurrency run, actual Mongo backup/restore/migration, driver/index deployment and multi-instance behavior.
- Malware scanning is not implemented; uploads rely on format checks, image re-encoding, encryption and review workflows, none of which detect all malicious content.
- Real email/SMS/WhatsApp receipt, downstream delivery callbacks, production TLS/proxy/CDN settings, external uptime/backup monitors, scheduled off-site backups and disaster-recovery RPO/RTO.
- School-approved HR/legal policies, guardian authority, real media consent, Maps pin, social identities, official-domain email, PDPC/legal compliance, independent penetration testing and production acceptance.

## Suggested next order

1. Owner completes P0 release gates, GitHub reconnection/history/asset cleanup, secrets rotation and infrastructure/provider configuration.
2. Rehearse with isolated Mongo staging, integration test recipients, restored backups and named independent role-holders; obtain school/legal acceptance and explicitly approve the risk of unscanned uploads.
3. Deploy the reviewed web/staff platform with monitoring and controlled rollback.
4. Extend admissions and add verified parent/student services; only then plan native mobile delivery.

## UI correction — original repository design

At the user’s request, the earlier alternate homepage and portal styling was removed. `public/css/main.css` is restored byte-for-byte from the starting GitHub commit. The homepage uses its original markup/sections, the original full navigation and mobile contact bar are retained, Parent Corner uses its original cards/dialogs with governed content, and the portal reuses the original CMS login/admin shell. EN/SW, new functionality, access controls and the recent sign-in/session fixes remain. Unverified photos and private artifacts were not restored. Browser regressions now cover the original slideshow controls and Parent Corner dialogs as well as the new workflows.
