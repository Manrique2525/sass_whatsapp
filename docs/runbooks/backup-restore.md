# Backup And Restore Runbook

## Policy

Backups are PostgreSQL custom-format dumps plus the separately managed object storage
backup for tenant media. Dumps must be encrypted at rest, transferred over TLS,
access-controlled to the database/platform operators, and retained according to the
approved retention policy. Never commit a dump, credential or production identifier.

The operational target to approve is an RPO of 15 minutes and an RTO of 30 minutes;
these are targets, not claims of production validation. The U2 rehearsal restored a
small 2-tenant dataset in approximately 701 ms, excluding provisioning, secrets,
object storage and application cutover.

## Backup

Run from a trusted operator environment using secret injection, not command-line
passwords:

```bash
pg_dump --format=custom --no-owner --no-privileges \
  --file=/secure/backup/whatsapp-$(date -u +%Y%m%dT%H%M%SZ).dump "$DATABASE_URL"
sha256sum /secure/backup/whatsapp-*.dump
```

Store the dump in the encrypted backup location and copy tenant media using the
approved S3-compatible replication policy. Record database version, schema revision,
UTC timestamp, checksum and retention expiry. A backup is not complete until both
database and media artifacts are available.

## Restore To A New Database

Never restore over the source database during an incident rehearsal. Provision an
isolated PostgreSQL database, restore the dump, then validate it:

```bash
pg_restore --list /secure/backup/selected.dump
createdb "$RESTORE_DATABASE"
pg_restore --exit-on-error --no-owner --no-privileges \
  --dbname="$RESTORE_DATABASE" /secure/backup/selected.dump
php artisan migrate:status
php artisan about
```

The rehearsal validated the custom-format archive with `pg_restore --list`, restored
it into a different database, and confirmed the application booted. The tested dump
was intentionally taken before the U2 migrations, so its restore correctly contained
the 57 pre-U2 migrations and did not contain the four U2 tables. Validate against the
backup's recorded schema point, not against the current release by assumption.

## Validation

- Compare tenant, subscription, conversation and message counts with the backup manifest.
- Check required indexes, foreign keys and constraints.
- Verify media objects exist and are readable from the restored storage prefix.
- Run application health checks with external providers disabled or sandboxed.
- Test a tenant-A read cannot access tenant-B data.
- Keep the source untouched until the incident owner approves cutover.

## Cutover And Retention

- Stop or drain writes according to the incident plan before any point-in-time cutover.
- Record the final source WAL/backup position and the restore validation result.
- Switch the database endpoint only through the deployment mechanism; do not edit application code or secrets ad hoc.
- Preserve the source database and original backup until the retention and incident owner approve deletion.
- Destroy temporary restore databases and decrypted local artifacts after evidence is recorded.

## Monitoring And Ownership

The platform owner owns backup scheduling and restore automation. The database
operator owns backup success, age, size, checksum, WAL/archive lag, restore drills
and RPO/RTO evidence. Alert on missed backups, stale backup age, replication/archive
lag, restore failure and capacity. Perform a restore drill at least quarterly and
after changes to database version, backup tooling or storage encryption.
