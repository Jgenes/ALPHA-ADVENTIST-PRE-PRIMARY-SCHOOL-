# PHP persistence, backups and migration

The PDO store uses the existing MySQL 8 `adsp_*` tables (JSON payloads, revisions, generated-column indexes and append-only audit triggers). Local development uses the existing private SQLite `adsp.sqlite` file. Production uses **`alpha_school`**. Create it with the approved SQL template or the explicit `scripts/init-production.php --create-database` operator command; normal requests never create databases. No script creates arbitrary databases. See [production integrations](production-integrations.md) for required secrets, SMTP and persistent-disk verification.

## Existing audited platform cutover

Use the same protected application keys, persistent storage and schema in an isolated rehearsal first. Install `pdo_mysql` and sodium, verify the audit chain and representative file checksums, and perform an approved maintenance-window cutover. Do not replace the keys or treat code conversion as permission to deploy.

## Backup

```sh
BACKUP_WRITES_PAUSED=true php scripts/backup-mysql.php
```

Provide a separate `MYSQL_BACKUP_ENCRYPTION_KEY_FILE` with permissions 600 or stricter (or an approved environment secret). The archive includes consistent code-owned collections and referenced encrypted private files. It never includes application encryption/audit keys. Keep those escrowed separately. Large data libraries should use managed database/storage snapshots instead of JSON archives.

## Isolated restore

Set `RESTORE_APPROVED=true`, `MYSQL_RESTORE_URL`, an already-created `MYSQL_RESTORE_DB_NAME` ending in `_restore_test`, and an empty `RESTORE_DATA_DIR` distinct from live storage.

```sh
php scripts/restore-mysql.php /secure/backup.json.enc
```

Existing records are never overwritten. Sessions and download grants are invalidated; public files are not recreated. Supply the original application keys, verify audit/file integrity and reconcile consent withdrawals before any cutover. Object-storage archives restore to isolated local quarantine before a reviewed private-file transfer to a new bucket.

## Legacy MongoDB archives

No MongoDB server or driver is used by the PHP application. Approved `ALPHAMDB` archives can be read offline:

```sh
# Requires MYSQL_IMPORT_APPROVED=true and BACKUP_WRITES_PAUSED=true.
# Production/staging imports also require MYSQL_IMPORT_STAGE_VERIFIED=true.
MYSQL_IMPORT_DRY_RUN=true php scripts/import-mongodb.php /secure/legacy.json.enc
php scripts/import-mongodb.php /secure/legacy.json.enc
```

Use a distinct dedicated target schema. Audited `adsp_*` records use a guarded empty-target transfer. Retired public text is mapped to drafts and enquiries/admissions retain their private review/retention basis; retired accounts and photographs are not automatically imported or republished. `scripts/migrate-mysql.php` uses the same guarded PHP entrypoint.

See [PHP migration](php-migration.md) for the language-conversion details and [the README](../README.md) for current TLS/environment settings.
