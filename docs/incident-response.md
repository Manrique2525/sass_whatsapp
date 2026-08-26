# Incident Response Guide

**FASE 28 U5 — Severity Model, Detection, Triage, Runbooks, Postmortem**

## Severity Model

| Severity | Definition | Response Time | Example |
|---|---|---|---|
| **P0** | Complete outage or critical data integrity risk | Immediate (15 min) | Database down, Redis down, data loss |
| **P1** | Major degradation, widespread failures | < 1 hour | 5xx spike > 10%, queue failure burst |
| **P2** | Partial degradation, specific feature affected | < 4 hours | Stripe sync failure, scheduler stale, provider timeout |
| **P3** | Minor / non-urgent anomaly | Next business day | Single failed job, informational alert |

## Detection Sources

| Source | What it catches |
|---|---|
| `GET /ready` (503) | DB, Redis, queue connectivity failure |
| `GET /health` (503) | Process cannot function |
| Sentry (backend) | Unhandled exceptions, queue job exhaustion |
| Sentry (frontend) | JS errors, unhandled rejections |
| `audit_logs` | Failed logins, security events |
| `failed_jobs` | Job exhaustion, permanent failures |
| Scheduler heartbeat stale | Scheduler loop not running |

## Triage Workflow

1. **Identify severity** — Is it P0/P1 (active user impact) or P2/P3?
2. **Check Sentry** — New issue? Regression? Fingerprint?
3. **Check health/readiness** — Are dependencies available?
4. **Check `failed_jobs`** — Job class, tenant_id, exception
5. **Check `audit_logs`** — Security events, failed logins
6. **Contain** — See runbooks below
7. **Resolve** — Fix root cause or rollback
8. **Postmortem** — If P0 or P1

## Containment Principles

- **Rollback first, investigate second** — restore service before root cause analysis
- **Isolate the blast radius** — disable the specific feature, not the whole app
- **Never flush production data** without explicit approval
- **Document everything** — timestamps, actions taken, who did what

## Runbooks

### Runbook: Database Unavailable (P0)

**Symptoms**: `GET /ready` returns 503 with `database: down`

**Verification**:
```bash
curl -s http://localhost:8000/ready | jq .checks.database
# Expected: {"status": "down", ...}
```

**Containment**:
1. Verify PostgreSQL process: `docker compose exec postgres pg_isready`
2. Check disk space: `docker compose exec postgres df -h /var/lib/postgresql/data`
3. Check connection limits: `docker compose exec postgres psql -U saas -d whatsapp_saas -c "SELECT count(*) FROM pg_stat_activity;"`

**Restore**:
- If crashed: `docker compose restart postgres`
- If disk full: clear WAL/archives, then restart
- If connection pool exhausted: restart app + worker containers

**Post-recovery**: Run `php artisan migrate --force` to verify connectivity.

---

### Runbook: Redis Unavailable (P0)

**Symptoms**: `GET /ready` returns 503 with `redis: down`

**Verification**:
```bash
curl -s http://localhost:8000/ready | jq .checks.redis
# Expected: {"status": "down", ...}
```

**Impact**: Queue stops processing, cache misses, scheduler heartbeat stale.

**Containment**:
1. Verify Redis process: `docker compose exec redis redis-cli ping`
2. Check memory: `docker compose exec redis redis-cli info memory`

**Restore**:
- If crashed: `docker compose restart redis`
- If memory full: `docker compose exec redis redis-cli FLUSHDB` (only if cache-only, no queue persistence needed)

**Post-recovery**: Verify queue resumes: `docker compose logs worker --tail=20`

---

### Runbook: Queue Failure Burst (P1)

**Symptoms**: Sentry alert for multiple job failures, `failed_jobs` growing rapidly

**Verification**:
```bash
php artisan queue:failed | head -20
# Check job class, exception, failed_at
```

**Containment**:
1. Identify job class and tenant_id from `failed_jobs`
2. If external provider down (Meta/OpenAI): pause queue worker, wait for provider recovery
3. If code bug: deploy fix, then retry failed jobs

**Do NOT**: `queue:flush` (loses all pending jobs) or retry-all blindly.

**Restore**:
- Provider outage: `php artisan queue:work --queue=default,analytics,knowledge` after provider recovers
- Code fix: deploy, then `php artisan queue:retry all`

---

### Runbook: Stripe Subscription Sync Failure (P2)

**Symptoms**: Sentry issue for Stripe-related jobs, AuditLog `billing.subscription.sync_failed`

**Verification**:
```bash
# Check Stripe webhook delivery status in Stripe Dashboard
# Check audit_logs for billing events
php artisan tinker --execute="App\Domain\Audit\Models\AuditLog::where('action','like','billing%')->latest()->limit(5)->get()"
```

**Containment**:
1. Verify Stripe webhook endpoint is receiving events (Stripe Dashboard > Webhooks)
2. Check `webhook_events` table for unprocessed events
3. If webhook secret rotated: update `STRIPE_WEBHOOK_SECRET`

**Do NOT**: Manually edit subscription state without approval.

---

### Runbook: WhatsApp Webhook Failure (P2)

**Symptoms**: Sentry issue for WhatsApp jobs, AuditLog `whatsapp.webhook.processing_failed`

**Verification**:
```bash
# Check webhook_events for recent failures
php artisan tinker --execute="App\Domain\Audit\Models\AuditLog::where('action','like','whatsapp%')->latest()->limit(5)->get()"
```

**Containment**:
1. Verify Meta webhook delivery in Meta Developer Dashboard
2. Check job retries: `php artisan queue:failed | grep -i whatsapp`
3. If signature verification failing: verify `WHATSAPP_APP_SECRET` is current

**Do NOT**: Disable signature verification.

---

### Runbook: Provider Outage (P2)

**Symptoms**: Elevated error rates for Meta/OpenAI/Stripe API calls in Sentry

**Impact**: Feature degradation, not full outage. Liveness/readiness unaffected.

**Containment**:
1. Check provider status page:
   - Meta: https://developers.facebook.com/status/
   - OpenAI: https://status.openai.com/
   - Stripe: https://status.stripe.com/
2. If transient: wait for provider recovery, monitor Sentry
3. If extended: document user-facing impact, communicate ETA

---

### Runbook: Security Event Spike (P2)

**Symptoms**: Multiple `user.login_failed` events in `audit_logs`, 429 rate limit spike

**Verification**:
```bash
php artisan tinker --execute="
  App\Domain\Audit\Models\AuditLog::where('action','user.login_failed')
    ->where('created_at','>', now()->subHour())
    ->count()
"
```

**Containment**:
1. Check if concentrated on single IP or distributed
2. If single IP: consider blocking at infrastructure level (reverse proxy/firewall)
3. If distributed: possible credential stuffing — alert team
4. Verify rate limiting is active on login routes

**Do NOT**: Implement auto-IP-blocking in application code (belongs infrastructure).

---

## Postmortem Template

```markdown
# Incident Postmortem — [TITLE]

**Date**: YYYY-MM-DD
**Severity**: P0 / P1 / P2
**Duration**: X hours Y minutes
**Author**: [Name]

## Summary
[1-2 sentence description of what happened]

## Impact
- Users affected: [number or "all"]
- Features affected: [list]
- Data impact: [none / describe]

## Timeline (UTC)
| Time | Event |
|---|---|
| HH:MM | First alert / detection |
| HH:MM | Triage started |
| HH:MM | Containment action |
| HH:MM | Resolution |
| HH:MM | Post-recovery verified |

## Root Cause
[What caused the incident]

## Detection
[How was it detected? Alert, customer report, manual check?]

## Resolution
[What fixed it]

## What Worked
- [list]

## What Failed
- [list]

## Action Items
| Action | Owner | Due | Priority |
|---|---|---|---|
| [action] | [name] | [date] | P0-P3 |

## Lessons Learned
[Key takeaways]
```

## Alert Configuration (Sentry)

### Recommended Alert Rules

| Rule | Condition | Severity | Destination |
|---|---|---|---|
| 5xx spike | > 10 unique errors in 5 min | P1 | Email + Slack |
| New issue | First occurrence of any error | P2 | Email |
| Regression | Issue resolved then re-occurred | P1 | Email + Slack |
| Queue exhaustion | Job `failed()` called | P2 | Email |
| DB down | Readiness probe 5xx | P0 | Email + Slack + PagerDuty |
| Redis down | Readiness probe 5xx | P0 | Email + Slack + PagerDuty |

### Alert Destinations

- **Email**: Always available (Sentry default)
- **Slack**: Configure via Sentry > Settings > Integrations > Slack
- **PagerDuty**: Configure via Sentry > Settings > Integrations > PagerDuty

**Do not configure external destinations without credentials/authorization.**

## Escalation

| Severity | First Responder | Escalation |
|---|---|---|
| P0 | On-call engineer | Team lead within 15 min |
| P1 | On-call engineer | Team lead within 1 hour |
| P2 | Any engineer | — |
| P3 | Any engineer | — |

## Data Retention Reference

| Dataset | Retention | Command |
|---|---|---|
| `audit_logs` | 90 days | `php artisan audit:prune` |
| `failed_jobs` | 30 days | `php artisan queue:prune-failed` |
| Log files | 14 days (daily channel) | Container rotation |
| Sentry events | 90 days (Sentry plan) | Sentry config |
