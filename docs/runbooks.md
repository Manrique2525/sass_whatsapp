# Production Runbooks

**FASE 31 U6 — Operations, Observability & Production Readiness**

Operational procedures for the Meta / WhatsApp Cloud API integration. These are
**operator maintenance** runbooks: they assume real production access and must only
be run by an authorized owner/admin. Nothing here is executed automatically.

> **Guardrails (AGENTS.md)**: all commands are tenant-scoped, PII-safe and
> non-destructive unless the step says otherwise. Never rotate secrets in Git;
> `.env` is the single source of truth. Real Meta/webhook credentials are never
> touched in tests or local dev.

---

## 1. Operator: Webhook replay (failed / stuck `received`)

**When**: Meta delivered an event that ended `failed` (e.g. phone was temporarily
unknown) or a `received` event is stuck after a worker crash. The sweepers
reprocess `received` automatically; this endpoint is the explicit owner/admin
fallback for both `failed` (terminal, needs an explicit decision) and stale
`received`.

**Endpoints** (owner/admin only; non-member → 404; agent → 403):

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/tenants/{tenant}/whatsapp/webhook-events/queue` | Count of replayable events (`pending`/`clean`) |
| `POST` | `/api/v1/tenants/{tenant}/whatsapp/webhook-events/replay` | Re-enqueue eligible `failed`/`received` (limit `1..500`, default `100`) |

**Audit**: every re-enqueued event writes `whatsapp.webhook.replayed`
(`webhook_event_id`, `provider_event_id`, `previous_status`).

**Eligibility guard** (enforced in `WhatsAppWebhookService::replayEvent`):
- Eligible: `failed` (re-set to `received` atomically) and `received`.
- **Never** eligible: `processed`, `enqueued` (prevents double work).
- A `failed` event whose `phone_number_id` no longer exists re-fails with
  `unknown_phone_number_id` and does **not** re-enqueue (no retry storm).

**Runbook**:
```bash
# 1. Inspect the queue for a tenant
TOKEN=<operator_api_token>
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://<app>/api/v1/tenants/<tenant>/whatsapp/webhook-events/queue"

# 2. Replay up to 100 failed events
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  "https://<app>/api/v1/tenants/<tenant>/whatsapp/webhook-events/replay"

# Expect: {requested, replayed, failed}
```

**Do NOT**: replay-all globally, replay `processed` events, or bypass the endpoint
to force-insert duplicate webhook events.

---

## 2. Operator: Phone health check

**When**: an operator needs current `quality_rating` / `verified_name` and the
provider-reported per-number status without disconnecting anything.

**Endpoint** (owner/admin; non-member → 404; agent → 403):
- `POST /api/v1/tenants/{tenant}/whatsapp/phone-health`

**Behavior**:
- Fails `409 WHATSAPP_NOT_CONNECTED` if the tenant has no connected account.
- Iterates `connected` numbers, reads info from the Graph API (fail-safe: a
  number that cannot be read is reported `degraded`, never throws).
- Persists **only informative columns already on the row**
  (`quality_rating`, `verified_name`). It **never** writes the `status` column,
  which governs send eligibility.
- Records `whatsapp.phone.health.rating.{key}` counters and audits
  `whatsapp.phone.health.check`.

```bash
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  "https://<app>/api/v1/tenants/<tenant>/whatsapp/phone-health"
```

**Do NOT**: interpret a degraded number as permission to disconnect it, or mutate
`status` directly.

---

## 3. Failed job visibility (PII-safe)

The queue stores `failed_jobs`; the payload may contain tenant context, phone, or
message content, so it is never printed.

```bash
# Aggregate summary by queue (no payload, no PII)
php artisan queue:failed-summary
php artisan queue:failed-summary --json

# Framework-level inspect/retry (personal review, PII-safe handling):
php artisan queue:failed --queue=default
php artisan queue:retry <id>          # retry a single exhausted job
```

**Do NOT**: dump `failed_jobs.payload` to logs or dashboards. Retain 30 days and
prune with `php artisan queue:prune-failed --days=30 --dry-run`.

---

## 4. Token / secret rotation

### 4.a WhatsApp `access_token` (system user / business token)

1. Create the new token in Meta Business Suite (with the minimum scopes the
   integration uses).
2. Verify it works against the target phone **before** swapping:
   ```bash
   curl -s \
     "https://graph.facebook.com/v26.0/<phone_number_id>?fields=verified_name,quality_rating,status" \
     -H "Authorization: Bearer $NEW_TOKEN"
   ```
3. Update `WHATSAPP_*` / the tenant's stored token via the normal connect flow
   (never by editing the DB).
4. Confirm `GET /api/v1/tenants/{tenant}/whatsapp` shows the new status and the
   webhook is still subscribed.
5. **No automatic dual-secret overlap is implemented in this release.** Introduce
   the new token to a maintenance window: swap the secret, then validate the
   webhook (see §6) and a smoke send (see §7) immediately.

> If the old token must stay valid for a short transition, this requires manual
> sequencing at Meta; the app does not hold two active API tokens for the tenant.

### 4.b `WHATSAPP_APP_SECRET` (webhook signature)

1. Generate a new app secret in Meta Apps list.
2. Set `WHATSAPP_APP_SECRET` in `.env` for **both** web and worker.
3. Restart the process group.
4. Send a test webhook (Meta Dashboard > Test) and confirm it ingests (an event
   appears in `webhook_events`; non-`failed`).

### 4.c `WHATSAPP_VERIFY_TOKEN` (webhook subscription verify)

1. Roll the value in Meta and in `WHATSAPP_VERIFY_TOKEN`.
2. Re-subscribe the webhook URL (see §5). The GET challenge will only succeed
   while both sides share the same token — rotate Meta **and** the env together
   in the same maintenance window.

**Do NOT**: commit tokens/secrets to Git, log them, or store them in the DB in
clear text. The `access_token` is stored encrypted (`encrypted` cast).

---

## 5. Meta webhook verification (subscribe / verify)

The Graph API version is pinned in `config/whatsapp.php` (`graph_version`).
Confirm the current value before scripting Graph calls.

```bash
# Read current callback + verify token (no token echoed)
php artisan tinker --execute="
  echo config('whatsapp.webhook_path');
  echo config('whatsapp.verify_token') !== '' ? 'verify_token: set' : 'verify_token: EMPTY';
"
```

Re-subscribe the callback for the phone/WABA owned by the tenant (operator token
with `whatsapp_business_management`):
```bash
curl -s -X POST \
  "https://graph.facebook.com/v26.0/<waba_id>/subscribed_apps" \
  -H "Authorization: Bearer $TOKEN"
```

Verify the GET challenge manually (must return the `hub.challenge` for a valid
token):
```bash
curl -s "https://<app>/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=$VERIFY_TOKEN&hub.challenge=1234567890"
```

---

## 6. Production smoke test — webhook

1. Send a real inbound message to the connected phone (or use Meta Dashboard >
   Test > Send a message to your number).
2. Wait a few seconds, then check the event was ingested and processed (not
   `failed`):
   ```bash
   php artisan tinker --execute="
     App\Domain\WhatsApp\Models\WebhookEvent::orderByDesc('created_at')->limit(1)->get()
   "
   ```
3. Confirm the counter moved: `whatsapp.webhook.received` / `processed` in Redis
   (`redis-cli get observability:metrics:whatsapp.webhook.received`).
4. Check `failed_jobs` stayed empty for `Process(Incoming)WhatsApp*`.

---

## 7. Production smoke test — outbound send

1. Send a text from the operator inbox (or `send` a template).
2. Confirm the message reaches `sent` (status) and the provider returned a
   `messages[].id`.
3. Confirm `whatsapp.outbound.delivery.sent` incremented and no
   `whatsapp.outbound.delivery.failed.*` for the attempt.
4. For a template, confirm it was `approved` before sending (a non-approved
   template returns `409` and never calls Meta).

---

## 8. Observability signals at a glance

| Signal | Where to look |
|---|---|
| Provider request results | `observability:metrics:whatsapp.provider.{op}.*` |
| Webhook pipeline | `observability:metrics:whatsapp.webhook.*` |
| Outbound delivery | `observability:metrics:whatsapp.outbound.delivery.*` |
| Phone health | `observability:metrics:whatsapp.phone.health.rating.*` |
| Exhausted jobs | `failed_jobs` + `queue:failed-summary` |
| Audit trail | `audit_logs` (`whatsapp.webhook.replayed`, `whatsapp.phone.health.check`, `message.delivery_replayed`) |

All counters live under Redis keys `observability:metrics:*` and can be read
directly with `redis-cli` / `GET`. Disable recording globally with
`OBSERVABILITY_METRICS_ENABLED=false`.