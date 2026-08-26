# Observability Architecture

**FASE 28 — Structured Logging, Error Tracking, Health Probes, Alerting & Retention**

## Overview

The platform provides multi-layered observability without leaking PII or blocking product functionality.

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Vue 3 App  │     │ Laravel API │     │   Workers   │
│  (Browser)  │     │  (PHP-FPM)  │     │  (Queue)    │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                   │
       │ Sentry JS         │ Sentry PHP        │ Sentry PHP
       │                   │                   │
       │ Structured JSON   │ Structured JSON   │ Structured JSON
       │ logs              │ logs              │ logs
       │                   │                   │
       └───────┬───────────┴───────────┬───────┘
               │                       │
               ▼                       ▼
        ┌─────────────┐       ┌─────────────┐
        │   Sentry    │       │  log files  │
        │   (errors)  │       │  (stdout)   │
        └─────────────┘       └─────────────┘
```

## Layers

### 1. Structured JSON Logging (U1)

Every log entry is JSON with request context:

| Field | Source | Example |
|---|---|---|
| `message` | Log entry | `Payment processed` |
| `request_id` | `Log::shareContext()` / `CorrelationIdMiddleware` | `req_abc123` |
| `tenant_id` | `Log::shareContext()` | `t_123` |
| `exception.class` | Throwable | `Symfony\\...\\NotFoundHttpException` |
| `exception.message` | Throwable | `Model not found` |

**Privacy**: No PII (email, phone, message content) in structured logs.

**Propagation**: `request_id` flows through HTTP → jobs → provider calls via `SentryScopeMiddleware` and `QueuePropagateContext`.

### 2. Backend Error Tracking (U2)

**Sentry PHP SDK** with privacy-safe configuration:

- `before_send` → `SentryEventScrubber::scrub`
- `before_send_transaction` → `SentryEventScrubber::scrub`
- Request bodies: excluded on auth/webhook paths
- PII: phones → `[PHONE]`, emails → `[EMAIL]`, API keys → `[REDACTED]`
- Queue failures: captured via `Queue::failing()` in `SentryQueueFailureServiceProvider`

**Fail-safe**: Sentry outage never blocks product. Exception handling is `try/catch` wrapped.

### 3. Frontend Error Tracking (U3)

**@sentry/vue** initialized in `resources/js/sentry.ts`:

- DSN-gated: only initializes if `VITE_SENTRY_DSN` is set
- `scrubEvent` callback: strips PII, auth headers, cookies, CSRF tokens
- Vue component tree visible in Sentry issues
- CSP: `connect-src` includes Sentry domain dynamically

### 4. Health & Readiness Probes (U4)

| Endpoint | Purpose | Checks |
|---|---|---|
| `GET /health` | Liveness (is the process alive?) | App only |
| `GET /ready` | Readiness (can it serve traffic?) | Database, Redis, Queue |

**Scheduler heartbeat**: `SchedulerHeartbeatCommand` writes timestamp to Redis cache every minute. Readiness probe verifies freshness (configurable max age, default 120s). Stale heartbeat is **informational** — does not block readiness.

**External providers** (Meta, OpenAI, Stripe) are deliberately excluded from readiness. Provider outage = degraded features, not full outage.

### 5. Queue Monitoring (U4)

- Worker consumes `--queue=default,analytics,knowledge` (priority order)
- `Queue::failing()` captures exhausted jobs to Sentry with `tenant_id` and `request_id` context
- `AggregateDailyAnalyticsJob::failed()` logs structured warning
- Health probe verifies queue backend connectivity (Redis ping)

### 6. Failed Login Audit (U5)

Both web and API login paths record `user.login_failed` events in `audit_logs`:

| Field | Value |
|---|---|
| `action` | `user.login_failed` |
| `data.reason` | `invalid_credentials` |
| `ip_address` | Client IP (from AuditLogger) |
| `user_agent` | Client UA (from AuditLogger) |
| `request_id` | Auto-injected by AuditLogger |

**Privacy**: No email, no password, no credential data logged. No user enumeration — same response for existing/non-existing users.

### 7. Data Retention (U5)

| Dataset | Retention | Command | Schedule |
|---|---|---|---|
| `audit_logs` | 90 days | `php artisan audit:prune` | Daily 03:00 |
| `failed_jobs` | 30 days | `php artisan queue:prune-failed` | Daily 03:00 |

Both commands support `--days=N` and `--dry-run`. Batched deletes (500 rows/iteration) avoid table locks.

Configurable via env: `AUDIT_LOG_RETENTION_DAYS`, `FAILED_JOBS_RETENTION_DAYS`.

**Not pruned** (documented):
- `webhook_events` — needed for idempotency/replay safety
- `flow_execution_logs` — needed for analytics/history
- Log files — rotation handled by container stdout/stderr

### 8. Alert Matrix (U5)

See [Incident Response](incident-response.md) for severity model and alert configuration.

| Alert | Severity | Source |
|---|---|---|
| 5xx spike | P1 | Sentry issue frequency |
| DB unavailable | P0 | Readiness probe failure |
| Redis unavailable | P0 | Readiness probe failure |
| Queue failure burst | P1 | Sentry + failed_jobs |
| Scheduler heartbeat stale | P2 | Readiness probe warning |
| Stripe sync failure | P2 | AuditLog + Sentry |
| WhatsApp webhook failure | P2 | Sentry + AuditLog |
| Provider timeout/error spike | P2 | Sentry fingerprint |
| Security auth failure spike | P2 | AuditLog + Sentry |

## Privacy Commitments

- No PII in telemetry (phone, email, message content, AI prompts, auth headers)
- No webhook payload bodies in logs/Sentry
- Sentry scrubber runs on every event before transmission
- AuditLogger captures only safe metadata (request_id, reason, IP/UA)
- `send_default_pii` = false

## Configuration Reference

### Backend (.env)

| Variable | Default | Description |
|---|---|---|
| `SENTRY_LARAVEL_DSN` | (empty) | Backend Sentry DSN |
| `SENTRY_ENVIRONMENT` | `local` | Sentry environment |
| `SENTRY_RELEASE` | (empty) | Deploy SHA for release tracking |
| `SENTRY_SAMPLE_RATE` | `1.0` | Error sample rate |
| `SENTRY_TRACES_SAMPLE_RATE` | (empty) | Performance traces (OFF) |
| `SCHEDULER_HEARTBEAT_MAX_AGE` | `120` | Seconds before stale heartbeat |
| `AUDIT_LOG_RETENTION_DAYS` | `90` | Audit log purge threshold |
| `FAILED_JOBS_RETENTION_DAYS` | `30` | Failed jobs purge threshold |

### Frontend (.env)

| Variable | Default | Description |
|---|---|---|
| `VITE_SENTRY_DSN` | (empty) | Frontend Sentry DSN |
| `VITE_SENTRY_ENVIRONMENT` | `${APP_ENV}` | Sentry environment |
| `VITE_SENTRY_RELEASE` | (empty) | Deploy SHA |

## Deferred (Not in Scope)

- **Metrics backend** (Prometheus, etc.)
- **Distributed tracing** (OpenTelemetry)
- **Dashboards** (Grafana, etc.)
- **Source map upload** (requires secure CI pipeline)
- **Sentry Replay** (privacy/consent considerations)
