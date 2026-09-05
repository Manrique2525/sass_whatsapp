# Runbook: Stripe Billing

Stripe is optional for the Free-only beta. Do not create products, prices, currencies, or paid catalog
entries as part of U4.

Account ownership, paid-plan scope, prices, currency, refund policy, and tax policy are BUSINESS pending.

## Activation

1. Obtain approval for billing scope and a Stripe account/environment.
2. Inject `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` through the secret manager.
3. Configure only already-approved plan price IDs in the database; never accept price IDs from the frontend.
4. Register `POST /api/webhooks/stripe` and configure the endpoint signing secret.
5. Confirm the approved customer portal return URL and the reconciliation owner before enabling paid checkout.

## Verification and rollback

- Confirm missing credentials fail closed without an HTTP request.
- Validate a signed test webhook in the Stripe test environment and confirm event idempotency.
- Reconcile checkout, subscription, invoice, cancellation, and `past_due` events against local state.
- Confirm portal access is denied safely when Stripe is disabled and no entitlement is granted by a failed event.
- On rollback, disable paid checkout and the endpoint, revoke/rotate secrets, and leave the Free plan available.
- Never log Stripe secrets, signatures, payment methods, or raw provider payloads.

U4 performs contract tests only and does not call Stripe or create billing objects.
