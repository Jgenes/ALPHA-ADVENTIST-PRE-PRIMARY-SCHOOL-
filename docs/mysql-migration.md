# MySQL data, backup and recovery

The application uses PHP PDO with MySQL 8 InnoDB tables in the `adsp_*` namespace, JSON record payloads, revisions, generated-column indexes and append-only audit triggers. The schema name is configurable, including cPanel-prefixed names. Create an empty database with cPanel or phpMyAdmin, configure `alpha-school/config.php`, and run `php alpha-school/scripts/init-production.php`.

**Existing installations:** changing `MYSQL_DB_NAME` does not migrate records. Do not point this release at another database or replace application keys without a protected backup and an isolated rehearsal. Previous alternate database/storage adapters and legacy archive import commands are not part of this simplified release. Plan any migration from a different store separately; never publish old media or grant access solely because a file/account existed before.

## Backup

Assign an owner, approved schedule/retention, and recovery-time/recovery-point objectives. Pause **all web and cron writers** during a coordinated backup. `BACKUP_WRITES_PAUSED=true` is an operator attestation, not a write-blocking feature.

Supply a separate `MYSQL_BACKUP_ENCRYPTION_KEY_FILE` (regular file, permissions `600` or stricter) or `MYSQL_BACKUP_ENCRYPTION_KEY` (64 hex characters). It must differ from the application storage/audit keys.

```sh
BACKUP_WRITES_PAUSED=true php alpha-school/scripts/backup-mysql.php
```

The tool creates an authenticated encrypted archive of application collections and referenced encrypted private files, with a checksum. It excludes application keys and public media copies. Default output is the private `DATA_DIR/backups` directory; override with `MYSQL_BACKUP_DIR` outside the web root. Transfer it to approved encrypted off-site storage, verify checksum/upload, then resume writers and check readiness.

This bounded archive format is for small installations (up to 512 MB total, with a 256 MB stored-file capture limit). For larger data use coordinated managed MySQL/filesystem snapshots. Database-only backups cannot recover encrypted uploads. Back up and escrow keys separately, and monitor backup failures/age.

## Isolated restore drill

Use a private non-production operator environment, not the live configuration:

- `APP_ENV=development`, `RESTORE_APPROVED=true`.
- `MYSQL_RESTORE_URL` pointing to an already-created empty schema ending in `_restore_test`.
- Matching `MYSQL_RESTORE_DB_NAME`, different from the original/live schema.
- Empty `RESTORE_DATA_DIR`, separate from live storage.
- Original storage/audit keys and the separate backup key, supplied privately.
- No live email recipients or scheduled jobs during initial inspection.

```sh
php alpha-school/scripts/restore-mysql.php /secure/backup.json.enc
```

The restore authenticates the archive, validates file hashes, refuses existing records, and invalidates sessions and download grants. It never automatically restores public media availability. If restoration fails partway through, leave the partial target isolated, investigate and retry into a fresh empty target; never overwrite live records.

Verify audit integrity, representative record counts, file decryption/checksums and role denials. Review publication/consent changes since the snapshot. Record actual recovery time and approval before a separate controlled cutover. Do not run a recovered outbox against real recipients during a rehearsal.

## Updates and rollback

Keep a protected previous code release and compatible data/file backup. Preserve all encryption keys. Rehearse updates before a maintenance window and verify readiness afterward. Do not deploy old insecure code against the protected live database. A data/schema rollback needs its own reconciliation/approval procedure.
