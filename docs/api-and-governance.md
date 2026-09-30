# API and governance notes

## Request contract

The vanilla-JS portal calls the same server-authorised APIs a separate client would use. Hiding a navigation item is not an access control.

1. `GET /api/auth/session` obtains an anonymous session and CSRF value.
2. Refresh `GET /api/auth/session` immediately before sign-in, then `POST /api/auth/login` with the cookie, CSRF header and named credentials. For an enrolled account, a correct password without a code returns `requiresMfaCode: true` without authenticating the session. Submit the credentials plus the current TOTP code to complete sign-in; existing clients may still supply both factors together. The initial browser form contains only username and password.
3. Complete mandatory password rotation/MFA before calling business APIs.
4. For mutating authenticated requests, send JSON, the cookie and `X-CSRF-Token`. Cross-site origins are rejected. CSRF values are never placed in URLs.
5. Updates/decisions include the record’s numeric `revision`; stale writes return 409. Reload instead of blindly repeating an approval.
6. Check both action permission and returned resource scope. An ID/reference, role label, client-submitted owner or browser button is not authorisation.

Business responses use `{ ok: true, data: ... }`; authentication/public-form responses have top-level fields. Failures use `{ ok: false, code, message }`. Authentication/authorisation failures are generally 403, invalid sign-in is 401, conflicts 409, rate limits 429, oversized bodies 413. A missing scanner returns 503; binary upload is not silently accepted. Private responses use `no-store`.

No client bearer token is saved in local/session storage. The opaque session cookie is HttpOnly/SameSite=Lax and Secure outside development. Only development requests on the HTTPS `port-sandbox.e2b.app` preview host use Secure, SameSite=None, Partitioned cookies so an embedded preview can retain its session. Origin and CSRF checks still apply. Login retries a CSRF failure once after refreshing the session; business mutations are never automatically retried. The service re-reads the active session and user authority in the same transaction as business actions.

## Endpoint groups

The exact dispatcher is `src/platform/router.js`; there are no wildcard model endpoints.

| Group | Main routes |
| --- | --- |
| Auth | `GET /api/auth/session`; `POST /api/auth/{login,logout,password,mfa/setup,mfa/confirm}` |
| Context/accounts | `GET /api/{meta,permissions,roles,users,departments}`; `POST /api/users`; `PATCH /api/users/:id` |
| Staff | `GET /api/staff`, `/api/staff/directory`; `PUT /api/staff/:userId` |
| Leave | `GET/POST /api/leave`, `/api/leave/balances`; `PUT /api/leave/:id`, `/api/leave/types/:id`; `POST /api/leave/:id/action` |
| Contracts | `GET/POST /api/contracts`; `PUT /api/contracts/:id`; `POST /api/contracts/:id/submit`; a new contract can reference the contract it replaces |
| Documents | `GET/POST /api/documents`; `POST /api/documents/:documentId/versions`; `POST /api/documents/:versionId/action` |
| Downloads | `POST /api/{documents,contracts,leave,media}/:resourceId/download-link`; `GET /api/downloads/:oneUseToken`; public `GET /downloads/:documentId` |
| Workflows | `GET/POST /api/workflows`; `PUT /api/workflows/:id`; `GET /api/approvals`, `/api/requests`; `POST /api/approvals/:id/decision` |
| Notices | `GET/POST /api/notices`; `PUT /api/notices/:id`; `POST /api/notices/:id/{publish,acknowledge}`; `GET /api/notices/:id/report` |
| Calendar | `GET/POST /api/calendar` (private audiences); public events use reviewed CMS |
| CMS | `GET/POST /api/cms`; `PUT /api/cms/:id`; `POST /api/cms/:id/action`; kinds: news, page, event, vacancy, FAQ, banner, settings |
| Enquiries/admissions | `GET /api/admissions`, `/api/submissions`; `PATCH /api/{admissions,submissions}/:id` |
| Staff notifications | `GET /api/notifications`; `POST /api/notifications/:id/read` |
| Privacy | `GET /api/privacy`; `POST /api/privacy/consents`; consent `/:id/{withdraw,removal}`; `PATCH /api/privacy/requests/:id`; `POST /api/privacy/incidents`; `PATCH /api/privacy/incidents/:id` |
| Media | `GET/POST /api/media`; `POST /api/media/:id/action`; consent-checked public `GET /media/:id` |
| Oversight | `GET /api/{dashboard,search,reports,audit,system}`; `POST /api/system/alerts/:id/retry` |
| Public forms | `POST /api/{contact,visit,apply,computer-class,computer-interest,safeguarding,privacy-request}` |
| Infrastructure | `GET /healthz`, `/readyz`, `/robots.txt`, `/sitemap.xml`, `/site.webmanifest`, `/service-worker.js`, `/offline` |

Public forms use purpose-specific whitelisted fields, a privacy acknowledgement and optional client-generated `submissionKey` for deduplication. They do not need a staff login. A confirmation is a saved office reference, not an admission offer or proof of external message delivery. The per-source form limit is eight/hour. Public uploads and anonymous reference-to-private-record lookup are not exposed.

Files are bounded base64 JSON (`file: { name, content }`), decoded size at most 5 MB. Only safe UTF-8 text or scanned PDFs are accepted for documents/contracts; media accepts scanned and re-encoded JPEG/PNG. Office/HTML/SVG uploads are not supported. Original filenames never become storage paths. Quarantine is private; approved public copies have their own storage area.

## Workflow configuration

Definitions hold an ID, kind, version and ordered steps. A step specifies an ID/label, selector (`role`, `department`, `supervisor`, or the special contract `owner` acknowledgement), required action permission and applicable roles. The only current conditional step is a contract’s optional financial review. The server validates compatible selectors/roles and mandatory authority; there is no arbitrary executable expression supplied by a client.

Each submission records a route snapshot; editing a definition affects future submissions only. Assigned approvers can approve, reject, return, comment or escalate. Decisions record actor, timestamp, stage and a session reference. Optimistic revisions and transactional audit updates prevent duplicated decisions. Escalation is a notification, not delegation.

Department-scoped reads require a matching recorded department. Contract reviewer visibility is limited to their pending assigned stage, except HR/authorised management and the employee’s own issued/acknowledgement records. Confidential document access requires both an explicit ACL/ownership match and the relevant classification permission; merely being an administrator does not satisfy it. New authors see/edit their own CMS drafts; editors have explicit `cms.edit_any`.

## Storage and audit

`src/platform/store.js` implements serialised SQLite `BEGIN IMMEDIATE` transactions and Mongo snapshot/majority transactions. Mongo must be a replica set or sharded transaction-capable deployment. Both stores use the `adsp_` namespace, record revisions and uniqueness constraints. The implementation is sized for a small school; many reports currently read/filter collections in memory. Pagination/index/query optimisation and shared object storage are required before substantial scale-out.

Files and database commits are separate physical systems. Failed uploads are discarded; published-file routes always recheck committed authority/state. Operations must monitor missing/orphaned files and reconcile storage after failures/restoration. Do not claim database transactions make the filesystem transactional.

HMAC audit chaining and its sequence/head are updated in the business transaction. The application has no audit update/delete API; SQLite also enforces triggers. Mongo administrators and secret custodians remain trusted operators: independent protected checkpoints/export/least-privilege database policy are necessary to detect a malicious full rewrite by someone holding every key.

## Test boundaries

`test/platform.test.js`, `test/operations.test.js` and `scripts/ui-smoke.js` are executable acceptance evidence using generated credentials/synthetic data. No production identity or child record is bundled. See `delivery-status.md` for exactly what was run and what remains unverified. A CI definition is included, but no GitHub workflow run has been claimed.
