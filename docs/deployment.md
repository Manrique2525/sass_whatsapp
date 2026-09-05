# Despliegue

## 1. Entornos

| Entorno | Propósito |
|---|---|
| `local` | Windows (dev) + Docker Compose opcional |
| `staging` | Validación pre-producción (CI deploy automático) |
| `production` | Proxied por nginx, TLS, workers, Reverb |

## 2. Docker Compose (FASE 1)

Servicios:

| Servicio | Imagen | Notas |
|---|---|---|
| `app` | php:8.3-fpm | Código Laravel, PHP-FPM |
| `nginx` | nginx:alpine | Serve + proxy a app/Reverb, TLS |
| `postgres` | postgres:16 + `pgvector/pgvector:pg16` | DB principal |
| `redis` | redis:7-alpine | Cache + Queue |
| `worker` | php:8.3-cli | `php artisan queue:work` + `queue:listen` + scheduler |
| `reverb` | php:8.3-cli | `php artisan reverb:start` |
| `minio` | minio/minio | S3-compatible local (storage) |
| `mailpit` | axllent/mailpit | Captura de emails en dev |

## 3. Entorno local (máquina actual)

**Situación detectada**: sin Docker, sin PostgreSQL/Redis locales, WSL sin distros.
Decisión tomada (ADR-002, confirmada por el usuario): **instalar Docker Desktop** y usar el
Compose completo (entornos idénticos a producción). El usuario instala Docker Desktop
(requiere permisos de administrador); el resto del entorno queda preparado en el repo.

### 3.1 Comandos de entorno (FASE 1)

**Setup (una sola vez):**

```bash
# 1. Instalar Docker Desktop (manual, requiere admin) y arrancarlo.

# 2. Levantar la infraestructura completa
docker compose up -d --build

# 3. Crear la base de datos y las extensiones (pgvector)
docker compose exec app php artisan migrate

# 4. Verificación de salud de todos los servicios
docker compose ps            # todos los servicios healthy
docker compose exec app php artisan health:check        # tabla de estados
docker compose exec app php artisan health:check --json # salida JSON

# 5. Frontend y cola
docker compose exec app npm run build    # assets (si cambian)
docker compose exec app php artisan queue:work --sleep=1  # worker manual (el compose ya incluye worker)
```

**Puertos expuestos** (mapeo host→contenedor; los puertos 5432/6379/1025/8025/9001
están ocupados por Herd en esta máquina, por eso se remapean):

| Servicio | URL |
|---|---|
| App (nginx) | http://localhost:8080 |
| Health check | http://localhost:8080/health |
| Reverb WS | ws://localhost:8081 |
| MinIO API (S3) | http://localhost:9000 (console: http://localhost:9002) |
| Mailpit UI | http://localhost:8026 (SMTP: 1026) |
| PostgreSQL | localhost:5433 |
| Redis | localhost:6380 |

El bucket `whatsapp-saas` se crea automáticamente al levantar el compose
(servicio one-shot `init-minio`).

**Comandos frecuentes:**

```bash
docker compose down                 # detener servicios
docker compose down -v              # detener y borrar volúmenes (DB/Storage)
docker compose up -d                # reanudar sin rebuild
docker compose build app worker     # rebuild de imágenes
docker compose logs -f app worker   # logs en vivo
docker compose exec app bash        # shell dentro del contenedor
docker compose exec app php artisan tinker
```

**Sin Docker (alternativa mínima, solo código):** `php artisan test`, `composer run lint`,
`composer run analyse` y `npm run build` corren directamente en la máquina (no requieren
Docker). `GET /health` local devolverá `degraded` hasta que Postgres/Redis estén arriba.

## 4. Variables de entorno (`.env.example`)

```
APP_ENV=local
APP_KEY=
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=whatsapp_saas
DB_USERNAME=saas
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

BROADCAST_CONNECTION=reverb
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...

WHATSAPP_GRAPH_URL=https://graph.facebook.com
WHATSAPP_GRAPH_VERSION=v26.0
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_MAX_ATTEMPTS=3

OPENAI_API_KEY=
AI_PROVIDER=openai
AI_MODEL=gpt-4o-mini
AI_TIMEOUT=15
AI_MAX_RETRIES=1
AI_MAX_TOKENS=500
AI_EMBEDDING_PROVIDER=openai
AI_EMBEDDING_MODEL=text-embedding-3-small
AI_EMBEDDING_DIMENSIONS=1536

AWS_ACCESS_KEY_ID=minio
AWS_SECRET_ACCESS_KEY=minio123
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=whatsapp-saas
AWS_ENDPOINT=http://minio:9000   # vacío en producción

SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=
SENTRY_SAMPLE_RATE=1
SENTRY_TRACES_SAMPLE_RATE=0
SENTRY_PROFILES_SAMPLE_RATE=0
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="WhatsApp SaaS"
```

### 4.1 Provider activation boundary

Provider credentials are injected through the secret manager and are never committed. The
application resolves provider adapters without making network calls during boot. Activation is
conditional: Meta requires `WHATSAPP_APP_SECRET` and `WHATSAPP_VERIFY_TOKEN` plus a tenant WABA
connection; OpenAI requires `OPENAI_API_KEY` and is still subject to the tenant AI entitlement;
Stripe requires both `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` and is not needed for the
Free-only beta; Sentry is DSN-gated and optional; production mail is mandatory and must use a
remote SMTP/transactional provider with `MAIL_SCHEME=tls` or `smtps`.

Business/operations inputs remain pending and are not invented here: Meta business verification/WABA/phone/app
ownership; Stripe account, paid-plan catalog, currency, refund/tax policy; mail sender domain and support email;
OpenAI account/billing ownership; and Sentry organization/project ownership. Domain, DNS, TLS, SPF, DKIM and DMARC
operations remain external prerequisites.

Missing credentials fail closed at the provider boundary. No provider readiness check performs a
real external request during deploy or CI. Follow the provider-specific runbooks before enabling
the corresponding tenant or route:

- `docs/runbooks/provider-meta.md`
- `docs/runbooks/provider-stripe.md`
- `docs/runbooks/provider-openai.md`
- `docs/runbooks/provider-sentry.md`
- `docs/runbooks/provider-mail.md`

## 5. CI/CD (GitHub Actions, FASE 34)

Workflow por PR y push a `main`:

```
jobs:
  lint:        composer install --no-dev → pint --test, phpstan analyze
  backend-tests:  postgres + redis services → migrate:fresh --seed → php artisan test --coverage
  frontend:    npm ci → npm run typecheck → npm run test → npm run build
  security:    composer audit, npm audit, gitleaks scan
  deploy:      (staging/prod) después de tests verdes → build image, push, deploy
```

- Secrets en GitHub → variables de entorno de los runners. **Nunca** en el repo.
- `gitleaks` falla el build si detecta secretos commiteados.

## 6. Producción

La referencia `docker-compose.production.yml` no incluye PostgreSQL, Redis, MinIO ni Mailpit:
son dependencias externas de producción. Requiere `.env.production` generado desde
`.env.production.example`, imágenes versionadas en `APP_IMAGE` y `WEB_IMAGE`, TLS terminado en
el ingress/ALB y `REVERB_ALLOWED_ORIGINS` explícito. No usa bind mounts ni publica los puertos de
PostgreSQL, Redis, PHP-FPM o Reverb; Nginx expone HTTP sólo en loopback para el ingress local.
El entrypoint falla si falta `APP_KEY` y no genera ni copia `.env` en producción.

El target `runtime` instala sólo dependencias Composer de producción. Los targets `runtime-dev` y
`runtime-e2e` conservan las dependencias de desarrollo y no deben desplegarse.

- **App**: contenedores PHP-FPM detrás de nginx/ALB. Horizontalmente escalables (stateless,
  sesiones en Redis, storage S3).
- **Workers**: `queue:work` por cola (`default`, `knowledge`, `analytics`) +
  `schedule:work` en un scheduler dedicado. Los jobs de WhatsApp, flows y triggers usan
  `default`; procesamiento de documentos usa `knowledge`; agregación diaria usa `analytics`.
- **Reverb**: al menos 1 nodo; múltiples nodos con `REVERB_*` compartidos y sticky sessions
  no necesarias (broadcast por Redis pub/sub si se usa `redis` driver de broadcaster).
- **DB**: backups automáticos (PITR), réplica de lectura para analytics/read-heavy opcional.
- **Redis**: alta disponibilidad (cluster) cuando el volumen lo exija; Sentinel/Master-Master.
- **Storage**: S3 (producción), minio (local).
- **Observabilidad**: Sentry (errores), logs estructurados JSON a stdout, correlación por
  `request_id`/`tenant_id`/`conversation_id`.

### Migraciones coordinadas

- La migración de FASE 15 U1 que establece invariantes de handoff añade scopes y columnas NOT
  NULL en el mismo release y ejecuta backfill/constraints sobre tablas existentes. No admite un
  rolling deploy con procesos de versiones mezcladas.
- Secuencia obligatoria: activar mantenimiento, detener workers/scheduler, desplegar la nueva
  imagen, ejecutar `php artisan migrate --force` y luego
  `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (idempotente; sincroniza el
  espejo spatie, incluido `conversations.claim`), verificar healthcheck y reactivar procesos y
  tráfico. Dimensionar la ventana con una copia representativa porque PostgreSQL retiene locks
  durante el backfill y la creación de constraints/índices.

### Gate de migración (FASE 26 U1)

No existe auto-migrate en ningún contenedor (`entrypoint.sh`, `Dockerfile`, servicios
`docker-compose.yml`). **Toda migración requiere ejecución manual explícita por el deployer**
antes de activar tráfico.

Historical migration example from the FASE 26 gate:
- `2026_08_25_100001_create_usage_reservations_table.php` — tabla
  `usage_reservations` con PK uuid, FK→`tenants` CASCADE, FK→`subscriptions` CASCADE,
  CHECK `quantity > 0`, UNIQUE compuesta `(tenant_id, subscription_id, category,
  idempotency_key)`, índice compuesto `(tenant_id, subscription_id)`.

**Secuencia de deploy con migración pendiente:**
1. Activar modo mantenimiento.
2. Detener workers y scheduler (`queue:work --stop`, sin `schedule:run`).
3. Desplegar la nueva imagen.
4. Ejecutar `php artisan migrate --force`.
5. Verificar con `php artisan migrate:status` que la migración figura como "Yes".
6. Verificar healthcheck.
7. Reactivar tráfico, workers y scheduler.

**Rollback**: la migración soporta `php artisan migrate:rollback` (drop table
`usage_reservations`). El rollback revierte la migración, pero las reservas de uso
activas se perderán. En producción, dado que la tabla es nueva y UsageGuard aún no
la consume en chokepoints de mensajes/flows (pendiente de activación), el rollback
es seguro si se ejecuta antes de activar dichos chokepoints.

## 7. Health check

`GET /health` → `{"status":"ok"}`. Verifica DB y Redis (si falla redis, degrada a `degraded`).
Usado por orquestadores y balancers.

## 8. FASE 34 U2: migration and recovery release gate

The current U2 release has four pending migrations, in dependency order:

1. `2026_08_25_100001_create_usage_reservations_table`
2. `2026_08_26_000001_add_tenant_id_id_unique_to_messages_and_whatsapp_accounts`
3. `2026_08_26_000002_create_message_media_table`
4. `2026_08_26_000003_create_whatsapp_templates_table`

Before enabling traffic, the deployer must complete the preflight and postflight in
`docs/runbooks/database-migrations.md`, including a verified backup. Migration 2
requires special attention: it requests `AccessExclusiveLock` and is not suitable
for an unbounded rolling deploy. Use a maintenance window, bounded `lock_timeout`,
and an abort/retry decision if active traffic prevents the lock.

Recovery is performed into a new database first and validated before cutover. Follow
`docs/runbooks/backup-restore.md`; do not restore over the source database or delete
the source before the incident owner approves it. U2 did not execute production
migrations, PITR or provider operations.

## 9. FASE 34 U3: runtime and worker release gate

Production is deployed as independent `web`, `app`, `worker-default`, `worker-knowledge`,
`worker-analytics`, `scheduler` and `reverb` services. PostgreSQL, Redis, S3-compatible
storage, SMTP and TLS are external infrastructure. The Compose template defaults to
`.env.production`; `PRODUCTION_ENV_FILE` may point to an explicitly managed alternate
file for validation or deployment. Use `docs/runbooks/runtime-topology.md`
and `docs/runbooks/worker-recovery.md`.

Before enabling traffic, provide `.env.production` from the secret manager with
`LOG_STACK=json`, `LOG_LEVEL=warning`, explicit Reverb origins, authenticated Redis,
private S3 and real SMTP. Do not use Mailpit or wildcard origins. Run
`docker compose -f docker-compose.production.yml config --quiet`, confirm no internal
ports are published, and wait for `/health=200`, `/ready=200`, healthy workers and a
current scheduler heartbeat. Use `php artisan queue:restart` for code reloads; never
auto-run migrations from the container entrypoint. The template uses `on-failure:5` so
bad configuration cannot restart forever without alerting. U3 was validated only with
disposable infrastructure: no production deployment, provider call, capacity claim or
load test is implied.
