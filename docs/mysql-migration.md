# MySQL: setup, migration and operations

The platform stores its audited records in MySQL 8 (InnoDB) instead of MongoDB. The
application code above the store did not change: `src/platform/store.js` still hands every
unit of work a transaction with `get`, `list`, `insert`, `update` and `remove`, and
`src/platform/mysql-store.js` implements that contract on one pooled MySQL connection.

## What changed physically

| Concern | MySQL implementation |
| --- | --- |
| Tables | One table per collection, `adsp_<name>`, in a dedicated schema |
| Row layout | `seq` (auto-increment, insertion order), `id` (`VARCHAR(191)` binary collation, primary key), `revision`, `payload JSON` |
| Concurrency | InnoDB transaction per unit of work; every update re-checks `revision`, so a lost update is a `409 VERSION_CONFLICT`, exactly as before |
| Uniqueness | `VARCHAR` virtual generated columns extracted from `payload` with `UNIQUE` indexes (username, staff ID, learner reference, learner↔guardian, class↔teacher, one attendance row per learner per day, one slug per content kind) |
| Audit chain | `BEFORE UPDATE`/`BEFORE DELETE` triggers on `adsp_audit_logs` refuse in-place rewriting |
| Deadlocks/lock timeouts | Translated into the same `409` conflict answer rather than retried behind the caller's back |
| Engine checks | Startup refuses a non-InnoDB `adsp_*` table or an isolation level below `READ-COMMITTED` |
| Audit head | Read with `SELECT … FOR UPDATE`, so simultaneous audited requests queue briefly on that row instead of failing as a version conflict |

Records are JSON documents, so the migration did not need a table-per-field rewrite; the
constraints that used to live in MongoDB validators and indexes now live in the schema.

## Environment

One connection string is enough:

```sh
MYSQL_URL=mysql://alpha_app:secret@db.internal:3306/alpha_school
MYSQL_DB_NAME=alpha_school          # optional; must agree with the path in MYSQL_URL
```

or the individual settings — useful when a platform injects database credentials itself:
`MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD` (or `MYSQL_PASSWORD_FILE`),
`MYSQL_DB_NAME`, and optionally `MYSQL_SOCKET` for a local unix socket.
Explicit settings win over the connection string.

| Setting | Default | Notes |
| --- | --- | --- |
| `MYSQL_SSL_MODE` | `verify-full` outside development, `disabled` in development | `disabled`, `required` (encrypted, certificate not verified), `verify-ca`, `verify-full` |
| `MYSQL_TLS_CA_FILE` | system trust store | PEM bundle for a private CA |
| `MYSQL_TLS_SERVER_NAME` | connection host | For a certificate issued to another name (SNI/verification) |
| `MYSQL_POOL_SIZE` | 15 | Connections per instance; each transaction takes one from the pool |
| `MYSQL_CONNECT_TIMEOUT_MS` | 10000 | |
| `MYSQL_CREATE_SCHEMA` | false | Let startup create a missing schema. Development/rehearsal only; a production typo must fail loudly |
| `MYSQL_REQUIRE_AUDIT_TRIGGERS` | true | Startup also installs `BEFORE UPDATE`/`BEFORE DELETE` triggers on the audit table and refuses to run without them. Set to `false` only when the provider denies trigger creation |

Outside development a missing or half-configured MySQL setup stops startup; there is no
silent fallback to SQLite. Public preview (`PUBLIC_PREVIEW=true`) refuses any `MYSQL_*`
setting so it can never write into the school database.

## Preparing the schema

```sql
CREATE DATABASE alpha_school CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'alpha_app'@'%' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, TRIGGER, REFERENCES
  ON alpha_school.* TO 'alpha_app'@'%';
```

`CREATE`/`ALTER`/`INDEX`/`TRIGGER` are needed because the application owns its schema and
creates missing tables and the audit triggers at startup. Binary logging does not block
trigger creation, but a provider that denies it must either grant `TRIGGER` or be told to
skip the guard with `MYSQL_REQUIRE_AUDIT_TRIGGERS=false` — a deliberate downgrade from a
database-enforced audit trail to an application-enforced one, so it is never silent. Grant the account access to
**one** schema only, and give the backup/restore operators a separate account: a restore
needs `CREATE` on a second schema, and an import rehearsal should be read-only.

A pre-created, empty schema also works — the DDL is `CREATE TABLE IF NOT EXISTS`, so
starting the app twice is safe. Table definitions come from code, never from a backup.

## Rehearsing and verifying

```sh
MYSQL_URL=mysql://alpha_app:secret@staging-db.internal:3306/alpha_staging npm run test:mysql
```

That runs the platform's HTTP API, authentication, approval workflows and audit chain
against a temporary `adsp_test_*` schema that it creates and drops itself. CI does the same
against a throwaway MySQL 8 service on every push.

## Cutover from a MongoDB deployment

1. Freeze writers and take a final encrypted archive with the **previous release** of the
   code (`npm run backup:mongodb`), then restore its `MONGO_BACKUP_ENCRYPTION_KEY` (the
   current release also reads that variable name for exactly this step).
2. Create the empty MySQL schema and set the target `MYSQL_*` values.
3. Preview the mapping without writing anything:
   `MYSQL_IMPORT_DRY_RUN=true npm run import:mongodb -- /protected/archive.ejson.enc`
   It reports per-table row counts, which access tables are cleared and which archive
   collections were left for the reviewed legacy migration (old CMS `news`, `submissions`,
   `platform_state` are not silently copied).
4. Run the import into the empty schema with `MYSQL_IMPORT_APPROVED=true`
   (`MYSQL_IMPORT_STAGE_VERIFIED=true` outside development). Records keep their ids and
   revisions, so in-flight optimistic-edit tokens remain valid.
5. Start the application against MySQL with jobs enabled and verify: the portal system
   page reports `MySQL`, the audit chain verifies, one controlled document downloads, one
   approval round trip completes, and the retention job runs.
6. Keep MongoDB running read-only, with no credentials for the app user, until the school
   signs off. Then take a `npm run backup:mysql` archive, restore it into a
   `<name>_restore_test` schema and re-verify — a backup is only real once it has been
   restored somewhere.

Legacy form/text data from the pre-platform site still uses `npm run migrate:mysql`, which
reads the same archive format and is independent of the database engine.

## Operations

- Backups: `npm run backup:mysql` needs `BACKUP_WRITES_PAUSED=true`, `DATA_DIR`,
  `MYSQL_BACKUP_ENCRYPTION_KEY` (or `_FILE`) and optionally `MYSQL_BACKUP_DIR`. It refuses
  a schema with missing or unexpected `adsp_*` tables rather than writing a partial archive.
- Restores: `npm run restore:mysql -- /protected/archive.json.enc` into an
  `<name>_restore_test` schema that has no tables, with `RESTORE_APPROVED=true`. Sessions
  and download grants are never restored, so old cookies and signed links stay dead.
- Monitoring: watch connection-pool saturation against `MYSQL_POOL_SIZE` × instances,
  `Innodb_row_lock_time_avg`, deadlock counts and replication lag if the schema is
  replicated. `/healthz` and `/readyz` prove the database answers, nothing more.
- Scale-out: MySQL removes the single-writer limitation of the SQLite file, but uploaded
  files still live in `DATA_DIR`. More than one instance requires the Supabase S3 storage
  adapter, and scheduled jobs must be limited to one instance until they claim work in the
  database.
