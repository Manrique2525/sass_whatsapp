# Roadmap

Estado general: **FASE 6 COMPLETADA** (WhatsApp). Solo se trabaja sobre la fase activa.

## Fases

| # | Fase | Estado |
|---|---|---|
| 0 | Arquitectura y documentación | COMPLETADA |
| 1 | Infraestructura (Laravel, Docker, health check) | COMPLETADA |
| 2 | Autenticación (Sanctum, roles iniciales) | COMPLETADA |
| 3 | Tenants (TenantContext, aislamiento) | COMPLETADA |
| 4 | Usuarios y roles (invitaciones, agentes) | COMPLETADA |
| 5 | Business profile | COMPLETADA |
| 6 | WhatsApp (webhooks, provider) | COMPLETADA |
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

## Fase 3 — Tenants y aislamiento multi-tenant (estado)

- [x] Modelo `Tenant` (UUID, `name`, `slug` unique, `status` enum, `timezone`, `locale`,
      `settings` json) + `TenantStatus` enum + factory
- [x] Migraciones: `tenants`, `audit_logs`; `tenant_users.tenant_id` y
      `users.current_tenant_id` → UUID con FK real (cascade/nullOnDelete)
- [x] `TenantContext` (set/setId/tenant/id/bound/clear) + `TenantScope` fail-safe
      (sin contexto: lecturas vacías) + trait `BelongsToTenant` (writing exige contexto,
      `tenant_id` forzado desde contexto)
- [x] Middleware `tenant` (403 `NO_TENANT`; valida activo + membresía real; `finally` clear)
- [x] `TenantPolicy` (viewAny/view/update/switch) + rutas `api/v1/tenants`
      (index/show/update/switch) con `can:*` y middleware `tenant` en recursos
- [x] Servicios de aplicación: `TenantService` (list/available/current/show/update, solo tenant
      activo → 404) y `SwitchTenant` (membresía → 404, inactivo → 409, audita `tenant.switched`,
      dispara `TenantSwitched`)
- [x] Trait `TenantAwareJob` (`forTenant()`, `handle()` con contexto propio + `finally` clear)
- [x] Auditoría mínima: `AuditLog` + `AuditLogger` (switch/update de tenant)
- [x] Reverb: patrón `tenant.{tenantId}.conversations.{conversationId}` (sin comodín `*`,
      ADR-022) con autorización por pertenencia + `TestAuthBroadcaster`
- [x] Frontend: `AppLayout` con lista de tenants y switch desde el sidebar (`Dashboard`)
- [x] Fix suite: `beforeEach` global encadenado en `tests/Pest.php` (Pest 3.8) con limpieza de
      `TenantContext` + rate limiters; `forgetGuards()` en tests de logout
- [x] Fix tests en Docker: phpunit.xml con `<server>` (determinismo env, ADR-024) y
      `APP_ENV` redundante eliminado de docker-compose
- [x] Tests (56 nuevos en FASE 3 → 93 total, 296 assertions): aislamiento (16), tamper de
      `tenant_id`, jobs tenant-aware (TEST 9–14), canal Reverb, switch, policy, servicios
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` sin errores
- [x] Regresión Docker: `migrate:fresh --seed`, suite completa verde en el contenedor,
      `/health` ok, flujo API register→me→tenants→logout, Reverb arriba
- [x] Documentación: `multi-tenancy.md` (implementación real), `security.md` (controles FASE 3),
      `api.md` (§3.1 tenants), `decisions.md` (ADRs 020–024)
- [x] Revisión de seguridad FASE 3: IDOR, tampering `tenant_id`, enumeración, fuga cross-tenant
      (colas/Reverb/webhooks), determinismo de tests

## Fase 4 — Usuarios y roles (estado)

- [x] Migración de spatie a UUID (`tenant_id` en roles/model_has_roles/model_has_permissions,
      driver-aware pgsql/sqlite, ADR-025) + `tenant_users.status` (active/invited/disabled) +
      `tenant_invitations` (token_hash sha256, ADR-027)
- [x] Enums: `UserRole` (con `GLOBAL_TEAM_ID`), `TenantPermission` (11 permisos + matriz),
      `TenantMembershipStatus`, `InvitationStatus`
- [x] `TenantTeamResolver` (override → TenantContext → current_tenant_id → null) + `team_resolver`
      en config/permission.php
- [x] `AuthorizationService` (matriz como fuente de verdad, spatie como espejo, ADR-026):
      tenant activo + membresía activa + permiso → 403 `PERMISSION_DENIED` / 404 no-miembro /
      409 `TENANT_NOT_ACTIVE`
- [x] `TenantRoleManager` (syncRoles reemplaza, revokeRoles, assignGlobalRole) +
      `MemberService` (list/changeRole/remove con reglas owner/admin) + `InvitationService`
      (invite/accept/revoke/resend, estados 201/200/409/410/403/422/404)
- [x] Endpoints API `/api/v1/tenants/{tenant}/users` (GET/PATCH/DELETE), `/users/invitations`
      (GET/POST) y `/users/invitations/{invitation}/revoke|resend`; públicos
      `GET /api/v1/invitations/{token}` y `POST .../accept`; web `invitations/{token}` y
      `settings/users`
- [x] Auth: `me()` con `current_role`/`permissions`/`is_super_admin`; audit `user.login/logout`;
      Inertia comparte `auth.current_role`/`permissions`/`is_super_admin`
- [x] Frontend: `Settings/Users.vue` (miembros, invitaciones, roles) e
      `Invitations/Accept.vue` (aceptar por enlace) + tipos TS
- [x] Seeder `RolesAndPermissionsSeeder` actualizado: 11 permisos + `syncPermissions` por rol
- [x] Tests (25 nuevos en FASE 4 → 123 total, 428 assertions): AUTH-1..8, INV-9..14,
      ROLES-15..20, MT-21..24 + crítico X (invitación a B no da acceso sin switch)
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` sin errores
- [x] Regresión Docker: migraciones aplicadas en postgres (uuid incluida), suite completa verde
      en el contenedor, seeder re-ejecutado en dev
- [x] Documentación: `multi-tenancy.md`, `security.md`, `api.md` (§3.3), `roadmap.md`,
      `decisions.md` (ADRs 025–027)

## Fase 5 — Business profile (estado)

- [x] Migración `business_profiles` (1:1 con `tenants`: `tenant_id` UNIQUE FK `cascadeOnDelete`;
      campos `name/description/category/address/website/email/phone/working_hours` JSON; sin logo
      — requiere media/storage en fase posterior, ADR-028)
- [x] `BusinessProfile` (`app/Domain/Business/Models`): trait `BelongsToTenant` (scope + forzado
      de `tenant_id` por TenantContext; `tenant_id` no fillable) + `Tenant::businessProfile()`
      (hasOne)
- [x] `TenantPermission`: +`business_profile.view` (todos los roles) y
      `business_profile.update` (owner/admin) → 13 permisos; matriz y seeder actualizados
- [x] `BusinessProfileService` (show auto-create con invariante 1:1 + update parcial con
      autorización y auditoría `business_profile.created/updated`)
- [x] HTTP: `UpdateBusinessProfileRequest` (validación completa), `BusinessProfileResource`,
      `BusinessProfileController` (GET/PUT `/api/v1/tenants/{tenant}/business-profile`, 404/403/409),
      web `settings/business-profile`
- [x] Frontend: `Settings/BusinessProfile.vue` (formulario completo + horarios dinámicos, oculto
      para agent) y nav de Settings en `AppLayout.vue`
- [x] Tests (12 nuevos en FASE 5 → 135 total, 475 assertions): BP-1..12 (CRUD, aislamiento
      CRITICO BP-6, rol activo, tampering BP-8, auditoría BP-9, validación, matriz)
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` sin errores
- [x] Regresión Docker: `migrate:fresh --seed` en postgres sin errores, suite completa verde en el
      contenedor, `health:check` ok
- [x] Documentación: `api.md` (§3.3), `security.md`, `multi-tenancy.md`, `database.md`,
      `roadmap.md`, `decisions.md` (ADR-028)

## Fase 6 — WhatsApp (estado)

- [x] Provider `MetaWhatsAppProvider` implementando `WhatsAppProviderInterface` (Graph **v26.0**,
      ADR-029): sendText/sendTemplate/sendImage/sendDocument/sendInteractiveMessage/markAsRead,
      getPhoneNumberInfo (valida credenciales antes de persistir), subscribe/unsubscribe de
      webhooks, `validateWebhookSignature` (HMAC-SHA256 + `hash_equals` sobre el body crudo) y
      `verifyWebhook` (GET challenge, fallback a claves `hub.mode`/`hub.verify_token`/`hub.challenge`
      por el underscore de PHP). El `access_token` se pasa POR LLAMADA (token del WABA del tenant,
      nunca global). Errores de Meta normalizados en excepciones de dominio con `providerErrorCode`
      + `retryable` (timeout/5xx/429 transitorios; 4xx permanentes).
- [x] Migraciones (4): `whatsapp_accounts` (1 por tenant, `access_token` CIFRADO `encrypted`,
      `status` string+cast), `whatsapp_phone_numbers` (`phone_id` UNIQUE = clave de resolución de
      tenant del webhook, `whatsapp_account_id` FK `nullOnDelete`), `webhook_events` (PLATAFORMA,
      `provider_event_id` UNIQUE = dedupe, `tenant_id` nullable, `status`/`event_type`/`duplicate`),
      `message_send_attempts` (cada llamada al provider; intentos/backoff real en FASE 9)
- [x] Modelos en `app/Domain/WhatsApp/Models` con enums (WhatsAppAccountStatus, PhoneNumberStatus,
      WebhookEventStatus, WebhookEventType, WhatsAppErrorCode) y VOs (`MessageSendResult`,
      `PhoneNumberInfo`, `InteractiveMessage`)
- [x] `WhatsAppWebhookService` (Application): firma → parseo → dedupe (`ON CONFLICT DO NOTHING`) →
      resolución de tenant por `phone_number_id` → `enqueued` + dispatch de jobs. Payload
      malformado/desconocido → `failed` + **200** (nunca 500; Meta no reintenta infinitamente)
- [x] Jobs `ProcessIncomingWhatsAppMessage` / `ProcessWhatsAppStatusUpdate` (TenantAwareJob +
      ShouldQueue): guard de estado `Enqueued` + `event_type` + `tenant_id`, `markProcessed()`.
      La persistencia de mensajes/conversaciones llega en FASE 7-9 (TODO marcado)
- [x] HTTP: webhook público `GET/POST /api/webhooks/whatsapp` (verify 403/200, handle 401/200/200),
      `WhatsAppController` (`GET/POST /api/v1/tenants/{tenant}/whatsapp[\/connect|\/disconnect]`),
      `ConnectWhatsAppRequest`, `WhatsAppAccountResource` (jamás expone `access_token`)
- [x] `WhatsAppConnectionService` (conectar valida SIEMPRE contra Meta antes de persistir; el token
      se guarda cifrado; desconectar anula token + status + suscripción WABA, conserva historial;
      auditoría `whatsapp.connected/disconnected/webhook_configured/message_sent/message_failed`)
      y `WhatsAppMessagingService` (envío registrando `message_send_attempts`)
- [x] Permisos `whatsapp.view` (todos) / `whatsapp.manage` (owner/admin) en la matriz ADR-026 →
      15 permisos; seeder actualizado
- [x] Frontend `Settings/WhatsApp.vue` (estado de conexión, conectar con token tipo password,
      desconectar con confirmación, `can('whatsapp.manage')`) + ruta web `settings/whatsapp` +
      nav en `AppLayout`
- [x] Tests (42 nuevos en FASE 6 → **177 total, 597 assertions**): WHATSAPP-1..14 webhook
      (firma, verify, duplicados, aislamiento CRITICO por phone_id, jobs por tipo, payload
      malformado, matriz permisos), WHATSAPP-15..30 conexión/envío (token cifrado, 401/404/409,
      aislamiento CRITICO A/B, errores permanentes/transitorios), WHATSAPP-31..40 provider unit
      (firma, verify, payload oficial, mapeo de errores, subscribed_apps)
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` sin errores; exclusión
      `trait.unused` de phpstan.neon eliminada (TenantAwareJob ya tiene consumidores de producción)
- [x] Regresión Docker: `migrate:fresh --seed` en postgres sin errores, suite completa verde en el
      contenedor (177), `health:check` ok
- [x] Documentación: `whatsapp.md`, `api.md` (§3.4 + webhooks), `database.md`, `security.md`,
      `multi-tenancy.md` (aislamiento webhook), `testing.md`, `deployment.md`, `roadmap.md`,
      `decisions.md` (ADR-029)
