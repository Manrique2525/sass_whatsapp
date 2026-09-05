# Release Checklist

This checklist is evidence-driven and non-deploying. Check an item only after recording the
command output or the named operational evidence. The release owner must mark unresolved
production-only items `OPS-PENDING`, not PASS.

## Pre-release

- [ ] Release owner, on-call operator, support owner and rollback owner identified by role.
- [ ] Candidate full Git SHA recorded; backend image, web image and optional Sentry release use that SHA.
- [ ] Tracked worktree clean; known runtime logs, `.env*`, backups, dumps and artifacts excluded.
- [ ] `git diff --check` passes; no untracked secret or provider response dump exists.
- [ ] P0 count is zero and P1 count is zero. Any P2 has written mitigation and sign-off.
- [ ] `composer audit --locked` and `npm audit --audit-level=moderate` have zero blocking advisories.
- [ ] Full backend, PostgreSQL, frontend, typecheck, build and fresh E2E evidence is attached.
- [ ] Tenant-isolation, IDOR, webhook signature, AI entitlement and upload-security suites are green.

## Deployment preparation

- [ ] `.env.production` comes from the secret manager; `.env.production.example` is names/placeholders only.
- [ ] Provider states are recorded separately: Meta, Stripe, OpenAI, Sentry and Mail.
- [ ] Free private beta minimum is met: TLS, production mail, backup/restore evidence, DB/Redis,
      workers, scheduler, health/readiness and support ownership. Meta is required if WhatsApp is used.
- [ ] Stripe is not required for the Free-only beta. Paid plans require approved catalog, currency,
      refund/tax policy, webhook, reconciliation and billing ownership.
- [ ] Migration decision is explicit. If migrations exist, list exact files, rehearsal, lock risk,
      backup, target identity and rollback/forward-fix decision. Otherwise record `NO MIGRATIONS`.
- [ ] `docker compose -f docker-compose.production.yml config --quiet` passes with synthetic/non-secret
      interpolation only; no registry push or production operation is part of this checklist.
- [ ] Runtime and web images are built from the candidate SHA and inspected for `.env`, secrets,
      backups, runtime logs, test-only material and unnecessary development tooling.
- [ ] Runtime user is verified as `www-data`; internal services have no public host ports.

## Health, smoke and observation

- [ ] `/health` returns liveness `200` without requiring external providers.
- [ ] `/ready` returns `200` only with DB, Redis and queue backend healthy, without secret details.
- [ ] Workers for `default`, `knowledge` and `analytics` are healthy; scheduler heartbeat is newer than 120 seconds.
- [ ] Final queue counts are pending `0`, reserved `0`, delayed `0` for `default`, `knowledge` and
      `analytics`, with failed jobs `0`.
- [ ] Failed-job summary records count, queue, allowlisted job class and failure time without payload, PII or secrets.
- [ ] Structured logs include safe request correlation and exclude message bodies, tokens and authorization data.
- [ ] Reverb explicit-origin/WSS smoke passes; secure cookies and forwarded-proto behavior are verified.
- [ ] Private storage and tenant object-prefix smoke passes; no public/local fallback is enabled.
- [ ] Staging smoke passes: landing, registration, verification, login, onboarding, dashboard, WhatsApp boundary,
      inbox, flow, knowledge, lead, analytics and logout.

## Post-release observation and rollback trigger

- [ ] Observe readiness, default-queue age/backlog, failed jobs, scheduler heartbeat, provider errors,
      storage failures, Reverb errors and database saturation during the agreed observation window.
- [ ] Roll back or disable the affected provider if error rate, tenant isolation, queue health,
      readiness, authentication or mail verification regresses.
- [ ] Record final go/no-go decision, evidence links, operator role and timestamp.

See `release-gates.md`, `alert-matrix.md` and `rollback.md` for the authoritative policies.
