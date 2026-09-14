# FASE 37 - Functional QA and commercial readiness

## Scope and environment

QA was executed locally on the published FASE36 source with Docker Compose and the isolated E2E
stack. No production environment, provider credentials, production migration, deploy, or push was
used. The synthetic E2E accounts were used where browser fixtures were required; the local QA
accounts were used for Mailpit password-reset and invitation checks.

## Manual and functional coverage

- Platform Admin: PASS. Login, MFA challenge, dashboard, customers, plans, subscriptions, security,
  logout and denial for tenant roles were exercised.
- Owner: PASS. Tenant dashboard, navigation, inbox, contacts, leads, flows, FAQ, Knowledge,
  analytics, users, business profile, WhatsApp and billing routes were smoke-tested at 390, 768,
  1024 and 1440 pixels.
- Admin: PASS. Allowed tenant routes and Platform denial were validated.
- Agent: PASS. Operational routes were available; billing, restricted analytics actions and Platform
  access were denied according to permissions.
- Tenant isolation: PASS. Cross-tenant conversations, tenant switching, realtime channels, flow
  data, Knowledge data and Platform boundaries were covered by fresh E2E and backend tests.
- Onboarding: PASS. Fresh registration, verification, provisioning, Free plan and dashboard were
  exercised by Playwright.
- Password reset: PASS. Forgot-password, Mailpit delivery, reset link, new password and login were
  completed against the local stack.
- Invitations and roles: PASS. Tenant B invitation and Mailpit delivery succeeded. Tenant A at the
  users limit returned the expected quota response without creating a row.
- Inbox and handoff: PASS. Conversation history, reply, assignment, handoff, claim, resume and
  realtime propagation were exercised.
- Contacts, tags and leads: PASS at API/feature level and route smoke level; tenant isolation and
  permission matrices are green.
- Flows and triggers: PASS. Flow editing, validation, handoff, realtime and trigger behavior are
  covered by E2E/backend suites. The full node matrix is primarily backend-tested.
- FAQ, Knowledge and analytics: PASS at runtime/API level. Knowledge processing, search, cleanup,
  worker execution and tenant isolation passed. There is no dedicated browser CRUD journey for FAQ
  or the full Knowledge UI.
- Notifications: PASS at backend/realtime level; no new queue failures were produced by QA.
- Billing and Free plan: PASS with synthetic/local providers. No real Stripe call was made.
- WhatsApp: PASS for local UI/configuration boundaries and fake/provider-safe tests. No real Meta call
  was made.
- Accessibility and responsive smoke: PASS for route loading, no horizontal overflow, labels,
  AppSelect coverage, keyboard-compatible controls and existing mobile landing coverage.

## Infrastructure local

- PostgreSQL: PASS and healthy.
- Redis: PASS and healthy.
- Worker: PASS and healthy; default, knowledge and analytics queues had zero pending, delayed and
  reserved jobs after QA.
- Scheduler: PASS and healthy; readiness reported scheduler `ok`.
- Reverb: PASS and healthy; realtime E2E passed.
- MinIO: PASS. Readiness passed and a tenant-style local put/get/delete smoke completed.
- Mailpit: PASS. Password-reset and invitation messages were received.
- `/health`: PASS (`200`).
- `/ready`: PASS (`200`) with database, Redis, queue and scheduler checks healthy.

## Defects and findings

| ID | Severity | Finding | Status |
|---|---|---|---|
| QA37-001 | P2 | The local development database contains 21 historical failed jobs, all dated 2026-09-05. They are mostly broadcast events plus two knowledge embedding jobs. No new failed jobs were created during this QA. | Known limitation; investigate/clean before production operations. |
| QA37-002 | P3 | Browser CRUD journeys are not present for every module, notably Contacts, FAQ, Leads, Analytics, Notifications and the full Knowledge UI. Backend, API, permission, isolation and route smoke coverage exists. | Known coverage gap. |
| QA37-003 | P3 | Production build reports existing chunks above 500 kB. Vitest reports existing Vue lifecycle warnings and a jsdom navigation notice. | Non-blocking warning; no functional failure observed. |

The initial Tenant A invitation attempt was not classified as a defect: the tenant had three users
against its Free limit of three, and the API correctly returned a quota error. A valid Tenant B
invitation subsequently completed and delivered through Mailpit.

## Automated evidence

- Pest: 2636 passed, 15 skipped.
- Vitest: 597 passed.
- Playwright: 51 passed with one worker and no retries after fresh `e2e:setup`.
- PHPStan: PASS.
- Pint: PASS.
- Typecheck: PASS.
- Build: PASS, with the existing chunk-size warning noted above.
- Composer validate: PASS with pre-existing exact-version warnings.
- Composer audit: PASS; no relevant vulnerabilities.
- `git diff --check`: PASS.

## Commercial readiness

### Free private beta

**CONDITIONAL GO.** Local functional, authorization, isolation, onboarding, mail, queue, storage
and realtime checks pass. Before accepting real customers, production TLS, production mail,
backup/restore evidence, operational support ownership and production worker/scheduler/DB/Redis
readiness must be completed. Meta activation is required only for a beta that actually uses
WhatsApp.

### Paid beta

**NO-GO.** Synthetic billing behavior passes, but real Stripe activation, approved billing catalog,
refund/tax policy, webhook/reconciliation controls and billing ownership are not activated or
validated in this phase.

## Provider and production gates

- Real Stripe calls: 0.
- Real Meta calls: 0.
- Real OpenAI calls: 0.
- Production SMTP calls: 0.
- Production deploy: NO.
- Production migrations: NO.
- DNS/TLS/provider credentials: unchanged.

## Decision

FASE 37 local functional QA is complete. The source is suitable for a controlled Free private beta
only after the production-only operational gates are closed. Paid beta remains blocked.
