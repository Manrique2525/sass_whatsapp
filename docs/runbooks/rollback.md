# Rollback Runbook

Rollback is an incident procedure. It is not authorization to deploy or migrate. Keep the
source release, database and backups available until the incident owner approves disposal.

## Decision

1. Declare the incident severity and assign the Release Owner and On-call Operator.
2. Stop promotion and record the candidate SHA, current SHA, symptoms, queues and readiness state.
3. Choose the smallest safe action: code rollback, config rollback, provider disable, migration
   forward-fix, or restore to a new database. Do not combine actions without recording dependencies.

## Code rollback

- Select the previous immutable backend/web images by full Git SHA, never a mutable `latest` tag.
- Verify image digests and the matching SHA before replacing services.
- Preserve the failed release logs in the approved incident store without committing them.
- Restart workers gracefully, verify `/health`, `/ready`, scheduler heartbeat, queue counts and smoke paths.

## Configuration and provider disable

- Restore the last known-good secret/config version from the secret manager without printing values.
- Meta: disable the affected tenant connection or webhook path after preserving inbound evidence.
- Stripe: disable paid checkout/webhook processing; keep the Free plan available and reconcile before re-enable.
- OpenAI: disable the AI entitlement/provider and preserve safe fallback behavior.
- Sentry: remove the DSN; application traffic must continue without telemetry.
- Mail: switch only to an approved remote provider; never fall back to `log`, `array`, Mailpit or loopback in production.

## Migrations

- A mechanically reversible migration is not automatically production-safe after traffic has used it.
- Do not run `migrate:rollback` reflexively. First check dependent code, live data, locks, backup and
  whether rollback would destroy constraints, reservations, media or template metadata.
- For the current four U2 migrations, follow `database-migrations.md`; migration 2 requires the
  documented maintenance/lock review and migrations 3/4 depend on it.
- If rollback risks data loss or mixed-version behavior, prefer a forward fix or restore to a new
  database and validate tenant isolation before cutover.

## Forward fix and recovery

- Freeze further schema changes, capture `migrate:status`, queue state and backup identifiers.
- Apply a reviewed forward fix only after rehearsal and target identity verification.
- Restore only into a separate database during rehearsal; keep the source untouched until cutover approval.
- Re-run health, readiness, queue, tenant-isolation, storage and smoke gates before reopening traffic.

Every action requires timestamp, operator role, SHA/config version, evidence and approval. No rollback
step sends a provider request unless separately authorized as production operations.
