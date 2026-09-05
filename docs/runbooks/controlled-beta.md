# Controlled Beta Readiness

This runbook records the FASE34 U6 release-candidate assessment. It is a
non-deploying decision document. Local validation proves the application
contract only; target-environment evidence must be supplied by the operator.

## Decision

| Launch mode | Decision | Reason |
|---|---|---|
| Free private beta | CONDITIONAL GO | Code and local readiness are green, but production domain, TLS, mail, backup/restore, support ownership and approved recovery targets are not evidenced. |
| Paid beta | NO-GO | Paid catalog, pricing, currency, Stripe ownership/configuration, reconciliation and payment policies are not approved or activated. |

`CONDITIONAL GO` does not authorize deployment. It means the listed
pre-launch conditions may be completed and reviewed before launch.

## Mandatory Free-Beta Conditions

- [ ] Small controlled cohort and admission criteria approved.
- [ ] Production domain approved and DNS ownership confirmed.
- [ ] HTTPS/TLS certificate active and renewal owner assigned.
- [ ] Production PostgreSQL and Redis provisioned, reachable and version-recorded.
- [ ] `migrate:status` captured against the intended production database; no production connection is performed by U6.
- [ ] Production `APP_KEY` and all required secrets injected by the approved secret manager.
- [ ] Production SMTP/API mail provider configured with TLS, sender identity, SPF, DKIM, DMARC and bounce monitoring.
- [ ] A real production database and object-storage backup exists, is encrypted, checksummed and restorable.
- [ ] Restore drill evidence names the backup, target database, operator, timestamp and tenant-isolation checks.
- [ ] Workers for `default`, `knowledge` and `analytics`, scheduler and Reverb are healthy.
- [ ] `/health` and `/ready` are monitored; alert and escalation owners are assigned.
- [ ] Support email, Support Owner and Incident Commander communication path are approved.
- [ ] RPO/RTO targets are approved by the business, or the temporary beta exception is explicitly accepted.
- [ ] Meta prerequisites are complete only if real WhatsApp connectivity is in cohort scope.
- [ ] OpenAI remains disabled unless its entitlement, key and operating owner are approved.
- [ ] Stripe remains disabled for Free-only beta.

## Business Input Matrix

| Input | Free beta | Paid beta | Status |
|---|---|---|---|
| Brand name | Required | Required | Pending operator/business input |
| Legal entity and legal review | Required | Required | Pending |
| Support email | Required | Required | Pending |
| Production domain | Required | Required | Unknown |
| Pricing and currency | Not required | Required | Missing for paid beta |
| Stripe ownership/catalog | Not required | Required | Missing for paid beta |
| Meta ownership/WABA scope | Required only with WhatsApp | Required if WhatsApp is included | Pending scope |
| Approved RPO/RTO | Required before full launch | Required | Pending |
| Launch cohort and admission criteria | Required | Required | Pending |
| Support and incident owner | Required | Required | Pending |

## Operations Input Matrix

| Input | Status |
|---|---|
| TLS certificate and renewal | OPS-PENDING |
| Trusted proxy values | OPS-PENDING |
| `APP_KEY` availability | OPS-PENDING; do not print or inspect the value |
| PostgreSQL credentials and target identity | OPS-PENDING |
| Redis credentials and namespace | OPS-PENDING |
| Mail credentials and sender DNS | OPS-PENDING |
| S3 credentials, private bucket and encryption | OPS-PENDING |
| Sentry DSN | Optional; OPS-PENDING if selected |
| Meta secrets | OPS-PENDING only for WhatsApp scope |
| Stripe secrets | N/A for Free-only; OPS-PENDING for paid |
| OpenAI key | N/A while AI is disabled |

## Go-Live Day

### T-24h

- Confirm candidate full SHA, image digests, approvals, backups and restore evidence.
- Confirm migration status, lock review, queue drain plan, support rota and incident channel.
- Confirm provider scope: Free-only, WhatsApp-enabled or disabled; Stripe remains disabled unless paid approval exists.

### T-1h

- Verify TLS, DNS, mail verification path, `/health`, `/ready`, workers, scheduler heartbeat and Reverb origin.
- Confirm zero failed jobs and no unexpected queue backlog.
- Record the operator roles and the rollback trigger owner.

### Deploy Window

- Use immutable images identified by the candidate SHA.
- Execute migrations only under the U2 migration runbook and explicit production authorization.
- Run the smoke matrix before admitting the cohort. U6 itself performs none of these production actions.

### Post-Deploy

- Verify landing, registration, email verification, login, onboarding, dashboard, inbox, reply, flows,
  Knowledge, leads, analytics and logout.
- Verify tenant isolation, queue drain, health/readiness, scheduler heartbeat, mail and selected provider boundaries.

### Monitoring Window

- Use enhanced monitoring during the initial beta window for readiness, 5xx, auth, queues, scheduler,
  storage, mail, Reverb, tenant isolation and provider errors.
- Staffing and observation duration require business approval; this document does not invent an SLA.

## Immediate Rollback or No-Go Triggers

- Cross-tenant read/write or IDOR exposure.
- Migration inconsistency, database corruption or failed recovery path.
- Authentication, email verification or session security regression.
- Irrecoverable queue growth, repeated worker failure or webhook corruption.
- Secret exposure or unexpected provider calls.
- Sustained high 5xx, readiness failure or object-storage data loss.

Follow `rollback.md` and prefer a forward fix or restore to a separate database when live data makes
schema rollback unsafe.

## Roles

- Release Operator: executes the approved release procedure.
- Application Owner: owns code, tests and application rollback.
- Infrastructure Owner: owns DNS, TLS, secrets, database, storage and runtime.
- Incident Commander: owns severity, coordination and stop/rollback decisions.
- Support Owner: owns cohort communication and support escalation.

## U6 Local Evidence

- Candidate: `0e089b720683b65ae300ef6b03720cff4852c8f7`.
- Backend: `2606 passed / 15 skipped`; PostgreSQL: `185 passed / 0 failed`.
- Frontend: `592 passed`; E2E: `39 passed`, serial, zero retries.
- Health/readiness, provider boundaries, queue cleanup, image labels, Compose and Nginx syntax passed.
- Real provider calls: Meta 0, Stripe 0, OpenAI 0, Sentry 0, production SMTP 0, AWS 0.
- Production deploy, migration, DNS, TLS, secret injection and provider activation: not executed.
