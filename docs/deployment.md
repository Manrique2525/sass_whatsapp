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
OPENAI_MODEL=gpt-4o-mini

AWS_ACCESS_KEY_ID=minio
AWS_SECRET_ACCESS_KEY=minio123
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=whatsapp-saas
AWS_ENDPOINT=http://minio:9000   # vacío en producción

SENTRY_LARAVEL_DSN=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
```

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

- **App**: contenedores PHP-FPM detrás de nginx/ALB. Horizontalmente escalables (stateless,
  sesiones en Redis, storage S3).
- **Workers**: `queue:work` por cola (`high`, `default`, `whatsapp`, `ai`, `billing`) +
  `schedule:run` en un worker dedicado.
- **Reverb**: al menos 1 nodo; múltiples nodos con `REVERB_*` compartidos y sticky sessions
  no necesarias (broadcast por Redis pub/sub si se usa `redis` driver de broadcaster).
- **DB**: backups automáticos (PITR), réplica de lectura para analytics/read-heavy opcional.
- **Redis**: alta disponibilidad (cluster) cuando el volumen lo exija; Sentinel/Master-Master.
- **Storage**: S3 (producción), minio (local).
- **Observabilidad**: Sentry (errores), logs estructurados JSON a stdout, correlación por
  `request_id`/`tenant_id`/`conversation_id`.

## 7. Health check

`GET /health` → `{"status":"ok"}`. Verifica DB y Redis (si falla redis, degrada a `degraded`).
Usado por orquestadores y balancers.
