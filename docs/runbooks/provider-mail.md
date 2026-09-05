# Runbook: Production Mail

Mail is required for production account recovery, invitations, and notifications. Mailpit is local-only.

Sender domain, support email, provider ownership, and deliverability approval are OPS/BUSINESS pending.

## Activation

1. Select an approved remote SMTP or transactional provider and verify sender/domain authentication.
2. Inject `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME=tls` (or `smtps`),
   `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` through the secret manager.
3. Keep `MAIL_MAILER=smtp`; local, loopback, and Mailpit hosts are rejected by the production validator.
4. Publish and verify SPF, DKIM, and DMARC for the sender domain before enabling customer traffic.

## Verification and rollback

- Run the production configuration validator before traffic and send one controlled verification email.
- Confirm password reset and invitation delivery without logging message bodies or credentials.
- Verify bounce, complaint, suppression, and delivery-failure handling with the provider's operational console.
- Rotate SMTP credentials or switch providers, then verify delivery and bounce handling before reopening traffic.
- If delivery fails, use the provider's out-of-band console; do not fall back to `log`, `array`, or a local host.

CI uses fake mail and does not send email externally.
