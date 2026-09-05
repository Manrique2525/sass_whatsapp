# Release Gate Matrix

This is the canonical go/no-go matrix for a release candidate. A local PASS means the
repository contract was verified; it does not claim that a production dependency is active.
`OPS-PENDING` is an honest blocking state for evidence that can only come from the target
environment.

| Gate | Required evidence | Blocking severity | Owner | Execution | Status |
|---|---|---|---|---|---|
| Git integrity | Clean tracked worktree, full SHA, origin relationship, no untracked secrets | P0 | Application Owner | Automated + review | LOCAL VALIDATED |
| Secret scan | Secret scanner and manual diff review find no credentials/private keys | P0 | Application Owner | Automated + review | LOCAL VALIDATED |
| Backend | `php -d memory_limit=512M vendor/bin/pest`, zero failures | P0 | Application Owner | Automated | LOCAL VALIDATED |
| PostgreSQL | Canonical `phpunit.pgsql.xml` suite, zero failures | P0 | DBA/Infrastructure Owner | Automated before RC | STAGING REQUIRED |
| Frontend | `npm run test`, zero failures | P0 | Application Owner | Automated | LOCAL VALIDATED |
| Type/build | `npm run typecheck` and `npm run build` | P0 | Application Owner | Automated | LOCAL VALIDATED |
| E2E | Fresh setup, `workers=1`, `retries=0`, zero failures | P0 | Application Owner | Automated before RC | STAGING REQUIRED |
| Tenant isolation | Cross-tenant reads/writes, IDOR, billing, inbox, knowledge, media, templates and users green | P0 | Application Owner | Automated | LOCAL VALIDATED |
| Security audits | `composer audit --locked` and `npm audit --audit-level=moderate` with zero blockers | P0/P1 | Application Owner | Automated | LOCAL VALIDATED |
| Migrations | `migrate:status`, exact files, rehearsal, lock review, verified backup, target identity | P0 | DBA/Infrastructure Owner | Manual before deploy | OPS-PENDING |
| Backup/restore | Timestamp, checksum, verification, operator, restore-drill age and media evidence | P0 | DBA/Infrastructure Owner | Manual before deploy | OPS-PENDING |
| Queues | Workers healthy; pending/reserved/delayed/failed jobs all zero after E2E/smoke; failed summary exposes only allowlisted class | P0 | On-call Operator | Automated + manual | STAGING REQUIRED |
| Scheduler | `schedule:work` configured and heartbeat newer than 120 seconds | P1 | On-call Operator | Automated + manual | STAGING REQUIRED |
| Health/readiness | `/health=200`, `/ready=200`; probes expose no secrets | P0 | On-call Operator | Automated + manual | STAGING REQUIRED |
| Logs/correlation | Structured JSON, request correlation, safe provider/tenant context, no PII | P1 | Application Owner | Automated + review | LOCAL VALIDATED |
| Reverb/TLS/proxy | Explicit origins, HTTPS/WSS, secure cookies, forwarded-proto and realtime smoke | P0 | DBA/Infrastructure Owner | Manual + smoke | OPS-PENDING |
| Storage | Private S3 config, no fallback, tenant isolation and object smoke | P0 | DBA/Infrastructure Owner | Manual + smoke | STAGING REQUIRED |
| Provider contracts | Each provider classified `DISABLED`, `CONFIGURED`, `READY` or `ACTIVATED`; no accidental calls | P1 | Application Owner | Automated + review | LOCAL VALIDATED |
| Release artifact | Immutable backend/web images mapped to the same full Git SHA; inspected contents | P0 | Application Owner | Automated + review | STAGING REQUIRED |
| Rollback | Code/config/provider disable paths selected; migration rollback or forward-fix decision recorded | P0 | Release Owner | Manual | OPS-PENDING |
| Smoke matrix | Landing, registration, verification, login, onboarding, dashboard, inbox, flow, knowledge, lead, analytics, logout | P0 | Application Owner | Automated + manual | STAGING REQUIRED |
| P0/P1 policy | No open P0 or P1; P2 only documented and explicitly non-blocking | P0 | Release Owner | Manual review | OPS-PENDING |

## Severity policy

- Any open P0 or P1 is **NO-GO**.
- P2 issues are allowed only when documented with owner, mitigation and explicit release-owner sign-off.
- No subjective exception overrides a security, tenant-isolation, secret, migration, backup or rollback gate.
- `/health` is liveness only. `/ready` checks DB, Redis and queue backend; external providers are operational concerns.

## Static and runtime commands

```bash
git status --porcelain --untracked-files=all
git rev-parse HEAD
git rev-parse origin/master
git diff --check
composer audit --locked
npm audit --audit-level=moderate
php -d memory_limit=512M vendor/bin/pest
npm run test
npm run typecheck
npm run build
```

Run the PostgreSQL suite and fresh E2E Compose gate before release-candidate approval using the
commands in `docs/testing.md`. Never substitute a skipped command with a fabricated PASS.
