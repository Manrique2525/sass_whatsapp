# Roadmap

Estado general: **FASE 2 COMPLETADA** (autenticación). Solo se trabaja sobre la fase activa.

## Fases

| # | Fase | Estado |
|---|---|---|
| 0 | Arquitectura y documentación | COMPLETADA |
| 1 | Infraestructura (Laravel, Docker, health check) | COMPLETADA |
| 2 | Autenticación (Sanctum, roles iniciales) | COMPLETADA |
| 3 | Tenants (TenantContext, aislamiento) | PENDIENTE |
| 4 | Usuarios y roles (invitaciones, agentes) | PENDIENTE |
| 5 | Business profile | PENDIENTE |
| 6 | WhatsApp (webhooks, provider) | PENDIENTE |
| 7 | Contactos | PENDIENTE |
| 8 | Conversaciones | PENDIENTE |
| 9 | Mensajes (jobs async) | PENDIENTE |
| 10 | Bandeja de entrada (UI + Reverb) | PENDIENTE |
| 11 | Chatbot engine | PENDIENTE |
| 12 | Flow Builder (Vue Flow) | PENDIENTE |
| 13 | Variables de conversación | PENDIENTE |
| 14 | Triggers | PENDIENTE |
| 15 | Transferencia a humano | PENDIENTE |
| 16 | IA (AIProviderInterface, OpenAI) | PENDIENTE |
| 17 | Base de conocimiento (RAG + pgvector) | PENDIENTE |
| 18 | FAQ inteligente | PENDIENTE |
| 19 | Leads | PENDIENTE |
| 20 | Tags | PENDIENTE |
| 21 | Analytics | PENDIENTE |
| 22 | Notificaciones | PENDIENTE |
| 23 | Planes | PENDIENTE |
| 24 | Billing (Stripe) | PENDIENTE |
| 25 | Usage limits | PENDIENTE |
| 26 | Auditoría | PENDIENTE |
| 27 | Seguridad (refuerzo OWASP) | PENDIENTE |
| 28 | Observabilidad (Sentry, logging) | PENDIENTE |
| 29 | Testing global + cobertura | PENDIENTE |
| 30 | E2E (Playwright) | PENDIENTE |
| 31 | Testing de webhooks (mocks Meta) | PENDIENTE |
| 32 | Testing de fallbacks | PENDIENTE |
| 33 | Performance | PENDIENTE |
| 34 | DevOps (Docker, CI/CD) | PENDIENTE |
| 35 | Documentación API (OpenAPI) | PENDIENTE |
| 36 | Seeders demo | PENDIENTE |
| 37 | Demo completa | PENDIENTE |

## Definición de DONE

Un módulo está DONE cuando cumple TODO:

- [ ] Código implementado (sin código falso).
- [ ] Tests creados y verdes (`php artisan test`).
- [ ] Lint + phpstan sin errores.
- [ ] Migraciones aplicables sin errores.
- [ ] Multi-tenancy validado (tests de aislamiento).
- [ ] Seguridad validada (auth + policies + rate limits).
- [ ] Frontend build (`npm run build`) + typecheck sin errores.
- [ ] Documentación actualizada.
- [ ] Sin errores conocidos críticos.
- [ ] Commit creado (mensaje `feat|fix|test|chore(<modulo>): ...`).

## Formato de reporte obligatorio al finalizar cada módulo

```
## MÓDULO
<nombre>

## IMPLEMENTADO
<lista>

## ARCHIVOS
<lista>

## DATABASE
<migraciones>

## API
<endpoints>

## TESTS
<cantidad>

## RESULTADOS
PASS / FAIL

## SEGURIDAD
<validaciones>

## PENDIENTES
<lista real>

## ESTADO
COMPLETADO / BLOQUEADO
```

## Fases 0 — entregables

- [x] AGENTS.md
- [x] docs/architecture.md
- [x] docs/database.md
- [x] docs/api.md
- [x] docs/security.md
- [x] docs/multi-tenancy.md
- [x] docs/whatsapp.md
- [x] docs/chatbot-engine.md
- [x] docs/ai.md
- [x] docs/testing.md
- [x] docs/deployment.md
- [x] docs/roadmap.md
- [x] docs/decisions.md
- [x] Revisión arquitectónica final (ARCHITECTURE REVIEW) — docs actualizadas
      (usuarios multi-tenant, roles por tenant, auth stateful+Bearer, webhook
      platform/outbox, aislamiento Redis/S3, locks de flujo, ADRs 011–016)
- [x] Aprobación del usuario → FASE 1

## Fase 1 — Infraestructura (estado)

- [x] Proyecto Laravel 12 creado en el workspace
- [x] `pdo_pgsql`/`pgsql` habilitados en `C:\php\php.ini`
- [x] Dependencias: reverb, pest, pint, phpstan (+ larastan)
- [x] Pest inicializado + tests de infraestructura (4 green)
- [x] Config Reverb + broadcasting publicados
- [x] Estructura modular `app/{Domain,Application,Infrastructure,Http,Jobs}`
- [x] `HealthChecker` + `GET /health` + comando `health:check`
- [x] Migración pgvector (`CREATE EXTENSION IF NOT EXISTS vector`)
- [x] Dockerfile + docker-compose (app/worker/schedule/reverb/nginx/postgres/redis/minio/mailpit)
- [x] `.env.example` (pgsql/redis/reverb/minio/mailpit), disco `minio`
- [x] Pint (preset laravel) + PHPStan (nivel 6, larastan) sin errores
- [x] `npm run build` sin errores
- [x] Verificación de todos los servicios con `docker compose up` (los 9 servicios arriba)
- [x] Migraciones aplicadas en el contenedor + pgvector 0.8.6 activo
- [x] Endpoints verificados: `GET /health` → 200 ok, `/` → 200
- [x] Queue end-to-end (job → Redis → worker → log)
- [x] Storage MinIO (bucket auto-creado, write/read OK) + Mailpit (SMTP OK)
- [x] Documentación de comandos (ver `docs/deployment.md`)

## Notas de entorno (registradas FASE 0/1)

- PHP 8.3.33 + Composer 2.10 + Node 24 disponibles en la máquina.
- Docker NO instalado. PostgreSQL/Redis NO disponibles localmente. `pdo_pgsql` y `pgsql`
  habilitados en `php.ini`. Decisión (ADR-002): instalar Docker Desktop para el entorno local.

## Fase 2 — Autenticación (estado)

- [x] Sanctum instalado: web por sesión (Inertia, CSRF) + API por Bearer (`auth:sanctum`)
- [x] spatie/laravel-permission instalado en modo teams (`team_foreign_key = tenant_id`)
- [x] `User` movido a `app/Domain/Users/Models/User` (multi-tenant listo: pivot `tenant_users`,
      `current_tenant_id` sin FK hasta FASE 3)
- [x] Migraciones: `permission_tables`, `personal_access_tokens`, `current_tenant_id`,
      `tenant_users` (unique user+tenant, índice tenant+role)
- [x] Roles base (`super_admin` global; `owner`/`admin`/`agent` por tenant) via seeder
- [x] Servicios de aplicación: RegisterUser, AuthenticateUser, SendPasswordResetLink,
      ResetUserPassword, VerifyUserEmail
- [x] Reset de contraseña con URL SPA (`/reset-password?token=&email=`), no filtra emails
- [x] Verificación de email (URL firmada + reenvío con throttle `6,1`)
- [x] API `/api/v1/auth/*` con formato de error estándar `{message, code, errors}`
      (`VALIDATION_ERROR`, `UNAUTHENTICATED`, `RATE_LIMITED`)
- [x] Rate limits: `auth-login` 10/min, `auth-register` 5/min, `auth-password` 3/min
- [x] Frontend Inertia + Vue 3 + TypeScript: Login, Register, ForgotPassword, ResetPassword,
      VerifyEmail, Dashboard (Tailwind)
- [x] Tests (37 green): auth web + API + multi-tenant prep + unit de servicios/enums
- [x] Pint + PHPStan (nivel 6) + `vue-tsc` + `vite build` sin errores
- [x] Verificado en Docker: `migrate:fresh --seed`, `/health` ok, flujo auth end-to-end,
      email de reset en Mailpit, páginas Inertia renderizadas
