# Database Migration Runbook

## Scope

This runbook covers the known release migrations pending production verification. It
applies to the PostgreSQL 16 database used by the application and must be executed by
an operator with a recent backup and database owner privileges. It does not authorize
production execution by itself. The list below is not a claim that these are the only
migrations missing in production; production state must be confirmed with
`migrate:status`.

## Migration Order

Run the migrations in this order with the normal Laravel migration command:

1. `2026_08_25_100001_create_usage_reservations_table`
2. `2026_08_26_000001_add_tenant_id_id_unique_to_messages_and_whatsapp_accounts`
3. `2026_08_26_000002_create_message_media_table`
4. `2026_08_26_000003_create_whatsapp_templates_table`
5. `2026_09_14_000001_create_platform_mfa_credentials_table`

Migration 3 depends on the composite key created by migration 2. Migration 4 depends
on the composite key for WhatsApp accounts created by migration 2. Migration 5 is
the platform-admin MFA credential schema and is independent of migrations 1-4.

## Preflight

- Confirm the release, database, operator, maintenance window and rollback owner.
- Confirm the database identity (host, database name and expected environment) from
  the release connection, without printing credentials.
- Confirm a successful backup and record its SHA-256 checksum.
- Confirm the application queue can be paused or drained if the deployment policy requires it.
- Run `php artisan migrate:status` before applying anything and record the result.
- Compare the result with the known release list above; do not assume the list is complete.
- Run the duplicate checks below before migration 2:

```sql
SELECT tenant_id, id, count(*)
FROM messages
GROUP BY tenant_id, id
HAVING count(*) > 1;

SELECT tenant_id, id, count(*)
FROM whatsapp_accounts
GROUP BY tenant_id, id
HAVING count(*) > 1;
```

Both queries must return zero rows. Do not silently delete or merge duplicates.

- Inspect long-running sessions and locks on `messages` and `whatsapp_accounts`.
- Cancel the release if there is an active long transaction against either table.

## Execution

Use the release image and the production environment configuration. Never put a
password in shell history or in this document. Apply a bounded session-level
PostgreSQL `lock_timeout` through the deployment/database connection mechanism;
for example, an operator may use `PGOPTIONS='-c lock_timeout=5s'` if that mechanism
is supported and verified for the release connection. Confirm the active value with
`SHOW lock_timeout` before running the migration. Do not silently continue without a
bounded timeout when the release requires one.

```bash
php artisan migrate:status
php artisan migrate --force --no-interaction
php artisan migrate:status
```

Capture stdout, duration, migration status and database logs. Do not use
`migrate:fresh`, `migrate:reset` or an ad-hoc SQL equivalent in a shared environment.

## Lock And Timing Evidence

The rehearsal used 100,000 messages and 10 tenants. PostgreSQL-reported migration
durations without contention were approximately 12 ms, 96 ms, 16 ms and 16 ms for
migrations 1 through 4. End-to-end container wall time was approximately 3.0 s.

Migration 2 requests `AccessExclusiveLock` on `messages` and
`whatsapp_accounts`. A synthetic 15-second `AccessShareLock` holder caused the DDL
to wait approximately 11.5 seconds and caused a concurrent read to wait the same
period. A concurrent insert completed before the DDL acquired its lock; this is not
evidence that writes are safe once the exclusive lock is held. Treat migration 2 as
requiring a maintenance window and a lock timeout.

Recommended operational controls:

- Set a bounded `lock_timeout` for the migration session.
- Abort and retry during a cleaner window if the timeout is reached.
- Monitor `pg_stat_activity`, `pg_locks`, application error rate and queue depth.
- Do not increase the timeout blindly to force the migration through user traffic.

## Rollback And Recovery

Rollback is a release emergency procedure, not the normal correction path:

| Migration | Down before dependent migrations | Data-loss risk | Preferred correction |
|---|---|---|---|
| usage reservations | Yes | Reservation rows can be lost | Forward fix or restore |
| composite unique keys | Yes, before migrations 3/4 | Removing integrity | Forward fix after duplicate review |
| message media | Yes | Media metadata can be lost | Forward fix or restore |
| WhatsApp templates | Yes | Template metadata can be lost | Forward fix or restore |

If migration 2 fails, migrations 3 and 4 must not be applied. PostgreSQL DDL is
transactional for the tested failure case: a failed dependent foreign key left no
table and no migration record. If rollback would remove live data or constraints,
restore to a separate database, validate it, and follow the incident decision rather
than improvising destructive SQL.

## Postflight

- Confirm all four migrations are marked as applied.
- Verify the expected tables, unique indexes, foreign keys and positive-quantity check.
- Run a tenant-isolation smoke test and application health checks.
- Confirm queues, webhook ingestion and message reads are healthy.
- Record timings, lock waits, errors and operator decision in the release evidence.
