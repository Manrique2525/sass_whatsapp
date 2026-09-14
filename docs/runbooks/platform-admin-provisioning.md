# Platform Admin Provisioning Runbook

## Scope

This runbook creates or promotes a global Platform Super Admin through the
interactive Artisan command. It does not create a tenant, assign tenant roles,
configure MFA or create production users automatically. Do not use the E2E admin
(`platform-admin@e2e.local`) in another environment.

## Command

```bash
php artisan platform-admin:create
```

The command is interactive by design. It does not accept passwords as arguments and
rejects non-interactive execution. In production it displays a warning and requires
the exact confirmation phrase `CREATE PLATFORM ADMIN`.

## New User Flow

1. Enter the name and email address.
2. Enter the password and confirmation through hidden prompts.
3. Confirm the provisioning action; production requires the typed confirmation phrase.
4. The command creates only the `users` row and assigns global `super_admin` through
   `TenantRoleManager`.
5. No tenant, workspace, subscription or tenant membership is created.
6. The normal email-verification notification is sent.
7. The account remains blocked from `/platform` until email verification and MFA setup.

Creation and role assignment are transactional. A role-assignment or audit failure
rolls back a newly created user. A later mail-delivery failure does not delete the
account; resolve mail and resend verification through the normal application flow.

## Existing User Promotion

The command displays only a safe summary: existence, verification status, global role
status and tenant-membership count. Promotion requires confirmation. It does not reset
the password or alter tenant memberships. An existing `super_admin` is idempotent and
causes no role or password change.

## Verification And MFA

The command never marks email verified and never creates TOTP credentials or recovery
codes. After receiving and following the signed verification email:

1. Log in through the configured application URL at `/login`.
2. Open `/platform`.
3. Follow the redirect to `/platform/security`.
4. Confirm the current password and enroll TOTP.
5. Store the one-time recovery codes in the approved secure location.
6. Use `/platform/security/challenge` when prompted and then access `/platform`.

MFA assertions are session-bound and expire after the current platform security policy
window. Password reset preserves the global role and MFA credential; the existing
password-reset web-session invalidation gap is a separate P2 hardening candidate.

## Audit And Secrets

The command records `platform.admin.created` or `platform.admin.promoted` with safe
metadata (`user_id`, email, environment and `source=artisan`). CLI execution has no
authenticated HTTP actor, so `actor_user_id` remains nullable; infrastructure logs are
needed for human operator attribution. Passwords, hashes, TOTP data, recovery codes and
tokens are never written to output or audit metadata.

Do not use direct SQL, `permission:assign-role`, production seeders, committed passwords,
tenant UI promotion or public signup to create a Platform Super Admin.

## Production Gate

Run only after the production infrastructure and SMTP provider exist. Verify the
application URL, database identity, roles/permissions seed state, audit table and mail
delivery configuration first. Perform the command from an authorized operator session;
do not send credentials through chat. Production account creation was not performed by
this implementation phase.
