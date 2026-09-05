# Runbook: Meta WhatsApp Cloud API

## Prerequisites

- Business verification, WABA ownership, phone-number ownership, and Meta app ownership are OPS/BUSINESS pending.
- The public callback URL must be HTTPS and controlled by the deployment owner.

## Activation

1. Create or select the Meta app and WhatsApp Business Account outside this repository.
2. Configure `WHATSAPP_GRAPH_URL` to the official HTTPS Graph host and pin `WHATSAPP_GRAPH_VERSION`.
3. Inject `WHATSAPP_APP_SECRET` and a high-entropy `WHATSAPP_VERIFY_TOKEN` through the secret manager.
4. Register `POST /api/webhooks/whatsapp` and `GET /api/webhooks/whatsapp` in Meta using the public HTTPS URL.
5. Connect each tenant WABA through the application. Access tokens and phone IDs are encrypted tenant data.

## Verification and rollback

- Verify GET challenge validation and POST `X-Hub-Signature-256` with a signed non-sensitive fixture.
- Confirm the tenant phone health check and an approved template in a controlled tenant.
- Monitor webhook signature failures, dispatch failures/replays, Meta rate limits, delivery failures, and phone health.
- Rotate App Secret, verify token, and tenant access tokens independently; disable the tenant connection on failure.
- Never place access tokens, App Secrets, webhook payloads, or message content in logs or this repository.

No production Meta request is part of CI or this runbook's local validation.
