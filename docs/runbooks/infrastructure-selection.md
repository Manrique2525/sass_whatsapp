# FASE 40: Infrastructure Selection And Owner Decisions

## Status

This phase is open. The initial Free Private Beta defaults below are approved by the
owner. Provider, region, domain, access and operational ownership details remain
pending. Provider-specific features, regions and pricing must be verified before
purchase.

## Approved Free Beta Defaults

- Free Private Beta: **APPROVED**, controlled cohort of 5-10 tenants.
- Paid Beta: **DEFERRED / NO-GO**; Stripe remains disabled.
- AI/OpenAI: **DISABLED** initially; implementation remains available for later gated activation.
- Knowledge: **ENABLED**, with a maximum upload size of 10 MB.
- `worker-knowledge`: one replica initially with a recommended 512 MB PHP memory limit.
- WhatsApp: **SUPPORTED / CONDITIONAL**; live Meta connectivity is not required for beta start.
- Reverb/realtime: **ENABLED**, one private replica with WSS through ingress only.
- Compute: managed container platform, 2 vCPU/4 GB minimum and approximately 4 vCPU/8 GB preferred across services.
- Process topology: one replica for `web`, `app`, `worker-default`, `worker-knowledge`, `worker-analytics` and `reverb`; exactly one `scheduler` replica.
- PostgreSQL: managed PostgreSQL 16 with pgvector, encryption, TLS and automated backups; PITR where supported.
- Redis: managed, authenticated, private, TLS where supported, single node initially, existing cache/queue separation preserved.
- Storage: private S3-compatible storage using `tenant/{tenant_id}/`; object versioning enabled where practical.
- SMTP: transactional provider with TLS and verified sender domain; Mailpit remains local only.
- Recovery: RPO 15 minutes and RTO 30 minutes as operational targets, not SLAs.
- Backups: provider PITR window where practical, daily logical database backups and object retention target of 30 days, quarterly restore drills.
- Observability: centralized platform logs required; Sentry recommended and conditional on account/project setup.
- Security: Platform MFA, HTTPS, secure cookies, explicit trusted proxies, explicit Reverb origins, no auto-migrations and no startup key generation.

## Owner Decision Record

| Decision | Current value | Recommendation (not approved) |
|---|---|---|
| Hosting category | Managed container platform | Managed container platform |
| Provider | TBD | Select after capability and region verification |
| Region | TBD | Closest practical region to primary customers with required managed services |
| Application domain | TBD | `app.<production-domain>` |
| Marketing domain | TBD | Same domain or separate `www` domain |
| DNS owner | TBD | Owner with controlled DNS and TLS access |
| Initial tenants | 5-10 approved | 5-10 |
| Messages/day | Up to approximately 10,000 planning assumption | Measure and size from beta traffic |
| Concurrent users | Low/moderate beta usage approved | Measure and size from beta traffic |
| WhatsApp in Free Beta | Supported, activation conditional | ON only after Meta prerequisites are ready |
| AI in Free Beta | Disabled approved | OFF initially |
| Knowledge in Free Beta | Enabled approved | ON, with current 10 MB limit |
| Reverb in Free Beta | Enabled approved | ON if realtime UI is included |
| Knowledge upload max | 10 MB approved | KEEP 10 MB initially |
| RPO | 15 minutes target approved | Approve only if PITR/backup evidence supports it |
| RTO | 30 minutes target approved | Conditional on an exercised operational process |
| Database retention | 30 days initial target approved | 14-30 days, subject to cost and policy |
| Object-storage retention | 30 days initial target; versioning approved | Versioning enabled, retention policy approved |
| Support email | TBD | Required before beta |
| Technical owner | TBD | Required before beta |
| Incident owner | TBD | Required before beta |
| Billing owner | TBD | Required before Paid Beta |

## Provider Comparison

Scores are architectural fit estimates, not current vendor claims. Verify current
regions, PostgreSQL 16/pgvector support, Redis availability, WebSocket behavior,
backup windows and pricing before purchase.

| Provider/category | Ease | Docker | Workers | Reverb | PostgreSQL/pgvector | Redis | Storage | Backups | Observability | Ops simplicity | Cost | Scale | Total/60 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Managed container PaaS (Render/Railway class) | 5 | 5 | 5 | 4 | 3 | 3 | 4 | 3 | 4 | 5 | 3 | 4 | 48 |
| DigitalOcean App Platform + managed services | 4 | 4 | 4 | 4 | 3 | 4 | 5 | 4 | 4 | 4 | 4 | 4 | 46 |
| Azure Container Apps + managed services | 3 | 5 | 5 | 4 | 4 | 4 | 5 | 5 | 4 | 3 | 3 | 5 | 50 |
| AWS ECS/Fargate + managed services | 2 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 2 | 3 | 5 | 52 |

The scores intentionally reflect operational burden: AWS and Azure have the strongest
long-term primitives but require more platform expertise. PaaS options are simpler,
but managed PostgreSQL/pgvector, Redis topology, WebSocket ingress and backup controls
must be verified for the selected region and service tier.

## Recommendation

- **Primary recommendation**: managed container platform with managed PostgreSQL,
  Redis, private object storage, TLS ingress and centralized logs. Provider remains TBD.
- **Secondary option**: managed container service from a major cloud platform when
  regional availability, pgvector and recovery evidence outweigh operational simplicity.
- **Lowest-ops option**: a managed PaaS with separate services for web, app, workers,
  scheduler and Reverb, subject to WebSocket and database capability verification.
- **Lowest-cost tendency**: the same managed container category at beta scale, using
  one replica per process and vertical scaling first. A VPS/Docker deployment may be
  cheaper but is not recommended because the owner would operate backups, patching,
  TLS, monitoring and recovery.

## Region Gate

No region is selected. The owner must compare customer latency, data residency, cost,
and whether the same region offers PostgreSQL 16 with pgvector, private Redis, object
storage, backups/PITR and WebSocket-capable ingress. Do not select a region from a
generic compute availability list without checking every dependency.

## Concrete Free Beta Topology

| Service | Replicas | CPU/RAM starting point | Exposure | Port/health | Scaling |
|---|---:|---|---|---|---|
| `web` Nginx | 1 | shared `0.25-0.5 vCPU`, `256-512 MB` | ingress only | container `80`; `/health` | with app |
| `app` PHP-FPM | 1 | `0.5-1 vCPU`, `512 MB-1 GB` | private | FPM `9000`; container healthcheck | request/error load |
| `worker-default` | 1 | `0.5 vCPU`, `512 MB` | private | process healthcheck | default queue lag |
| `worker-knowledge` | 1 | `1 vCPU`, `1 GB` | private | process healthcheck | knowledge backlog/duration |
| `worker-analytics` | 1 | `0.5 vCPU`, `512 MB` | private | process healthcheck | analytics backlog |
| `scheduler` | 1 | `0.25 vCPU`, `256 MB` | private | process/heartbeat | never scale initially |
| `reverb` | 1 | `0.5 vCPU`, `512 MB` | ingress WebSocket path only | internal `8080`; healthcheck | active connections |
| PostgreSQL 16 + pgvector | managed | start at provider minimum, encrypted | private | provider/DB readiness | vertical first |
| Redis | managed | start at provider minimum, authenticated | private | provider readiness | vertical/HA later |
| S3-compatible storage | managed | private bucket, versioning recommended | private/signed access | provider health | capacity/retention |
| SMTP | managed provider | provider tier | outbound only | TLS delivery | provider policy |
| Monitoring/logs | managed/platform | provider tier | operator access | `/health`, `/ready`, logs | alert-driven |

Assumptions are moderate messaging, limited Knowledge processing, one region, one
worker per queue and vertical scaling before horizontal scaling. They are not capacity
claims or an SLA.

## Feature Recommendation

- WhatsApp: enable only after Meta Business, WABA, phone, app, webhook URL, verify
  token, app secret and production HTTPS domain are ready.
- AI: disable initially unless OpenAI billing, model, data-processing and cost policy
  are explicitly approved. The product must remain functional without AI.
- Knowledge: enable with the current 10 MB application limit and the dedicated
  `knowledge` worker.
- Reverb: enable when realtime inbox behavior is part of the beta; route WSS through
  ingress and keep Reverb private.
- Stripe: OFF. Paid Beta remains NO-GO until commercial policy and provider readiness
  are closed.

## Production Environment Checklist

### Required

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<secret-manager-value>
APP_URL=https://app.<production-domain>
APP_IMAGE=<immutable-git-sha-or-release-tag>
WEB_IMAGE=<immutable-git-sha-or-release-tag>
LOG_CHANNEL=json
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_HOST=<private-postgres-host>
DB_PORT=5432
DB_DATABASE=<database-name>
DB_USERNAME=<secret-manager-value>
DB_PASSWORD=<secret-manager-value>
REDIS_CLIENT=phpredis
REDIS_HOST=<private-redis-host>
REDIS_PORT=6379
REDIS_PASSWORD=<secret-manager-value>
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
TRUSTED_PROXIES=<explicit-ingress-cidr>
REVERB_APP_ID=<secret-manager-value>
REVERB_APP_KEY=<secret-manager-value>
REVERB_APP_SECRET=<secret-manager-value>
REVERB_HOST=app.<production-domain>
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=https://app.<production-domain>
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<secret-manager-value>
AWS_SECRET_ACCESS_KEY=<secret-manager-value>
AWS_DEFAULT_REGION=<selected-region>
AWS_BUCKET=<private-bucket>
AWS_ENDPOINT=<s3-endpoint-if-required>
AWS_USE_PATH_STYLE_ENDPOINT=false
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<secret-manager-value>
MAIL_PASSWORD=<secret-manager-value>
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=<verified-sender>
MAIL_FROM_NAME=WhatsApp SaaS
```

### Conditional

```dotenv
WHATSAPP_APP_SECRET=<only-when-meta-is-approved>
WHATSAPP_VERIFY_TOKEN=<only-when-meta-is-approved>
OPENAI_API_KEY=<only-when-ai-is-approved>
SENTRY_LARAVEL_DSN=<optional-approved-dsn>
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=<immutable-release>
VITE_SENTRY_DSN=<optional-approved-dsn>
```

### Disabled for Free-only Beta

```dotenv
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
```

Never generate `APP_KEY` at startup, run migrations at startup, use `latest` as the
only production image tag, expose internal services, or put credentials in chat/Git.

## Migration Gate

The known release list is:

1. `2026_08_25_100001_create_usage_reservations_table`
2. `2026_08_26_000001_add_tenant_id_id_unique_to_messages_and_whatsapp_accounts`
3. `2026_08_26_000002_create_message_media_table`
4. `2026_08_26_000003_create_whatsapp_templates_table`
5. `2026_09_14_000001_create_platform_mfa_credentials_table`

Before any future production execution, the operator must verify environment and DB
identity, backup/checksum, locks, maintenance window and actual `php artisan
migrate:status`. The known list is not proof that production is missing only these
migrations. No migration is executed in FASE 40.

## Access Gate

Access must be configured outside chat through the selected platform's normal access
controls. Required categories are cloud/platform access, deployment access, registry
access if needed, private DB access, Redis access, object-storage access and DNS access.
Do not send raw passwords or tokens in chat.

## FASE 40 Exit Gate

Provisioning cannot begin until the owner explicitly approves category/provider,
region, domain, beta cohort, feature flags, upload policy, RPO/RTO, retention and
support/technical/incident ownership. This document is planning only.
