# Alert Matrix

This defines alert contracts, not an activated monitoring vendor. Thresholds that require production
traffic evidence remain `TBD / operational config` and need owner approval.

| Alert | Severity | Condition | Owner | Response |
|---|---|---|---|---|
| `/ready` unavailable | SEV1 | Any core dependency readiness failure sustained beyond the operational window | On-call Operator | Check DB/Redis/queue, stop promotion, follow runtime recovery |
| Default queue backlog | SEV1/SEV2 | Sustained backlog or oldest-job age above `TBD / operational config` | On-call Operator | Check customer-facing workers, dependencies and failed jobs |
| Knowledge queue backlog | SEV2/SEV3 | Backlog/oldest age above `TBD / operational config` | Application Owner | Isolate document/embedding impact; default queue remains independent |
| Analytics queue backlog | SEV3 | Delayed aggregation above `TBD / operational config` | Application Owner | Recover analytics worker; reporting is degraded, core app is not automatically down |
| Failed jobs | SEV1/SEV2 | Failure rate or retry spike above `TBD / operational config` | On-call Operator | Inspect aggregate summary, classify and replay only safe jobs |
| Scheduler stale | SEV2 | Heartbeat older than 120 seconds | On-call Operator | Restart scheduler and verify heartbeat/recent schedule execution |
| Backup stale/failure | SEV1 | Missing backup, checksum/verification failure or restore drill beyond approved age | DBA/Infrastructure Owner | Freeze release, verify backup/restore evidence |
| Database saturation/unavailable | SEV1 | Connection, lock, saturation or replication/archive failure | DBA/Infrastructure Owner | Follow database recovery and migration lock procedures |
| Storage failure | SEV1/SEV2 | Private object read/write failure or unexpected public fallback | DBA/Infrastructure Owner | Stop affected uploads, preserve tenant isolation and recover storage |
| Reverb failure | SEV2 | WSS/upgrade/origin failures or realtime smoke regression | On-call Operator | Check Nginx/Reverb/Redis and explicit origins |
| Meta webhook failures | SEV2 | Signature, dispatch, delivery or rate-limit failure above `TBD / operational config` | Application Owner | Inspect safe metrics and replayable event queue; no raw payload logging |
| Stripe reconciliation | SEV1/SEV2 | Local/provider subscription or invoice state diverges | Billing Owner | Disable paid flow, reconcile idempotently, preserve Free access |
| Sentry errors | SEV2/SEV3 | Error-rate regression when Sentry is activated | Application Owner | Inspect scrubbed events; Sentry outage itself does not block traffic |

## Severity examples

- **SEV1**: cross-tenant access, database unavailable, secret exposure, failed readiness for customer traffic,
  or unverified backup during a schema release.
- **SEV2**: scoped WhatsApp delivery degradation, Reverb outage, repeated worker failures, or provider failure
  with core application operation preserved.
- **SEV3**: delayed analytics, non-critical reporting lag, or isolated optional telemetry degradation.

No personal names are recorded here. External dashboards, alert routes, thresholds and escalation schedules
remain OPS decisions. The current application emits structured logs, correlation IDs, fail-safe metrics and
health signals; it does not claim that an external alert backend is configured.
