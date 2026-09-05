# Runbook: Sentry

Sentry is optional and DSN-gated. It must not become a boot or request-path dependency.

Sentry organization/project ownership, retention approval, and alert ownership are OPS pending.

## Activation

1. Create the approved Sentry project and inject `SENTRY_LARAVEL_DSN` through the secret manager.
2. Set `SENTRY_ENVIRONMENT=production` and the deployed `SENTRY_RELEASE`.
3. Review `SENTRY_SAMPLE_RATE`, trace/profile sampling, retention, and access controls.
4. If browser telemetry is approved, set the corresponding `VITE_SENTRY_*` build variables without embedding secrets.

## Verification and rollback

- Trigger a controlled test exception and confirm the backend/frontend scrubbers remove credentials, PII,
  request bodies, cookies, and authorization headers.
- Configure alerts for error-rate regressions, queue failures, webhook failures, and readiness degradation;
  verify routing without including customer content.
- Remove the DSN or disable the integration to roll back; application traffic must continue normally.
- Never send real customer messages or credentials as test data.

CI validates scrubbers and does not send telemetry.
