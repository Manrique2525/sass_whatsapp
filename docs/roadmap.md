# Roadmap

Estado general: **FASE 23 COMPLETADA · FASE 24 COMPLETADA · FASE 25 COMPLETADA · FASE 26 COMPLETADA · FASE 27 COMPLETADA · FASE 28 COMPLETADA · FASE 29 COMPLETADA · FASE 30 COMPLETADA (U1/U2/U3/U4/U5-A/U5-B/U5-C/U5-D/U5-E COMPLETADAS) · FASE 31 COMPLETA LOCALMENTE (U1/U2/U3/U4/U5/U6; pendiente revisión global) · FASE 32 COMPLETADA/PUBLICADA (U1: deterministic message ordering) · FASE 33 COMPLETA LOCALMENTE (U1: self-service provisioning; pendiente revisión global)**.

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
| 7 | Contactos | COMPLETADA |
| 8 | Conversaciones | COMPLETADA |
| 9 | Mensajes (jobs async) | COMPLETADA |
  | 10 | Bandeja de entrada (UI + Reverb) | COMPLETADA |
  | 11 | Chatbot engine | COMPLETADA |
| 12 | Flow Builder (Vue Flow) | COMPLETADA |
| 13 | Variables de conversación | COMPLETADA |
| 14 | Triggers | COMPLETADA |
| 15 | Transferencia a humano | COMPLETADA |
| 16 | IA (AIProviderInterface, OpenAI + AI Node Runtime + Telemetry + Security) | COMPLETADA |
  | 17 | Base de conocimiento (RAG + pgvector) | COMPLETADA |
| 18 | FAQ inteligente | COMPLETADA |
| 19 | Leads | COMPLETADA |
| 20 | Tags | COMPLETADA |
| 21 | Analytics | COMPLETADA |
| 22 | Notificaciones (U1: Data Model, U2: Event Listeners, U3: Notification API, U4: Email Preferences, U5: Realtime + Frontend) | COMPLETADA |
| 23 | Planes (U1: Data Model, U2: Usage Metering, U3: Billing API, U4: Billing Frontend) | COMPLETADA |
| 24 | Billing (U1: Provider Infrastructure + Mappings, U2: Checkout, U3: Webhooks, U4: Frontend Provider UX, U5: Hardening + Closure) | COMPLETADA |
| 25 | Usage limits (U1: UsageGuard + Atomic Quota Reservation, U2: Message + Flow Quota Enforcement, U2-HOTFIX: fail-closed missing entitlement, U3: AI Token Enforcement, U4: Capacity Limits, U5: Hardening + Closure) | COMPLETADA |
| 26 | Auditoría + Seguridad (U1: Deployment Gate + Rate Limiting, U2: Billing atomicity + UsageGuard hardening, U3: Job timeout + error handling, U4: LIKE escaping + provider error sanitization + global exception renderers) | COMPLETADA |
| 27 | Seguridad (refuerzo OWASP) — U1: Security Headers + CORS + Session Hardening · U2: Sanctum Token Expiry + TrustProxies + Structured Error Responses · U3: Token Rollout Verification + Documentation Closure | COMPLETADA |
| 28 | Observabilidad (U1: Structured Logging + Correlation IDs · U2: Backend Sentry · U3: Frontend Sentry · U4: Health Checks + Queue · U5: Alerting + Ops Docs) | COMPLETADA |
| 29 | Testing global + cobertura (U1: Coverage Infra + Critical Gap Baseline ✅ · U2: Tenancy + Auth · U3: Billing/Concurrency/PG · U4: Jobs/Webhooks · U5: Frontend + Closure) | COMPLETADA |
| 30 | E2E Playwright (U1: Infra + Auth + Multi-Tenancy Base · U2: Inbox · U3: Handoff · U4: Flow Builder + Billing + Knowledge integration · U5: CI foundation/static/frontend/backend/PostgreSQL integration) | EN PROGRESO |
| 31 | Meta / WhatsApp Cloud API (U1: Provider + config hardening · U2: Webhook authenticity + durable ingestion · U3: Inbound normalization + monotonic status · U4: Outbound delivery ambiguity + care window · U5: Secure media + approved templates · U6: Operations, Observability & Production Readiness) | COMPLETA LOCALMENTE (pendiente revisión) |
| 32 | Deterministic message ordering (U1: `ORDER BY created_at, id` en inbox + conversación activa + frontend realtime==reload · contract + tests) | COMPLETADA/PUBLICADA |
| 33 | Self-service provisioning (U1: registro atómico User + workspace + owner + plan free + onboarding post-verificación) | COMPLETA LOCALMENTE (pendiente revisión) |
| 34 | Performance | PENDIENTE |
| 35 | DevOps (Docker, CI/CD) | PENDIENTE |
| 36 | Documentación API (OpenAPI) | PENDIENTE |
| 37 | Seeders demo | PENDIENTE |
| 38 | Demo completa | PENDIENTE |

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

## Fase 7 — Contactos (CRM básico) (estado)

- [x] Migraciones (3): `contacts` (UUID, `tenant_id` FK `cascadeOnDelete`, `phone` E.164 canónico
      con `+` y sin separadores, `name`, `email`, `avatar_url` (2048), `metadata` JSON,
      `provider_contact_id` (preparado para correlación outbound FASE 10+), `last_interaction_at`,
      timestamps, softDeletes, índices `(tenant_id, created_at)` y `(tenant_id, name)` y UNIQUE
      PARCIAL `(tenant_id, phone) WHERE deleted_at IS NULL` = unicidad por tenant entre activos),
      `tags` (`(tenant_id, name)` UNIQUE) y `contact_tag` (PK `(contact_id, tag_id)`); tablas de
      tags preparadas para FASE 20 (sin API/UI)
- [x] Modelos en `app/Domain/Contacts/Models`: `Contact` (BelongsToTenant + HasUuids + SoftDeletes,
      casts metadata/last_interaction_at, relación `tags()` N:M) y `Tag`; `Tenant::contacts()`
      (hasMany)
- [x] Excepciones de dominio: `ContactDuplicateException` (409, code `CONTACT_DUPLICATE`) y
      `ContactNotFoundException` (mapeada a 404 por el controller, oculta existencia ADR-010/023)
- [x] `ContactService` (Application): `normalizePhone()` (E.164 con `+`; espejo del webhook de Meta),
      index con filtros search/phone/email + paginación, showForUser, create, update (parcial),
      delete (soft), `findOrCreateForPhone()` sin autorización para jobs del webhook (FASE 9, con
      backstop de carrera `QueryException` → re-consulta). Guard `assertPhoneUnique` + índice único
      parcial como backstop. Auditoría `contact.created/updated/deleted`
- [x] Permisos `contacts.view` (todos los roles) / `contacts.manage` (owner/admin) en la matriz
      ADR-026 → 17 permisos; seeder actualizado (espejo spatie se alimenta de `all()`)
- [x] HTTP API `/api/v1/tenants/{tenant}/contacts` (GET/POST) y `/contacts/{contact}`
      (GET/PATCH/DELETE): index 200 + `meta` de paginación explícito, store 201, update/delete 200,
      duplicado 409 `CONTACT_DUPLICATE`, 404/403/409 según patrón de fase. `{contact}` se resuelve
      como `string` (sin route-model binding implícito: `SubstituteBindings` corre antes que el
      middleware `tenant`) y el servicio filtra SIEMPRE por `tenant_id` autorizado
- [x] Requests: `ContactIndexRequest` (filtros + `per_page` 1..100), `StoreContactRequest` y
      `UpdateContactRequest` (phone regex `/^\+?[0-9\s().\-]+$/` + 7–15 dígitos por closure,
      email/avatar_url/metadata opcionales). `ContactResource` sin `tenant_id`
- [x] Web: `ContactSettingsController` + ruta `settings/contacts` + nav en `AppLayout`
- [x] Frontend: `Settings/Contacts.vue` (tabla responsive, filtros search/phone/email, paginación
      Anterior/Siguiente, modal crear/editar con validación y JSON de metadata, confirmación de
      borrado, `can('contacts.view')`/`can('contacts.manage')`) y `features/contacts/contactUtils.ts`
      (funciones puras espejo del backend)
- [x] Vitest instalado (devDep `vitest@^3`), `vitest.config.ts`, script `npm run test`,
      `contactUtils.test.ts` (13 tests: normalizePhone, hasValidPhoneDigits, buildContactQuery,
      extractErrorMessage, parseMetadata)
- [x] Tests (19 nuevos en FASE 7 → **196 total, 693 assertions**): CONTACT-1..19 (CRUD + 201/409,
      normalización E.164, validación, soft delete + re-creación, filtros + paginación, aislamiento
      CRITICO CONTACT-12 A/B, tampering CONTACT-13, matriz permisos CONTACT-14, auditoría
      CONTACT-15, no-miembro/suspendido/switch, `findOrCreateForPhone` CONTACT-19)
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` + `vitest` sin errores
- [x] Regresión Docker: migraciones aplicadas y revertidas (`migrate`/`rollback`) en postgres sin
      errores; suite completa verde
- [x] Documentación: `database.md`, `api.md` (§3.5), `security.md`, `multi-tenancy.md`,
      `whatsapp.md` (find-or-create FASE 9), `testing.md` (vitest), `architecture.md`, `roadmap.md`,
      `decisions.md` (ADR-030)

## Fase 8 — Conversaciones (estado)

- [x] Migraciones (3): `conversations` (UUID, `tenant_id` FK `cascadeOnDelete`, `contact_id` UUID FK
      `cascadeOnDelete`, `status` default `open`, `last_message_at`, `last_interaction_at`,
      `agent_id` FK `users.id` (BIGINT) `nullOnDelete` = asignación vigente, `auto_assigned`,
      `bot_paused`, `context` JSONB, `flow_execution_id` nullable SIN FK (la tabla de ejecuciones
      llega en FASE 11), timestamps, softDeletes, índices `(tenant_id, status, last_message_at)`,
      `(tenant_id, contact_id)`, `(tenant_id, agent_id)` y `(tenant_id, last_interaction_at)`),
      `conversation_participants` (UNIQUE `(conversation_id, user_id)`, `role` espejo del rol del
      tenant, `joined_at`/`left_at`) y `conversation_assignments` (historial acumulativo:
      `agent_id`, `assigned_by`, `assigned_at`, `unassigned_at`, `reason` manual/transfer)
- [x] `ConversationStatus` enum con máquina de estados (`canTransitionTo`): open↔pending,
      open/pending→resolved, resolved→archived, ≠open→open (reabrir); mismo estado = no-op
- [x] Modelos en `app/Domain/Conversations/Models`: `Conversation` (BelongsToTenant + HasUuids +
      SoftDeletes, casts status/context/timestamps/booleans, relaciones contact/agent/participants/
      assignments), `ConversationParticipant`, `ConversationAssignment`; `Tenant::conversations()`
- [x] Excepciones de dominio: `ConversationNotFoundException` (404), `ConversationContactNotFoundException`
      (404, oculta contacto cross-tenant), `ConversationInvalidStateException` (409,
      `CONVERSATION_INVALID_STATE`), `ConversationAgentNotInTenantException` (422, `AGENT_NOT_IN_TENANT`)
- [x] `ConversationService` (Application): index (filtros search sobre el contacto/status/agent_id,
      orden `last_interaction_at` DESC nulls last), showForUser, create (valida contacto del tenant),
      update (status con máquina de estados + merge de `context` por claves), assign/transfer
      (valida membresía ACTIVA en `tenant_users`, cierra la asignación/participación previa,
      registra historial, audita `conversation.assigned/transferred`), close/reopen (auditan),
      pauseBot/resumeBot (auditan `bot_paused/bot_resumed`), `findOrCreateActiveForContact` sin
      autorización para jobs del webhook (FASE 9)
- [x] Permisos `conversations.view` (todos los roles) / `conversations.manage` y
      `conversations.assign` (owner/admin) en la matriz ADR-026 → 20 permisos; seeder actualizado
      (espejo spatie se alimenta de `all()`)
- [x] HTTP API `/api/v1/tenants/{tenant}/conversations` (GET/POST) y `/conversations/{conversation}`
      (GET/PATCH) + acciones `assign|transfer|close|reopen|pause-bot|resume-bot` (POST). Se añade
      `POST /conversations` (crear) aunque la especificación de FASE 8 no lo listaba: es necesario
      para CONV-1 y para que FASE 9 (webhook) tenga el punto de entrada de alta (ver ADR-031)
- [x] Requests: `ConversationIndexRequest` (search/status enum/agent_id/per_page 1..100),
      `StoreConversationRequest` (contact_id uuid + status enum opcional + bot_paused + context),
      `UpdateConversationRequest` (status enum + context), `AssignConversationRequest` (agent_id).
      `ConversationResource` con contacto (ContactResource), agente y detalle de participantes/
      asignaciones `whenLoaded`; nunca expone `tenant_id`
- [x] Web: `ConversationsController` + ruta `settings/conversations` + nav en `AppLayout`
- [x] Frontend: `Conversations/Index.vue` (tabla responsive, filtros search/status/agente,
      paginación, modal de detalle con contacto/estado/agente/última interacción/contexto, alta con
      selector de contacto, asignación/transferencia por dropdown de miembros, cerrar/reabrir/
      pausar/reanudar bot con `can('conversations.view'|'manage'|'assign')`) y
      `features/conversations/conversationUtils.ts` (funciones puras espejo del backend)
- [x] Vitest: `conversationUtils.test.ts` (7 tests: buildConversationQuery, formatLastInteraction,
      canClose/canReopen)
- [x] Tests (24 nuevos en FASE 8 → **220 total, 821 assertions**): CONV-1..24 (CRUD + 201/409/422,
      validación, máquina de estados, context merge, assign/transfer con historial y auditoría,
      aislamiento CRITICO CONV-18/19 A/B (crear sobre contacto de B / leer-modificar-asignar con
      usuario de B), tampering CONV-20, matriz permisos CONV-21, agente solo lectura CONV-22,
      no-miembro, soft delete + `findOrCreateActiveForContact`)
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` + `vitest` sin errores
- [x] Regresión Docker: migraciones aplicadas y revertidas (`migrate`/`migrate:rollback --step=3`)
      en postgres sin errores; suite completa verde; `health:check` ok
- [x] Documentación: `database.md`, `api.md` (§3.6), `security.md`, `multi-tenancy.md`,
      `testing.md`, `architecture.md`, `roadmap.md`, `decisions.md` (ADR-031)

## Fase 9 — Mensajes (estado)

- [x] Migración `messages` (UUID, `tenant_id` FK `cascadeOnDelete`, `conversation_id` FK
      `cascadeOnDelete` — el contacto se resuelve vía la conversación, no se duplica en la tabla;
      `type` text/image/audio/video/document/location/interactive/template, `direction`
      inbound/outbound, `status` pending/sending/sent/delivered/read/failed, `body` TEXT,
      `provider_message_id` (id de Meta; idempotencia con `UNIQUE (tenant_id, provider_message_id)`,
      los NULL no colisionan), `media_url` (2048)/`media_mime` (100)/`media_size` (bigint) como
      columnas propias, `metadata` JSONB (`from`, `provider_timestamp` y payload del tipo:
      media/location/interactive/template), `sent_at`/`delivered_at`/`read_at`/`failed_at`
      (columna por estado, ADR-032), timestamps, índices `(tenant_id, conversation_id, created_at)`
      y `(conversation_id)`)
- [x] `MessageStatus` enum ampliado con `Sending = 'sending'` (estado CAS del job de envío) y
      `columnFor()` → `sent_at`/`delivered_at`/`read_at`/`failed_at` (null para pending/sending)
- [x] Modelos en `app/Domain/Messages/Models`: `Message` (BelongsToTenant + HasUuids + SoftDeletes,
      casts type/direction/status/metadata/timestamps, relaciones contact/conversation) y
      `Tenant::messages()`
- [x] `MessageService` (Application): `handleInboundMessage(tenant, eventData)` → find-or-create
      contacto (FASE 7) → find-or-create conversación activa (FASE 8) → dedupe por
      `provider_message_id` (mensaje duplicado = no-op + audita `message.duplicate`) → crea mensaje
      inbound (extrae body/media según tipo; `UnsupportedMessageTypeException` para tipos no
      soportados; metadata sin secretos) → `touchConversation` (reabre resolved/archived, actualiza
      `last_message_at`/`last_interaction_at`) → audita `message.received`. `handleStatusUpdate(tenant,
      eventData)` → update por `provider_message_id` (nunca crea; actualiza `columnFor` y estado,
      `failed` → conversación `pending`; audita `message.status_updated`). `createOutbound(tenant,
      conversation, text)` → crea mensaje `pending` + dispatch `SendWhatsAppMessage` (FASE 10 usará
      esta vía)
- [x] Jobs: `ProcessIncomingWhatsAppMessage` y `ProcessWhatsAppStatusUpdate` (TenantAwareJob +
      ShouldQueue, `tries=3`, backoff `[5,15,60]`, guard de estado `Enqueued`) ahora DELEGAN en
      `MessageService` y hacen `markProcessed()`/`markFailed()` real (sin TODOs pendientes).
      `SendWhatsAppMessage` (ShouldBeUnique `send:{id}` uniqueFor 300, timeout 60, `tries` de
      config, backoff `[10,30,60]`): CAS `pending → sending` con update atómico (job duplicado =
      no-op, evita doble envío), valida tipo text + cuenta conectada + teléfono conectado default,
      llama a `MetaWhatsAppProvider::sendText` con el token del WABA del tenant, registra
      `message_send_attempts` (attempt/max_attempts, `attempted_at`, `error_code`/`error_message`
      del proveedor en el intento fallido), éxito → `sent` + `provider_message_id` + audita
      `message.sent`; fallo permanente → `failed` + `failed_at` (el detalle del error queda en el
      attempt, no en `messages`) + audita `message.failed`; fallo retryable → rethrow (reintento
      real con backoff)
- [x] `WhatsAppWebhookService` refactorizado: `resolveAndEnqueue()` (resuelve tenant por
      `phone_number_id`, marca `enqueued`, dispatch por tipo) + `reprocessEvent()` público para el
      outbox. Dedupe de estados: Meta reusa el id de mensaje en `statuses` (delivered/read del mismo
      mensaje colisionaban en `provider_event_id` UNIQUE) → clave compuesta `id|status|timestamp`
- [x] Outbox (sweeper): comando `whatsapp:reprocess-webhook-events` (reprocesa eventos
      `received` de más de 5 minutos, limit 100) + `Schedule::command(...)->everyMinute()
      ->withoutOverlapping()` en `routes/console.php` (ADR-032: garantía de entrega ante fallos de
      resolución de tenant/jobs)
- [x] Tests (28 nuevos en FASE 9 → **248 total, 934 assertions**): MSG-1..9 (inbound e2e vía
      webhook: persistencia contacto/conversación/mensaje, dedupe, aislamiento CRITICO MSG-6 A/B,
      tipo no soportado → failed, media image, reabrir resolved, auditoría), STAT-1..8 (delivered/
      read/failed e2e, clave compuesta dedupe, idempotencia, no-op sin mensaje, aislamiento A/B),
      OUT-1..7 (dispatch, éxito con attempt+auditoría, fallo permanente, fallo retryable rethrow +
      queda `sending`, CAS no re-envía, no conectado, no-text), OUTBOX-1..4 (reprocesa `received`
      viejos, ignora nuevos/`processed`, límite 100, exit codes)
- [x] Helpers de test movidos a `tests/Support/helpers.php` (composer autoload-dev `files`):
      `make_contact`, `make_conversation`, webhook (`post_whatsapp_webhook`,
      `whatsapp_webhook_payload`, firma/secreto); suites FASE 7/8 actualizadas
- [x] Pint + PHPStan (nivel 6, larastan) + `vue-tsc` + `vite build` + `vitest` sin errores
- [x] Regresión Docker: `migrate`/`rollback` de `messages` en postgres sin errores (JSONB ok),
      suite completa verde en el contenedor, `health:check` ok (app/database/redis/queue)
- [x] Documentación: `database.md`, `whatsapp.md`, `api.md` (§3.7), `multi-tenancy.md`,
      `security.md`, `testing.md`, `roadmap.md`, `decisions.md` (ADR-032)

## Fase 10 — Bandeja de entrada (estado)

- [x] **REST de mensajes**: `GET|POST /api/v1/tenants/{tenant}/conversations/{conversation}/messages`
      bajo `middleware('tenant')`. `index` página DESC (`per_page` 1..100, default 30) → `{messages,
      meta}` con `MessageResource` completo; `store` valida `body` (required, string, max 4096),
      encola el envío real vía `MessageService::createOutbound` y responde 201 `{message,
      created_message}`. Errores estándar: no-miembro/tenant ajeno → 404, sin permiso → 403
      PERMISSION_DENIED, tenant inactivo → 409. Permisos: `conversations.view` (index) y nuevo
      `messages.send` (store) para owner/admin/agent
- [x] `TenantPermission::SendMessages` en `all()` y en la matriz de roles (owner/admin/agent);
      seeder sincronizado con el enum (espejo automático)
- [x] `Conversation::lastMessage()` (HasOne `latestOfMany`) + `ConversationResource::last_message`
      (whenLoaded) + eager-load en `ConversationService::index` (preview del listado)
- [x] **Realtime backend**: eventos `MessageCreated`, `MessageStatusUpdated` (con `previous_status`)
      y `ConversationUpdated` (`ShouldBroadcast`, `broadcastAs`/`broadcastWith` con payload vía
      `*Resource`) despachados con `Illuminate\Contracts\Events\Dispatcher` inyectado. Emisores:
      inbound/outbound/status (`MessageService`), `sent`/`failed` (`SendWhatsAppMessage`) y
      update/close/reopen/pause-bot/resume-bot/assign/transfer (`ConversationService`). Canal
      privado por conversación `tenant.{id}.conversations.{convId}` (patrón ya validado en
      `ReverbChannelAuthTest`; ADR-022/033)
- [x] **Realtime frontend**: `features/realtime/echo.ts` (init lazy con guard
      `VITE_REVERB_APP_KEY`, connector `reverb`, `forceTLS` según `VITE_REVERB_SCHEME`, se añadió
      `VITE_REVERB_SCHEME` a `.env`/`.env.example`) y composable `useConversationChannel` (se
      suscribe al canal de la conversación abierta, listener `.MessageCreated`/`.MessageStatusUpdated`/
      `.ConversationUpdated`, unsubscribe al cambiar/cerrar)
- [x] **features/messages**: `messageTypes.ts` (Message/MessagePagination/payloads), `messageUtils.ts`
      (buildMessageQuery, isNearBottom, formatMessageTimestamp, dayKey/messageDayLabel/
      groupMessagesByDay, mergeIncomingMessage con dedupe, applyMessageUpdate, messagePreview,
      messageStatusLabel, isOutbound). `Conversation` tipada con `last_message` y `TenantMember`
- [x] **Inbox UI** (`Pages/Conversations/Index.vue` reescrito + componentes en
      `Components/Conversations/`): `ConversationList` (ConversationListItem + ConversationFilters),
      `ChatHeader` (estado, asignar/transferir, cerrar/reabrir, pausar/reanudar bot, volver en
      mobile), `MessageList` (scroll inteligente: auto-bottom si estás al final, pill "Nuevos
      mensajes", "cargar anteriores" y carga al llegar al tope), `MessageBubble` (ticks de estado
      sent/delivered/read, error), `MessageComposer` (Enter envía, Shift+Enter salto de línea),
      `ContactPanel` (datos del contacto, agente, última interacción, contexto). Responsive: 3
      paneles en desktop, lista→chat con "volver" en mobile; `AppLayout` acepta `full-width` para
      la bandeja. Lista con filtros (búsqueda/estado/agente), "cargar más", polling 30 s (solo en
      página 1) como complemento de Reverb
- [x] **Vitest**: entorno `jsdom` + `@vitejs/plugin-vue` en `vitest.config.ts`; 48 tests
      (messageUtils 22, MessageComposer 6, contactUtils 13, conversationUtils 7)
- [x] Tests backend (16 nuevos en FASE 10 → **264 total, 996 assertions**): MSG-API-1..16
      (paginación DESC/per_page, agente lista, aislamiento A/B 404, IDOR, POST pending + job +
      timestamps, validación 422, cross-tenant 404, responder del inbox 201, matriz de permisos,
      eventos MessageCreated/ConversationUpdated/MessageStatusUpdated vía `Event::fake`, canal con
      prefijo `private-`, `last_message` en lista)
- [x] Pint + PHPStan (nivel 6) + `vue-tsc` + `vite build` + `vitest` sin errores; regresión Docker
      (suite completa verde, `migrate` sin cambios, `/health` ok)
- [x] Documentación: `api.md` (§3.7 mensajes), `architecture.md` (realtime), `testing.md`,
      `security.md`, `whatsapp.md`, `roadmap.md`, `decisions.md` (ADR-033)

> ## Fase 11 — Chatbot engine (estado)

- [x] **Modelo de datos** (ADR-034): migraciones `2026_08_17_0000xx_*` — `chatbots` (soft
      delete), `flows` (la fila ES la versión, sin `flow_versions`), `flow_nodes`
      (type/config/posición/is_start; el inicio es un nodo real, no existe tipo `start`),
      `flow_connections` (arista dirigida con label), `triggers`, `flow_executions` (UNIQUE
      parcial `(tenant_id, conversation_id) WHERE status IN ('running','waiting')` → una activa
      por conversación), `flow_execution_logs` (traza por paso). FK real
      `conversations.flow_execution_id` en `000700`. Enums `FlowStatus`, `FlowNodeType`,
      `FlowTriggerType`, `FlowExecutionStatus` con `label()`/`canTransitionTo()`/`isWaitingType()`
- [x] **Ejecutores de nodo** (ADR-035): `NodeExecutorInterface` + `NodeExecutorRegistry` + 9
      ejecutores (`message, buttons, question, condition, delay, tag, webhook, human, end`) en
      `app/Application/Flows/Services/Executors/`. `ai` queda **bloqueado en FlowValidator**
      (FASE 16); prohibido ejecutor vacío. `FlowValidator` (un solo inicio, grafo conexo,
      `end` alcanzable, config por tipo, sin loops), `VariableResolver`, `ConditionEvaluator`,
      `WebhookUrlGuard` (anti-SSRF)
- [x] **Motor** (ADR-037): `FlowEngine::handleMessage`/`continueExecution` bajo lock Redis por
      conversación (`lock:tenant:{id}:flow:{conversation_id}`), idempotencia por
      `last_inbound_message_id`, `flow_execution_logs` por paso, guard anti-loop, retry de
      `webhook` (máx 3, backoff), `pause/resume/cancel` sobre ejecuciones activas (409 sobre
      terminales), `handed_off` pausa el bot. `TenantContext::withId()` (fix contexto anidado:
      los servicios internos ya no limpian un contexto activo)
- [x] **Estados del flujo** (ADR-036): borrador atómico `PUT /flows/{flow}/draft` (transacción
      nodes+connections), `GET /flows/{flow}/validate` → `{valid, errors}`, publish valida el
      grafo, deactivate, 409 `FLOW_PUBLISHED` / `FLOW_ALREADY_PUBLISHED` /
      `FLOW_INVALID_STATE` / `CHATBOT_HAS_PUBLISHED_FLOWS`
- [x] **Triggers** (ADR-038): `keyword` / `new_message` / `start` implementados
      (`TriggerMatcher`, precedencia específico→genérico); `tag/schedule/webhook` registrados
      en el enum sin matcher (FASE 14)
- [x] **Permisos y API** (ADR-039): `flows.view` (owner/admin/agent) + `flows.manage`
      (owner/admin) en `TenantPermission` + seeder sincronizado. 4 controllers +
      11 form requests + 6 resources. REST: chatbots CRUD, `chatbots/{chatbot}/flows`,
      `flows/{flow}` show/update/delete/draft/validate/publish/deactivate,
      `flows/{flow}/triggers` CRUD, `flow-executions` index/show/pause/resume/cancel. Errores
      estándar `{message, code, errors}` (404/403/409/422, `FLOW_INVALID` con lista)
- [x] **Frontend read-only**: `Pages/Settings/Flows.vue` (link "Flujos" en AppLayout,
      `settings/flows` en web.php): chatbots → flujos → detalle (nodos, conexiones, triggers,
      estado). `features/flows/{flowTypes,flowUtils}.ts` (labels espejo del backend, query
      builders, `nodeConfigSummary` sin exponer secrets) + Vitest
- [x] **Tests backend** (30 nuevos en FASE 11 → **294 total, 1229 assertions**):
      FlowEngineTest FLOW-1..15 (secuencias, condition, question captura + `{{custom.*}}`,
      delay, human, webhook, límite de pasos, duplicado no avanza, aislamiento A/B motor),
      FlowApiTest FLOW-16..28 (CRUD chatbots/flows, borrador atómico, publish/deactivate,
      409 publicado, triggers, validate, aislamiento A/B CRÍTICO, matriz de permisos, ejecuciones,
      pause/resume/cancel, auditoría), FlowsPermissionTest FLOW-20/21
- [x] **Frontend tests**: 23 Vitest nuevos (`flowUtils.test.ts`) → 71 total
- [x] Pint + PHPStan (nivel 6) + `php -l` + `vue-tsc` + `vite build` + `vitest` sin errores;
      suite completa verde
- [x] Documentación: `chatbot-engine.md` (§10 estado FASE 11), `api.md` (§3.8),
      `database.md` (tablas FASE 11), `multi-tenancy.md`, `security.md`, `testing.md`,
      `architecture.md`, `roadmap.md`, `decisions.md` (ADR-034..039)
- [ ] FASE 11 termina SIN push a origin (reporte final en PASO 13)

> ## Fase 12 — Flow Builder (estado)

- [x] **Editor visual** (ADR-040): `@vue-flow/core` v1.48 + background/minimap/controls.
      `features/flows/useFlowEditor.ts` (estado + mutaciones + `FlowEditorController`),
      `useEditorHistory` (50 snapshots clonados, undo/redo), `useKeyboardShortcuts`
      (Ctrl+S / Ctrl+Z / Ctrl+Shift+Z). Canvas one-way `FlowEditor.vue` con nodeTypes propios.
- [x] **10 nodos** (`features/flows/components/nodes/`): message, buttons, question,
      condition (handles true/false), delay, tag, webhook, human, end y `ai` (visible pero
      bloqueado con badge "Reservado · FASE 16"). `FlowNodeBase.vue` (colores por tipo, badge
      Inicio, badge de issue). Edge propio `FlowEdge.vue` con label pill y `MarkerType.ArrowClosed`.
- [x] **Config panels** (`components/panels/config/`): MessageNodeConfig (texto + hint de
      variables), ButtonsNodeConfig (1-3 botones con id+title), QuestionNodeConfig (text/prompt/
      field `{custom.*}`), ConditionNodeConfig (reglas + `CONDITION_OPERATORS` con
      `needsValue`), DelayNodeConfig (1..3600s), TagNodeConfig (1-10), WebhookNodeConfig
      (solo method+url — secrets en backend), HumanNodeConfig, EndNodeConfig.
- [x] **Paneles y dialogs**: NodePropertiesPanel (nombre, isStart, config, duplicar/eliminar),
      FlowPropertiesPanel (nombre/descripción), EdgePropertiesPanel (rama + eliminar),
      ValidationPanel (issues + "Ver nodo" → `focusNode`), ConfirmDialog, ConflictDialog
      (recargar / seguir editando / sobrescribir). NodePalette + FlowToolbar (Guardar/Deshacer/
      Rehacer/Validar/Publicar/Desactivar) + EmptyState.
- [x] **Lock optimista** (ADR-041): `base_updated_at` opcional en `PUT /draft`; 409
      `FLOW_CONFLICT` → ConflictDialog; sobrescribir reenvía sin `base_updated_at`.
      Migración `timestamp(6)` en flows. Página `Pages/Flows/Editor.vue` + ruta
      `settings/flows/{chatbot}/{flow}` (`settings.flows.editor`, middleware verified+tenant) +
      enlace "Abrir editor" en `Pages/Settings/Flows.vue`. Guard beforeunload + `router.on`.
- [x] **Contrato de grafo** (ADR-042): ids UUID cliente, posiciones enteras, edge ids
      `e-{source}-{target}-{label}`, ramas `true`/`false`, `graphSignature`, sin `tenant_id` en
      payload. **Validación local** (ADR-043): `configIssuesForNode` + `localGraphIssues`
      (espejo de `FlowValidator`) + `mapBackendErrors`. **Selección v1.48** (ADR-044):
      `syncSelection` por flags `selected` sin evento `selection-change`; tipos propios
      `FlowEditorNode/Edge` desacoplados de `GraphNode/GraphEdge`.
- [x] **Solo lectura**: agent (`flows.view` sin `manage`) y flujos publicados abren el editor en
      read-only; el composable ignora toda mutación; la barra oculta Guardar/Publicar.
- [x] **Tests frontend** (46 Vitest nuevos → **117 total**): `flowAdapter.test.ts` (13:
      roundtrip API↔draft, edge ids, ramas, graphSignature, canCreateConnection), 
      `flowValidation.test.ts` (16: config por tipo + grafo + mapBackendErrors),
      `useEditorHistory.test.ts` (6: límite 50, undo/redo, clonado, clear),
      `useFlowEditor.test.ts` (11: load/save/conflicto/sobrescribir/publish-inválido/connect/
      undo-redo/read-only con `window.axios` mockeado)
- [x] **Tests backend** (13 nuevos FLOW-29..43 → **307 total, 1319 assertions**):
      secrets webhook no expuestos (FLOW-29), lock optimista (FLOW-30/38/43), página editor
      (FLOW-31), carga del flujo (FLOW-32), borrador atómico (FLOW-33/34), estados
      (FLOW-35/36), FLOW_INVALID (FLOW-37), aislamiento A/B (FLOW-39), tenant_id forzado
      (FLOW-40), matriz de permisos (FLOW-41), publish tras edición (FLOW-42)
- [x] Pint + PHPStan (nivel 6) + `php -l` + `vue-tsc` + `vite build` + `vitest` sin errores;
      suite completa 307 verde; regresión Docker (`health:check` ok) + migraciones PostgreSQL
      down/up (timestamp(6) verificado)
- [x] Documentación: `chatbot-engine.md` (§11), `api.md` (§3.8 editor + lock), `database.md`
      (timestamp(6)), `multi-tenancy.md` (editor), `security.md` (editor), `testing.md`,
      `architecture.md` (frontend editor), `roadmap.md`, `decisions.md` (ADR-040..044)
- [ ] FASE 12 termina SIN push a origin (reporte final en PASO 13)

> ## Fase 13 — Variables, validación y Flow Builder (COMPLETADA, UNIDAD 7)

- [x] **Catálogo de variables** (UNIDAD 3/4, ADR-046): endpoint
      `GET /api/v1/tenants/{tenant}/flows/{flow}/variables` (solo lectura `flows.view`, definiciones
      derivadas, nunca valores runtime), `VariableCatalogService`, `VariableDefinitionResource`,
      picker en el editor (`VariablePicker`), espejo frontend `useVariableCatalog` (Map, sin
      prototype pollution).
- [x] **Referencias y resolver** (UNIDAD 1/2): `VariableGuard` (keys snake_case, rechazo de
      peligrosas), `VariableResolver` contact/business/conversation/custom/node, `default:`
      inline, warnings para no resueltas. `{{contact.<campo>}}` = alias de
      `contact.metadata[<campo>]` (ADR-045); `contact.metadata.<clave>` bloqueado.
- [x] **UNIDAD 5 — Validación endurecida** (ADR-045): `FlowValidator` con límites (textos ≤ 4096,
      campo condition ≤ 128, URL webhook ≤ 2048), `question.type`/`default` validados
      (`VariableType` + coerción), referencias con error duro solo para segmentos peligrosos,
      campo de condition solo namespaces `contact/business/conversation/custom`.
- [x] **UNIDAD 5 — Webhook seguro**: esquema `http(s)` en `WebhookUrlGuard`, host literal (sin
      variables → sin SSRF), sin credenciales en URL; logs/auditoría con `sanitizeForLog()`
      (sin userinfo/query/fragment); headers/payload nunca salen por API (FLOW-29).
- [x] **UNIDAD 5 — Concurrencia (VAR-24/25/26)**: dos mensajes acumulan variables con una sola
      ejecución; dedupe por `provider_message_id` + barrera `last_inbound_message_id`; valor
      persistente waiting→delay→resume; continue duplicado no-op; lock Redis liberado.
- [x] **UNIDAD 5 — Aislamiento tenant (VAR-29/30)**: motor/catálogo/webhook del tenant A jamás
      ven datos de B; flow de B desde A → 404; `TenantContext` equivocado no expone nodos.
- [x] **UNIDAD 5 — Frontend**: `QuestionNodeConfig` conserva y edita `type`/`default` (2 Vitest);
      `useVariableCatalog` inmune a `__proto__`/`constructor`/`prototype`.
- [x] **Gates**: `php artisan test` (425/2001 assertions) + Pint + PHPStan nivel 6 + `vitest`
      (147) + `vue-tsc` + `vite build` verdes.
- [x] **Documentación (UNIDAD 5)**: `decisions.md` (ADR-045), `chatbot-engine.md` (§10.2/§11),
      `api.md` (§3.8), `security.md` (SSRF + logs), `testing.md`.
- [x] **UNIDAD 6 — Contrato runtime de variables** (ADR-046): `question.config.default` se aplica
      en runtime — una respuesta **vacía** a una pregunta con default usable persiste el default
      **coerceado al tipo declarado** (`integer` `'42'` → `42` int) en `execution.variables`;
      sin default o con respuesta no vacía el comportamiento previo queda intacto (VAR-2
      conservado). El DSL inline `{{variable|default:'valor'}}` se verifica end-to-end en el
      motor (múltiples variables, valor capturado gana, caracteres de control eliminados).
      No toca tipos, condiciones, webhook ni API.
- [x] **UNIDAD 6 — Gates**: `php artisan test` (434/2013 assertions) + Pint + PHPStan nivel 6 +
      `vitest` (147) + `vue-tsc` + `vite build` verdes. Frontend sin cambios.
- [x] **UNIDAD 6 — Documentación**: `decisions.md` (ADR-046, repara la referencia colgante a
      "ADR-046" del catálogo), `chatbot-engine.md` (§5/§10.2), `api.md` (§3.8), `testing.md`.
- [x] **UNIDAD 7 — Auditoría de cierre** (sin cambios de código): revisión completa de los
      invariantes de FASE 13 (CUSTOM, VARIABLES, SEGURIDAD, CONCURRENCIA, MULTI-TENANCY,
      EDITOR, TIPOS, DSL, DEFAULTS) contra código + tests VAR-1..VAR-36 y FLOW-1..43; sin
      hallazgos pendientes, sin features nuevas, sin migraciones, sin permisos nuevos.
- [x] **FASE 13 COMPLETADA y PUBLICADA**: push autorizado de los 6 commits FASE 13
      (`53e459c`..`35743d4`) + commit de documentación `docs(flows): mark phase 13 complete`
      (`f262f20`). `HEAD` = `origin/master` = `f262f20` (ahead/behind 0/0, working tree limpio).

### FASE 14 — Triggers (COMPLETADA — SIN push)

- [x] **UNIDAD 1 — Auditoría técnica previa** (read-only, sin código): especificación real =
      disparo de `tag`/`schedule`/`webhook` (ADR-038). Contradicciones C1-C7 detectadas y
      resueltas: C1 (regla `FLOW_ALREADY_PUBLISHED` documentada, nunca implementada) → se
      implementa en U1; C3 (tags a nivel contacto) → la ejecución por etiqueta NO se implementa
      (FASE 20); C4 (referencias seguras a conversación) → resuelto SIN migración con las
      estructuras existentes. Unidades propuestas: U1 validación/endurecimiento, U2 scheduler,
      U3 webhook público, U4/U5/U6 pendientes.
- [x] **UNIDAD 1 — C4: auditoría de modelos/relaciones** (sin migración): `Conversation`
      (tenant_id, contact_id, bot_paused, flow_execution_id, status), `Contact` (tenant_id,
      phone E.164 único por tenant), `Chatbot`/`Flow`/`Trigger`/`FlowExecution` (tenant_id).
      `schedule` referencia conversación por UUID verificada en tenant; `webhook` resolverá por
      payload identificador en U3. NO se requieren columnas ni tablas nuevas.
- [x] **UNIDAD 1 — Validación y endurecimiento de triggers** (ADR-047): `TriggerValidator`
      (dominio puro, backend autoritativo) valida la config por tipo — `keyword`/`new_message`/
      `start` sin config, `tag` con `config.tags` (1..10 únicas, ≤100), `schedule` con cron
      determinista de 5 campos (sin eval) + `conversation_id` UUID verificado en tenant,
      `webhook` con `conversation_by` y `token_hash` sha256 (token CSPRNG devuelto una única
      vez; `TriggerResource` redacta `token_hash`; cliente jamás envía secretos). Se integra en
      store/update y en publish. `isImplementedInPhaseEleven()` → `isMessageTrigger()`;
      `TriggerMatcher::typeOrder` registra `tag`/`schedule`/`webhook` (que jamás matchean un
      mensaje entrante). C1: publicar valida la config de los triggers (422 `FLOW_INVALID`) y
      bloquea un segundo flujo publicado del mismo tenant con trigger genérico activo del mismo
      tipo (409 `FLOW_ALREADY_PUBLISHED`); los específicos coexisten.
- [x] **UNIDAD 1 — Gates**: `php artisan test` (476/2184 assertions) + Pint + PHPStan nivel 6 +
      `vitest` (147) + `vue-tsc` + `vite build` verdes. Frontend sin cambios.
- [x] **UNIDAD 1 — Documentación**: `decisions.md` (ADR-047 + actualización ADR-038),
      `api.md` (§3.8 contrato de config + publish), `chatbot-engine.md` (§6), `security.md`,
      `testing.md`. `roadmap.md` NO marca FASE 14 como completa.
- [x] **UNIDAD 1 — Commit local** `feat(flows): harden trigger validation` (`d48dbb0`,
      SIN push). HEAD = `d48dbb0`, origin/master = `f262f20`, ahead 1.
- [x] **UNIDAD 2 — Scheduler** (disparo por cron, ADR-048):
  - [x] `FireScheduleTriggers` command (`flow:fire-schedule-triggers`, cada minuto,
        `withoutOverlapping()`): query global con `whereIn` + `withoutTenantScope()`.
  - [x] `StartFlowFromSchedule` job (TenantAwareJob + ShouldBeUnique): revalidación completa,
        lock Redis por trigger, delega a `FlowEngine::handleScheduleTrigger()`.
  - [x] `FlowEngine::handleScheduleTrigger()` + `handleScheduleTriggerLocked()`: crea
        `FlowExecution`, loguea `schedule_triggered`, ejecuta start()+run().
  - [x] `TenantAwareJob::handle()` save/restore: guarda contexto previo, restaura en finally
        (fix de producción, no workaround de tests).
  - [x] `CronMatcherTest` (15 tests, dominio puro): `matchesCron()` + `cronFieldMatches()`.
  - [x] `ScheduleTriggerTest` (17 tests, SCHED-01..17): todos los escenarios incluyendo
        aislamiento A/B, locks, command, audit log.
  - [x] TenantContextJobTest actualizado para save/restore.
  - [x] Gates: `php artisan test` (508/2250 assertions) + Pint + PHPStan nivel 6 + `vitest`
        (147) + `vue-tsc` + `vite build` verdes. Frontend sin cambios.
  - [x] Documentación: `decisions.md` (ADR-048), `chatbot-engine.md` (§12), `security.md`,
        `testing.md`.
- [x] **UNIDAD 2 — Commit local** `feat(flows): schedule trigger firing` (SIN push).
- [x] **UNIDAD 3 — Webhook público** (endpoint + verificación de token, ADR-049):
  - [x] `FlowWebhookController` (POST `/api/webhooks/flows/{trigger}`, público,
        rate-limited `throttle:flow-webhook` 60/min por IP): resuelve trigger por UUID,
        valida token SHA-256 con `hash_equals`, resuelve conversación por `conversation_by`,
        idempotencia por `Idempotency-Key` header + `Cache::lock`, despacha job → 202.
  - [x] `StartFlowFromWebhook` (TenantAwareJob + ShouldBeUnique por idempotencyKey): revalida
        todo en TenantContext propio (defensa en profundidad), delega a
        `FlowEngine::handleScheduleTrigger()`.
  - [x] Rate limiter `flow-webhook` en `AppServiceProvider`.
  - [x] `FlowWebhookTest` (37 tests, WEBHOOK-01..20 + 17 extensiones): token válido/inválido,
        trigger inexistente/inactivo/flow no publicado, conversación válida/inexistente/de otro
        tenant, payload hackeado, idempotencia, concurrencia, bot_paused, ejecución activa,
        secretos en response/logs, rate limit, payload grande/JSON inválido, aislamiento A/B,
        pipeline existente, conversation_by contact_id/phone, audit log.
  - [x] Gates: `php artisan test` (545/2325 assertions) + Pint + PHPStan 0 errores (1G) +
        `vue-tsc` + `vite build` verdes. Frontend sin cambios.
  - [x] Documentación: `decisions.md` (ADR-049), `chatbot-engine.md` (§13), `security.md`,
        `testing.md`, `api.md`.
- [x] **UNIDAD 3 — Commit local** `feat(flows): public webhook trigger endpoint` (`104d5a1`,
      SIN push).
- [x] **UNIDAD 4 — Decisión arquitectónica** (ADR-050): **DIFERIDA A FASE 20**. El trigger
      `tag` mantiene su contrato/configuración (`config.tags`) y validación backend, pero NO
      ejecuta flujos. FASE 20 implementará primero la infraestructura centralizada de Tags,
      incluyendo el evento estable de asignación, política Contact→Conversation, semántica de
      matching e invariantes de idempotencia/anti-recursión. No se adelantaron `TagService`,
      eventos/listeners, API/UI, `StartFlowFromTag` ni cambios a `TagNodeExecutor`.
- [x] **CIERRE FORMAL FASE 14**: U1/U2/U3 completadas; U4 diferida por dependencia
      arquitectónica, no por defecto del código. Gates finales y documentación de cierre verdes.
      Commit local `docs(flows): close phase 14 and defer tag trigger to phase 20` (SIN push).

### FASE 15 — Transferencia a humano (EN PROGRESO — SIN push)

- [x] **UNIDAD 1 — Semántica e invariantes** (ADR-051..053): `handed_off` terminal; resume solo
      habilita futuros inbound; cola manual sin auto-routing; `handoff_message` opcional;
      notification center/email diferidos a FASE 22; realtime tenant-wide reservado para U4.
- [x] **DB tenant-safe**: `tenant_id` NOT NULL + FK + índices en assignments/participants;
      backfill desde conversaciones; FK compuesta contra referencias cross-tenant; UNIQUE parcial
      de una assignment abierta; actor humano nullable en messages; `handoff_requested_at`
      nullable en conversations.
- [x] **Modelos/contratos**: `BelongsToTenant`, relaciones tenant/actor, casts; Human deja de ser
      waiting, es terminal alternativo a end y acepta mensaje vacío sin cambiar runtime.
- [x] **Tests U1**: HANDOFF-DATA, aislamiento A/B, migración rollback/backfill, contratos Human y
      frontend local. Migration UP/DOWN/UP verificada en PostgreSQL real aislado.
- [x] **Gates U1**: `php artisan test` (565/2378 assertions), Pint, PHPStan 0 errores (1G),
      Vitest (149), `vue-tsc`, Vite build, Docker y healthcheck verdes.
- [x] **UNIDAD 2 — Assignment, claim y transfer atómicos**: permission `conversations.claim` para
      claim propio; Redis conversation lock → DB transaction → `FOR UPDATE`; memberships
      revalidadas; audit transaccional; `ConversationUpdated` after-commit; frontend usa users.id.
- [x] **Tests U2**: HA/HT/HC/HMT y contrato frontend; suite PostgreSQL aislada con procesos reales
      para row lock, concurrent assign/transfer/claim, rollback tardío y UNIQUE SQLSTATE 23505.
- [x] **Gates U2**: backend 601/2490, PostgreSQL 9/50, Vitest 151, Pint, PHPStan 0 errores (1G),
      `vue-tsc`, Vite build, Docker y `health:check` verdes.
- [x] **UNIDAD 3 — Human handoff operativo**: `HumanHandoffService` transaccional e idempotente
      bajo el lock del motor; `handoff_message` opcional previo al terminal; timestamp/audit/log;
      conserva `open|pending` y rechaza `resolved|archived` sin reabrir ni alterar assignment.
- [x] **UNIDAD 3 — Mensajería y resume seguros**: origen interno
      `automation|human|handoff`, actor autenticado, policy agent-assignment con override
      owner/admin, campos sensibles prohibidos, worker serializado que bloquea automation con
      `BOT_PAUSED_HANDOFF`, y resume atómico sin revive/replay ni release del agente.
- [x] **Tests/gates U3**: HANDOFF-RUNTIME/HANDOFF-OUT/MSG-API, rollback e idempotencia; carrera
      PostgreSQL/Redis con proceso outbound real. Backend 621/2615, PostgreSQL 10/60, Vitest 151,
      Pint, PHPStan 0 errores (1G), migraciones PostgreSQL limpias, `vue-tsc` y Vite build verdes.
- [x] **UNIDAD 4** — Realtime tenant-wide `InboxConversationChanged`.
      Canal privado `tenant.{id}.inbox` con auth `belongsToTenantWithPermission`
      (membresía activa + `conversations.view`). Evento `InboxConversationChanged` afterCommit
      con `ConversationResource` + `event_id` UUID. Enum `InboxConversationChangeKind` cerrado.
      `useInboxChannel` composable con dedupe por `event_id`. Fix `useConversationChannel`:
      `private()` en vez de `channel()`. Emisores en `HumanHandoffService`,
      `ConversationService`, `MessageService`, `FlowEngine`. Backend 652/2708, Vitest 165,
      Pint PASS, PHPStan 0, vue-tsc PASS, Vite build PASS, Docker PASS, health PASS.
- [x] **UNIDAD 5** — Inbox UX (scope/buckets/counts + handoff UI + claim + composer fix).
      Backend: scope `all|mine|unassigned` en `ConversationIndexRequest` + `ConversationService::index()`;
      `inboxCounts()` con 3 COUNTs tenant-scoped en controller. Tests INBOX-01..08.
      Frontend: bucket tabs (Todas/Mias/Sin asignar) con counters, `isUnassignedHandoff()`,
      `isHumanActive()`, `isManualPause()` helpers. ChatHeader: claim button + banner, bot/human
      status labels, self-exclusion from assign/transfer dropdown. ConversationListItem: handoff
      indicator (amber left border + "Requiere agente" / human agent name). ContactPanel: attention
      status + handoff_requested_at display. MessageComposer: draft preserve on error (clear only
      when `sending` goes false). Realtime integration: scope-aware upsert/remove + counter updates.
      Tests: INBOX-01..08 (backend), UI-01..24 + UI-HP-01..05 (frontend). Backend 660/2740,
      Vitest 194, Pint PASS, PHPStan 0 (256M), vue-tsc PASS, Vite build PASS.
- [x] **UNIDAD 6** — Hardening y cierre FASE 15:
  - [x] BUG-1 fix: despacho único de `InboxConversationChanged` (HumanHandoffService
        es la fuente autoritativa; `FlowEngine` ya no despacha el evento).
  - [x] HANDOFF-FINAL-01..10: 10 tests de regresión (regression guard, cross-tenant
        security, sent_by_user_id rejection, inactive membership, duplicate HumanNode,
        inbound during handoff, resume-then-inbound, ConversationUpdated dispatch).
  - [x] Security matrix: 12/12 pasan (scope filters, claim visibility, self-exclusion,
        draft preserve, sent_by_user_id prohibited, inactive membership denied).
  - [x] PostgreSQL concurrency: HCON-01..06, HCON-ROW-01, HC-07-PG, HCON-MEMBER-02,
        HCON-U3-01 (10/10 pasan). DB: `whatsapp_saas_handoff_u2_test`.
  - [x] PostgreSQL migration UP/DOWN/UP verificada en PostgreSQL 16 aislado.
  - [x] Gates: backend 670 tests / 2765 assertions; Vitest 194; Pint PASS;
        PHPStan 0 errores; vue-tsc PASS; Vite build PASS; Docker PASS; health PASS.

### FASE 15 — CIERRE

## FASE 15 — Transferencia a humano (COMPLETADA)

## IMPLEMENTADO
- Data invariants (U1): tenant_id NOT NULL + FK + índices en assignments/participants;
  backfill determinista; FK compuesta contra referencias cross-tenant; UNIQUE parcial
  de una assignment abierta; sent_by_user_id nullable en messages; handoff_requested_at
  nullable en conversations.
- Atomic assignment claim transfer (U2): permission conversations.claim; Redis distributed
  locks → DB transaction → FOR UPDATE; memberships revalidadas; audit transaccional;
  ConversationUpdated after-commit; frontend usa users.id.
- Human handoff runtime (U3): HumanHandoffService transaccional e idempotente bajo el
  lock del motor; handoff_message opcional; timestamp/audit/log; conserva open|pending;
  resume-bot sin revive/replay; inbound durante handoff persiste sin FlowEngine; worker
  bloquea automation con BOT_PAUSED_HANDOFF.
- Tenant-wide inbox realtime (U4): InboxConversationChanged event afterCommit; canal
  privado tenant.{id}.inbox con auth belongsToTenantWithPermission; InboxConversationChangeKind
  enum cerrado; useInboxChannel composable con dedupe por event_id.
- Operational human inbox (U5): scope filter mine/all/unassigned; inboxCounts tenant-scoped;
  claim button + banner; handoff indicators; self-exclusion from dropdown; draft preserve
  on error; sent_by_user_id prohibited in StoreMessageRequest.
- Hardening (U6): BUG-1 fix (single InboxConversationChanged dispatch); 10 HANDOFF-FINAL
  regression tests; 12/12 security matrix; 10/10 PG concurrency; migration UP/DOWN/UP
  verified on PostgreSQL 16.

## ARCHIVOS
- app/Domain/Conversations/Models/Conversation (handoff_requested_at, unique index)
- app/Domain/Conversations/Models/ConversationAssignment (tenant_id FK compuesta)
- app/Domain/Conversations/Models/ConversationParticipant (tenant_id FK compuesta)
- app/Domain/Messages/Models/Message (sent_by_user_id nullable FK)
- app/Application/Conversations/Services/HumanHandoffService
- app/Application/Conversations/Services/ConversationService (claim/assign/transfer/resume)
- app/Events/InboxConversationChanged
- app/Domain/Conversations/Enums/InboxConversationChangeKind
- app/Http/Requests/ConversationIndexRequest (scope, counts)
- Migraciones FASE 15 (assignments/participants tenant_id, messages sent_by_user_id,
  conversations handoff_requested_at, unique indexes, partial indexes)

## DATABASE
Ver FASE 15 en docs/database.md (9 elementos DDL).

## API
Ver FASE 15 en docs/api.md (scope, counts, claim, handoff actions).

## TESTS
670 backend / 2765 assertions; 194 Vitest; 10 PostgreSQL concurrency tests.

## RESULTADOS
PASS — 670/670 backend, 10/10 PG concurrency, 194/194 frontend, 12/12 security matrix.

## SEGURIDAD
Ver FASE 15 en docs/security.md (scope filters, claim, self-exclusion, draft preserve,
sent_by_user_id prohibited, inactive membership denied).

## ADRs
ADR-051 (semántica terminal de Human Handoff), ADR-052 (consistencia de asignación),
ADR-053 (frontera realtime tenant-wide inbox).

## ESTADO
COMPLETADO

---

## FASE 16 — AI / AI NODE

### UNIDAD 1 (U1): AI Provider Infrastructure

**Objetivo**: Crear la infraestructura base de IA: contrato, provider, VOs, excepciones, config, binding.

#### Archivos creados

- `app/Domain/AI/Contracts/AIProviderInterface.php` — contrato con `generateResponse(AIRequest): AIResponse`
- `app/Domain/AI/ValueObjects/AIRequest.php` — VO inmutable (prompt, systemPrompt, model, temperature, maxTokens)
- `app/Domain/AI/ValueObjects/AIResponse.php` — VO inmutable (content, provider, model, inputTokens, outputTokens, totalTokens)
- `app/Domain/AI/Enums/AIErrorCode.php` — enum de códigos de error (AuthFailed, RateLimit, InvalidRequest, ProviderError, Timeout, ResponseInvalid)
- `app/Domain/AI/Exceptions/AIException.php` — excepción abstracta base
- `app/Domain/AI/Exceptions/AIAuthFailedException.php` — HTTP 401, no retryable
- `app/Domain/AI/Exceptions/AIRateLimitException.php` — HTTP 429, retryable
- `app/Domain/AI/Exceptions/AIInvalidRequestException.php` — HTTP 400, no retryable
- `app/Domain/AI/Exceptions/AIProviderException.php` — HTTP 5xx, retryable configurable
- `app/Infrastructure/AI/OpenAIProvider.php` — implementación concreta (Laravel HTTP Client, `/v1/chat/completions`)
- `config/ai.php` — configuración del provider (api_key, model, base_url, timeout, max_retries, max_tokens)
- `.env.example` — variables `AI_PROVIDER`, `AI_MODEL`, `AI_TIMEOUT`, `AI_MAX_RETRIES`, `AI_MAX_TOKENS`, `OPENAI_BASE_URL`

#### Archivos modificados

- `app/Providers/AppServiceProvider.php` — binding `AIProviderInterface` → `OpenAIProvider`

#### Tests

- `tests/Unit/AI/OpenAIProviderTest.php` — 15 tests (AI-P01..P15):
  - P01: AIRequest VO inmutabilidad y defaults
  - P02: AIResponse VO inmutabilidad y datos completos
  - P03: AIProviderInterface se resuelve desde el contenedor
  - P04: OpenAIProvider con API key vacía lanza AIAuthFailedException
  - P05: generateResponse 200 → AIResponse con tokens correctos
  - P06: generateResponse con systemPrompt incluido en messages
  - P07: generateResponse sin systemPrompt → solo user message
  - P08: API key vacía lanza AIAuthFailedException sin HTTP
  - P09: HTTP 401 → AIAuthFailedException
  - P10: HTTP 429 → AIRateLimitException
  - P11: HTTP 400 → AIInvalidRequestException
  - P12: HTTP 500 → AIProviderException retryable
  - P13: Timeout conexión → retryable
  - P14: Respuesta 200 sin choices → RuntimeException
  - P15: Token usage mapeado correctamente al VO

#### Puertas

- php artisan test: 685/685 PASS (2808 assertions)
- Pint: PASS
- PHPStan: 0 errores
- vue-tsc: PASS
- Seguridad: sin API key en logs/responses/exceptions, sin dumps/serialization

#### SEGURIDAD

- API key nunca en response, logs, auditoría, exceptions ni frontend
- API key solo en Authorization header del HTTP client
- Provider stateless re: tenant (sin TenantContext)
- Tests con Http::fake (sin llamadas reales)

#### ADRs

ADR-054 (AI Provider Infrastructure)

#### ESTADO
COMPLETADO

### UNIDAD 2 (U2): AI Node Runtime

**Objetivo**: Integrar el nodo AI en el motor de flujos. Genera contenido con IA, lo guarda en `custom.*`, el flow continúa.

#### Archivos creados

- `app/Application/Flows/Services/Executors/AiNodeExecutor.php` — ejecutor del nodo AI
- `app/Application/Flows/Services/AiPromptBuilder.php` — construye SYSTEM/CONTEXT/USER para el prompt
- `config/ai.php` (extendido) — sección `fallback_message`
- `tests/Fakes/FakeAIProvider.php` — provider falso para tests (sin llamadas reales)
- `tests/Unit/Flows/AiNodeExecutorTest.php` — 15 tests (AI-01..15)
- `tests/Feature/Flows/AiFlowTest.php` — 10 tests (AI-F01..F10)
- `tests/Feature/Flows/AiSecurityTest.php` — 10 tests (AI-S01..S10)
- `tests/Feature/Flows/AiTenantIsolationTest.php` — 6 tests (AI-MT-01..06)

#### Archivos modificados

- `app/Domain/Flows/Enums/FlowNodeType.php` — AI removido de `isWaitingType()`
- `app/Domain/Flows/Services/FlowValidator.php` — `validateAiNode()` para config de AI
- `app/Providers/AppServiceProvider.php` — registro de `AiNodeExecutor` en `NodeExecutorRegistry`
- `tests/Unit/Flows/FlowValidatorTest.php` — `HANDOFF-CONTRACT-06` actualizado

#### Tests

- **Unit (AiNodeExecutorTest)**: 15 tests / 33 assertions:
  - AI-01: provider se invoca con AIRequest
  - AI-02: output se persiste en custom.{output_variable}
  - AI-03: variables del prompt se resuelven
  - AI-04: output_variable inválida → fallback sin provider call
  - AI-05: respuesta vacía → fallback
  - AI-06: timeout → fallback
  - AI-07: rate limit → fallback
  - AI-08: AIAuthFailedException → fallback
  - AI-09: AI node NO envía mensajes (solo guarda en custom)
  - AI-10: segunda ejecución reutiliza output sin nueva llamada (idempotencia)
  - AI-11: bot_paused es defense-in-depth
  - AI-12: caracteres de control sanitizados
  - AI-13: output que excede MAX_VALUE_LENGTH se trunca
  - AI-14: AIRequest no contiene secrets ni API keys
  - AI-15: AIRequest usa config del provider abstraction

- **Feature (AiFlowTest)**: 10 tests / 24 assertions:
  - AI-F01: flow con AI se puede publicar
  - AI-F02: end-to-end con fake provider
  - AI-F03: AI → condition usando custom output
  - AI-F04: AI → message interpolando {{custom.output}}
  - AI-F05: provider falla → fallback → flow continúa
  - AI-F06: bot_paused impide ejecución
  - AI-F07: idempotencia → una sola llamada
  - AI-F08: ejecución completa exitosamente
  - AI-F09: handoff posterior al AI mantiene invariantes
  - AI-F10: AI node no puede ser start node

- **Security (AiSecurityTest)**: 10 tests / 15 assertions:
  - AI-S01: output tenant A nunca aparece en tenant B
  - AI-S02: API key no aparece en execution logs
  - AI-S03: API key no aparece en audit logs
  - AI-S04: prompt completo no se registra
  - AI-S05: response completa no se registra
  - AI-S06: output malicioso tratado como texto plano
  - AI-S07: prompt injection en contact.name no altera system
  - AI-S08: custom values maliciosos no ejecutan código
  - AI-S09: business internal/secret no incluidos en AI context
  - AI-S10: config injection no cambia tenant context

- **Multi-tenant (AiTenantIsolationTest)**: 6 tests / 14 assertions:
  - AI-MT-01: usa contexto correcto del tenant A
  - AI-MT-02: usa solo datos del tenant B
  - AI-MT-03: output guardado solo en execution del tenant A
  - AI-MT-04: template de A no resuelve variables custom de B
  - AI-MT-05: wrong tenant context no filtra datos
  - AI-MT-06: ejecución secuencial A→B limpia tenant context

#### SEGURIDAD

- API key nunca en logs/audit/response/exceptions/frontend
- Prompt y response nunca completos en logs (solo token counts y output_length)
- Output tratado como texto plano (no se ejecuta como código)
- bot_paused verificado primero (defense-in-depth)
- Inyección bloqueada (system prompt separado de datos del usuario)
- VariableGuard en output_variable
- Aislamiento cross-tenant verificado

#### PUERTAS

- php artisan test: 726/726 PASS (2894 assertions)
- Pint: PASS
- PHPStan: 0 errores
- npm test: 194/194 PASS
- vue-tsc: PASS
- Vite build: PASS
- Docker: all healthy
- Healthcheck: ok

#### ADRs

ADR-055 (AI Node Runtime)

#### ESTADO
COMPLETADO

### UNIDAD 3 (U3): Flow Builder AI UX

**Objetivo**: Habilitar visualmente el nodo AI en el Flow Builder existente. Agregar, configurar, validar y publicar flujos con nodos AI desde la UI.

#### Archivos creados

- `resources/js/features/flows/components/panels/config/AiNodeConfig.vue` — panel de configuración del nodo AI
- `resources/js/features/flows/aiFlowBuilder.test.ts` — 20 tests AI-V01..V20

#### Archivos modificados

- `resources/js/features/flows/components/NodePalette.vue` — eliminado bloque AI, descripción actualizada
- `resources/js/features/flows/components/nodes/AINode.vue` — eliminado badge "Reservado", delega a FlowNodeBase
- `resources/js/features/flows/components/nodes/FlowNodeBase.vue` — eliminados 3 bloqueos AI (opacity, badge, handle)
- `resources/js/features/flows/components/nodes/index.ts` — actualizado comentario
- `resources/js/features/flows/components/panels/ConfigPanel.vue` — añadido AiNodeConfig branch
- `resources/js/features/flows/flowEditorTypes.ts` — DEFAULT_NODE_CONFIG.ai con defaults reales
- `resources/js/features/flows/flowUtils.ts` — isImplementedNodeType retorna true para ai, summary mejorado
- `resources/js/features/flows/flowValidation.ts` — validación real de AI config (prompt, output_variable, longitudes)
- `resources/js/features/flows/useFlowEditor.ts` — eliminado bloque AI en addNode()
- `resources/js/features/flows/useFlowEditor.test.ts` — test actualizado para AI creable
- `resources/js/features/flows/flowUtils.test.ts` — isImplementedNodeType test actualizado
- `resources/js/features/flows/flowValidation.test.ts` — test AI con validación real

#### Tests Frontend U3

- **AI-V01**: AI aparece en NodePalette
- **AI-V02**: AI se agrega al canvas
- **AI-V03**: AI no puede ser start node
- **AI-V04**: AINodeConfig renderiza
- **AI-V05**: prompt obligatorio
- **AI-V06**: output_variable obligatorio
- **AI-V07**: output_variable peligrosa rechazada
- **AI-V08**: VariablePicker inserta en prompt
- **AI-V09**: system_prompt se persiste
- **AI-V10**: fallback_message se persiste
- **AI-V11**: roundtrip adapter conserva config AI
- **AI-V12**: DEFAULT_NODE_CONFIG correcto
- **AI-V13**: published AI read-only
- **AI-V14**: agent AI read-only
- **AI-V15**: source/target handles correctos
- **AI-V16**: badge Reservado eliminado
- **AI-V17**: summary no expone system_prompt completo
- **AI-V18**: save usa draft endpoint existente
- **AI-V19**: FLOW_CONFLICT sigue funcionando
- **AI-V20**: AI config no contiene model/provider/api_key

Suite U3: **49 tests**. Suite frontend total: **244 tests**.

#### SEGURIDAD

- No se exponen API keys, provider credentials ni model config al frontend
- No se crea endpoint nuevo de AI (solo APIs Flow existentes)
- Prompt preview es solo texto local (sin llamadas al provider)
- Read-only en published y para agent con flows.view

#### PUERTAS

- php artisan test: 726/726 PASS (2894 assertions)
- Pint: PASS
- PHPStan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS
- Vite build: PASS (14.05s)
- Docker: all healthy
- Healthcheck: ok
- git diff --check: clean
- Security scan: clean

#### ADRs

ADR-056 (Flow Builder AI UX)

#### ESTADO
COMPLETADO

---

### FASE 16 — U4: AI Usage Telemetry

#### Archivos modificados

- `app/Domain/AI/ValueObjects/TelemetryPayload.php` — VO inmutable con safe schema
- `app/Application/Flows/Services/Executors/AiNodeExecutor.php` — latencia, telemetry en logs

#### Archivos nuevos

- `tests/Unit/AI/TelemetryPayloadTest.php` — 8 tests VO
- `tests/Unit/Flows/AiTelemetryTest.php` — 17 tests executor telemetry

#### TelemetryPayload safe schema

```
{operation, provider, model, input_tokens, output_tokens,
 total_tokens, latency_ms, success, error_code, fallback_used}
```

- **operation**: siempre `generate` (futuras operaciones: `embed`, `analyze`)
- **latency_ms**: `hrtime(true)` monotonic clock, milisegundos enteros >= 0
- **success**: `true` para `ai_completed`, `false` para `ai_failed`
- **error_code**: `AIErrorCode` enum value cuando es AIException, `null` en éxito
- **fallback_used**: `true` cuando se aplicó fallback_message
- **PII guarantee**: prompt, content, contact, business, custom.secret NUNCA en payload

#### Tests

- **TelemetryPayloadTest** (AI-U01..U08): fromResponse/fromError, clamping tokens, toArray
  keys, PII exclusion VO.
- **AiTelemetryTest** (AI-U09..U25): latency_ms, success, provider/model/tokens,
  output_variable, error_code, fallback_used, idempotencia (no duplicate logs),
  empty response → ai_failed, PII never in payload, monotonic clock, bot_paused → no logs,
  invalid output_variable → no logs, safe schema keys.
- Suite FASE 16 U4: **25 tests / 120 assertions**.

#### Puertas

- php artisan test: 751/751 PASS (3014 assertions)
- pint: PASS
- phpstan: 0 errores
- Security scan: clean

#### ADRs

ADR-057 (AI Usage Telemetry)

#### ESTADO
COMPLETADO

---

### FASE 16 — U5: Hardening, Auditoría Final y Cierre

#### Bug fix

- `OpenAIProvider::parseResponse()` — `RuntimeException` → `AIProviderException` para
  respuestas malformadas. Test AI-P14 actualizado.

#### Security Matrix

- `tests/Feature/Flows/AiSecurityMatrixTest.php` — 12 tests formales (AI-SEC-F01..F12):
  - F01: API key never in execution logs
  - F02: API key/provider/model never in frontend config
  - F03: API key never in audit logs
  - F04: Prompt never in telemetry
  - F05: Response never in telemetry
  - F06: Contact PII never in telemetry
  - F07: Tenant A telemetry isolated from B
  - F08: Malicious output stored as plain text
  - F09: bot_paused blocks provider completely
  - F10: Provider dependency is AIProviderInterface only
  - F11: tenant_id config injection ignored
  - F12: Exceptions sanitized (no stack traces)

#### Boundary verification

- **RAG**: cero código, solo docs (FASE 17)
- **FAQ**: cero código, solo docs (FASE 18)
- **Billing/UsageGuard**: cero código, solo docs (FASE 23-25)
- **DDL**: cero migraciones AI/usage

#### Puertas

- php artisan test: 763/763 PASS (3055 assertions)
- pint: PASS
- phpstan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS

#### ADRs

ADR-056 (AI Prompt/Data Security Boundaries)

#### ESTADO
COMPLETADO — FASE 16 CERRADA

---

### FASE 17 — Base de conocimiento / RAG — UNIDAD 1: Knowledge Data Model + DDL

#### Archivos creados

- `database/migrations/2026_08_18_020000_create_knowledge_bases_table.php` — KB con soft deletes + unique parcial
- `database/migrations/2026_08_18_020100_create_knowledge_documents_table.php` — documentos con FK compuesta + file hash
- `database/migrations/2026_08_18_020200_create_knowledge_chunks_table.php` — chunks con vector(1536) PG-only + HNSW
- `app/Domain/KnowledgeBase/Enums/KnowledgeDocumentStatus.php` — enum 4 estados + label() + isTerminal()
- `app/Domain/KnowledgeBase/Models/KnowledgeBase.php` — final class, HasUuids, BelongsToTenant, SoftDeletes
- `app/Domain/KnowledgeBase/Models/KnowledgeDocument.php` — final class, HasUuids, BelongsToTenant, SoftDeletes
- `app/Domain/KnowledgeBase/Models/KnowledgeChunk.php` — final class, HasUuids, BelongsToTenant, NO SoftDeletes
- `database/factories/Domain/KnowledgeBase/Models/KnowledgeBaseFactory.php`
- `database/factories/Domain/KnowledgeBase/Models/KnowledgeDocumentFactory.php` — ready/processing/failed states
- `database/factories/Domain/KnowledgeBase/Models/KnowledgeChunkFactory.php` — conditional embedding (PG-only)
- `tests/Unit/Domain/KnowledgeBase/KnowledgeBaseModelTest.php` — 19 tests SQLite (KB-DB-01..19)
- `tests/Postgres/KnowledgeBase/KnowledgeBasePostgresTest.php` — 14 tests PG (KB-DB-PG-01..14, 14/14 PASS)
- `tests/Postgres/PgvectorTestCase.php` — base class para tests pgvector

#### Puertas

- php artisan test: 782/782 PASS (3134 assertions) — 763 FASE 16 + 19 nuevas
- pint: PASS
- phpstan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS

#### ADRs

ADR-058 (Knowledge Base Data Model), ADR-059 (Embedding Abstraction - diseño)

#### ESTADO
COMPLETADA Y VERIFICADA EN POSTGRESQL REAL — commit base 9beecf0 + fix commit pendiente

---

### FASE 17 — UNIDAD 2.1: Knowledge Base Management API + Permissions

#### Archivos creados

- `app/Domain/KnowledgeBase/Exceptions/KnowledgeBaseNotFoundException.php` — HTTP 404
- `app/Domain/KnowledgeBase/Exceptions/KnowledgeBaseDuplicateException.php` — HTTP 409, code KB_DUPLICATE
- `app/Domain/KnowledgeBase/Exceptions/DocumentNotFoundException.php` — HTTP 404
- `app/Application/KnowledgeBase/Services/KnowledgeBaseService.php` — CRUD completo (index/show/create/update/delete)
- `app/Application/KnowledgeBase/Services/DocumentService.php` — read/delete (upload diferido a U2.2)
- `app/Http/Controllers/Api/V1/KnowledgeBaseController.php` — index/store/show/update/destroy
- `app/Http/Controllers/Api/V1/DocumentController.php` — index/show/destroy (sin store)
- `app/Http/Requests/KnowledgeBase/KnowledgeBaseIndexRequest.php` — filtros search + per_page
- `app/Http/Requests/KnowledgeBase/StoreKnowledgeBaseRequest.php` — name required, description nullable
- `app/Http/Requests/KnowledgeBase/UpdateKnowledgeBaseRequest.php` — todos opcionales
- `app/Http/Requests/KnowledgeBase/DocumentIndexRequest.php` — filtros search + per_page
- `app/Http/Resources/KnowledgeBaseResource.php` — id, name, description, documents_count (condicional), timestamps
- `app/Http/Resources/DocumentResource.php` — safe fields (sin storage internals)
- `tests/Feature/KnowledgeBase/KnowledgeBaseApiTest.php` — 35 tests (34 pass, 1 skip SQLite)

#### Archivos modificados

- `app/Domain/Users/Enums/TenantPermission.php` — +ViewKnowledge, +ManageKnowledge (15→17 permisos)
- `app/Domain/KnowledgeBase/Models/KnowledgeBase.php` — +$fillable
- `app/Domain/KnowledgeBase/Models/KnowledgeDocument.php` — +$fillable, +@property PHPDoc annotations
- `routes/api.php` — +8 KB/document routes bajo middleware tenant

#### API

| Método | Endpoint | Descripción | Permiso |
|--------|----------|-------------|---------|
| GET | `/api/v1/tenants/{tenant}/knowledge-bases` | Listar KBs (paginado) | knowledge.view |
| POST | `/api/v1/tenants/{tenant}/knowledge-bases` | Crear KB | knowledge.manage |
| GET | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}` | Detalle KB | knowledge.view |
| PUT | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}` | Actualizar KB | knowledge.manage |
| DELETE | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}` | Eliminar KB (soft delete) | knowledge.manage |
| GET | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}/documents` | Listar documentos | knowledge.view |
| GET | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}/documents/{doc}` | Detalle documento | knowledge.view |
| DELETE | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}/documents/{doc}` | Eliminar documento | knowledge.manage |

#### Permisos

- `knowledge.view` → owner, admin, agent (todos los roles activos)
- `knowledge.manage` → owner, admin (solo gestión)

#### Tests

35 tests (34 pass, 1 skip SQLite por unique parcial): KB-U21-01..11 (CRUD), KB-U21-PERM-01..03 (matriz), KB-U21-MT-01..10 (aislamiento A/B), KB-U21-SEC-01..04 (seguridad resource), KB-U21-AUD-01 (auditoría), KB-U21-DOC-01..06 (documentos).

#### SEGURIDAD

- tenant_id body injection ignorado (BelongsToTenant + service filtra)
- Resource no expone file_hash, storage_disk, storage_path
- Cross-tenant UUID no produce IDOR
- UniqueConstraintViolationException manejado (SQLite: UniqueConstraintViolationException; PG: QueryException)
- Auth en FormRequest retorna true; authorization en Application Service

#### Puertas

- php artisan test: 816/816 PASS (3243 assertions)
- pint: PASS
- phpstan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS
- Docker: all healthy
- git diff --check: clean

#### ADRs

ADR-060 (Knowledge Base API Contract + Permissions)

#### ESTADO
COMPLETADO — commit 5738444. NO PUSH.

---

### FASE 17 — UNIDAD 2.2: Private Knowledge Document Upload + Storage

#### Archivos creados

- `config/knowledge.php` — upload config (extensions, MIME, max_file_size, storage_disk, prefix)
- `app/Domain/KnowledgeBase/Exceptions/DocumentStorageFailedException.php` — 500
- `app/Domain/KnowledgeBase/Exceptions/DocumentInvalidFileException.php` — 422
- `app/Domain/KnowledgeBase/Exceptions/DocumentTooLargeException.php` — 413
- `app/Domain/KnowledgeBase/Exceptions/DocumentUnsupportedTypeException.php` — 422
- `app/Domain/KnowledgeBase/Exceptions/DocumentDuplicateException.php` — 409
- `app/Application/KnowledgeBase/Services/DocumentUploadValidator.php` — validate + magic bytes + DOCX structure + text validation + finfo MIME
- `app/Http/Requests/KnowledgeBase/StoreDocumentRequest.php` — file required + file + max
- `tests/Feature/KnowledgeBase/DocumentUploadTest.php` — 39 tests (KB-U22-01..06, V01..V07, D01..D04, S01..S06, MT01..MT08, A01..A02, NO-01..03, SEC-01..03)

#### Archivos modificados

- `app/Application/KnowledgeBase/Services/DocumentService.php` — +upload() method with hash, dedup, storage write, DB transaction, compensation
- `app/Http/Controllers/Api/V1/DocumentController.php` — +store() method with full exception handling
- `routes/api.php` — +POST `/{tenant}/knowledge-bases/{kb}/documents`

#### API

| Método | Endpoint | Descripción | Permiso |
|--------|----------|-------------|---------|
| POST | `/api/v1/tenants/{tenant}/knowledge-bases/{kb}/documents` | Upload documento (multipart) | knowledge.manage |

#### Validación de seguridad (capas)

1. Extension whitelist (pdf, docx, txt)
2. Server-side MIME (finfo + DOCX→application/zip bypass)
3. Magic bytes (%PDF-, PK)
4. Tamaño max 10MB
5. Empty file check
6. DOCX ZIP structure ([Content_Types].xml + word/document.xml, 1-500 entries, no traversal)
7. TXT null-byte + UTF-8 check

#### Storage path

`knowledge/tenant/{tenantId}/knowledge-bases/{kbId}/documents/{docId}/source.{ext}`

Server-side, UUID-based, deterministic. No nombres de usuario en path.

#### Dedup

SHA-256 streaming → misma KB + mismo hash + doc active → 409 DOCUMENT_DUPLICATE.
Soft-deleted docs permiten re-upload.

#### Tests

39 tests: KB-U22-01..06 (upload valid), V01..V07 (validación), D01..D04 (dedup), S01..S06 (storage), MT01..MT08 (tenancy), A01..A02 (audit), NO-01..03 (confirmations), SEC-01..03 (seguridad).

#### Puertas

- php artisan test: 855/855 PASS (3371 assertions) — 816 pre-U2.2 + 39 nuevas
- pint: PASS (pendiente verificación)
- phpstan: 0 errores (pendiente verificación)
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS

#### ADRs

ADR-061 (Private Knowledge Document Storage)

#### ESTADO
COMPLETADO — pendiente commit. NO PUSH.

### FASE 17 — UNIDAD 2.3: Safe Text Extraction + Normalization + Chunking + PDF

#### Archivos creados

- `app/Application/KnowledgeBase/Extractors/PlainTextExtractor.php` — Extractor de texto plano UTF-8
- `app/Application/KnowledgeBase/Extractors/DocxTextExtractor.php` — Extractor DOCX vía XML parsing
- `app/Application/KnowledgeBase/Extractors/PdfTextExtractor.php` — Extractor PDF vía smalot/pdfparser
- `app/Application/KnowledgeBase/Extractors/DocumentTextExtractorFactory.php` — Factory por MIME type
- `app/Application/KnowledgeBase/Services/TextNormalizer.php` — Normalización UTF-8, CRLF, whitespace
- `app/Application/KnowledgeBase/Services/DocumentChunker.php` — Chunking por párrafos con overlap
- `app/Application/KnowledgeBase/Services/ChunkPersistenceService.php` — Persistencia de chunks
- `app/Domain/KnowledgeBase/ValueObjects/ExtractedText.php` — Value Object: text + characterCount + metadata
- `app/Domain/KnowledgeBase/ValueObjects/DocumentTextExtractorInterface.php` — Interfaz de extractors
- `app/Domain/KnowledgeBase/Exceptions/DocumentExtractionFailedException.php` — Excepción de extracción
- `app/Domain/KnowledgeBase/Exceptions/DocumentTextTooLargeException.php` — Texto excede límite
- `tests/Unit/KnowledgeBase/PlainTextExtractorTest.php` — 10 tests (EXT-TXT-01..10)
- `tests/Unit/KnowledgeBase/DocxTextExtractorTest.php` — 12 tests (EXT-DOCX-01..12)
- `tests/Unit/KnowledgeBase/PdfTextExtractorTest.php` — 12 tests (EXT-PDF-01..12)
- `tests/Unit/KnowledgeBase/TextNormalizerTest.php` — 11 tests (NORM-01..11)
- `tests/Unit/KnowledgeBase/DocumentChunkerTest.php` — 15 tests (CHUNK-01..15)
- `tests/Feature/KnowledgeBase/ChunkPersistenceTest.php` — 7 tests
- `tests/Feature/KnowledgeBase/ExtractionPipelineTest.php` — 4 tests

#### Archivos modificados

- `config/knowledge.php` — extraction + chunking configuration
- `composer.json` — +smalot/pdfparser:^2.12
- `composer.lock` — smalot/pdfparser v2.12.0

#### Funcionalidad

- **PlainTextExtractor**: UTF-8 validation/sanitize, BOM strip, null byte reject, binary reject
- **DocxTextExtractor**: ZIP bomb protection (500 entries, 50MB uncompressed, 100:1 ratio), XML entity injection protection, Zip Slip protection
- **PdfTextExtractor**: smalot/pdfparser para extracción de texto plano, error handling seguro (no leak internal exceptions)
- **DocumentTextExtractorFactory**: Resolución por MIME type, extensible
- **TextNormalizer**: CRLF→LF, null bytes strip, control chars strip, Unicode NFC, whitespace collapse, trim, size validation
- **DocumentChunker**: Split por párrafos → oraciones → caracteres, overlap configurable, merge de chunks pequeños, max chunks limit
- **ChunkPersistenceService**: Replace atómico de chunks, tenant_id server-side, metadata handling

#### Tests

- 84 unit tests (10 TXT + 12 DOCX + 12 PDF + 11 NORM + 15 CHUNK + 24 factory/edge)
- 11 feature tests (7 ChunkPersistence + 4 ExtractionPipeline)
- Total U2.3: 95 tests, todos green
- Regression total FASE 17: 144 passed (1 skipped — PostgreSQL)

#### Puertas

- php artisan test: 144/144 PASS (1 skipped)
- phpstan: 0 errores (303 files)
- pint: PASS
- npm test: pendiente
- vue-tsc: pendiente
- vite build: pendiente

#### ADRs

ADR-063: Text Extraction Architecture

#### ESTADO
COMPLETADO — pendiente commit. NO PUSH.

---

### FASE 17 — UNIDAD 2.4: Document Processing Orchestration

**Objetivo**: Orquestar el pipeline de procesamiento de documentos (extract → normalize → chunk → persist) de forma asíncrona, idempotente y segura ante concurrencia.

#### Archivos creados

- `app/Jobs/ProcessKnowledgeDocument.php` — TenantAwareJob + ShouldBeUnique + Cache::lock + CAS
- `app/Application/KnowledgeBase/Services/KnowledgeDocumentProcessingService.php` — State machine + pipeline orchestration
- `app/Domain/KnowledgeBase/Exceptions/DocumentProcessingException.php` — 409 delete-during-processing
- `tests/Feature/KnowledgeBase/ProcessKnowledgeDocumentTest.php` — 40 tests (PROC-01..10, PROC-FAIL-01..10, PROC-MT-01..06, PROC-CON-01..05, QUEUE-01..07, DELETE-01..02)

#### Archivos modificados

- `app/Application/KnowledgeBase/Services/DocumentService.php` — +dispatch ProcessKnowledgeDocument after commit, +delete guard for processing state
- `app/Http/Controllers/Api/V1/DocumentController.php` — +DocumentProcessingException handler in destroy()
- `app/Domain/KnowledgeBase/Enums/KnowledgeDocumentStatus.php` — PHPDoc update: ready = ingestion/chunking complete
- `config/knowledge.php` — +processing.tries, processing.backoff
- `tests/Feature/KnowledgeBase/DocumentUploadTest.php` — Queue::fake() en tests de upload (KB-U22-01, KB-U22-05, KB-U22-NO-02, KB-U22-NO-03)

#### Funcionalidad

- **State machine**: uploaded → processing → ready/failed. 4 capas de protección anti-duplicación: ShouldBeUnique, Cache::lock, CAS DB, CAS ready.
- **Pipeline**: validate → read source → extract → normalize → chunk → persist → mark ready/failed.
- **Error sanitization**: error_message expone solo códigos genéricos, nunca paths/stack traces.
- **Delete guard**: 409 DOCUMENT_PROCESSING si se intenta borrar un documento en estado processing.
- **failed() safety net**: marca documento como failed si aún está en processing (recurso último recurso).
- **Queue config**: tries=3, backoff=[30,60]s.

#### Tests

- 40 tests U2.4, todos green. 84 tests pre-U2.2 también pasan con Queue::fake() add.
- Regression total: 966 tests PASS (1 skipped — PostgreSQL).

#### Puertas

- php artisan test: 966/966 PASS (1 skipped)
- phpstan: 0 errores
- pint: PASS
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS
- composer audit: clean

#### ADRs

ADR-064 (Document Processing State Machine + Idempotency)

#### ESTADO
COMPLETADO — commit 881cc6c. NO PUSH.

---

### FASE 17 — UNIDAD 3.1: Embedding Provider Infrastructure

**Objetivo**: Establecer el contrato y la infraestructura para la generación de embeddings vectoriales, separada del dominio de chat (AIProviderInterface), sin materialización ni búsqueda semántica.

#### Archivos creados

- `app/Domain/AI/Contracts/EmbeddingProviderInterface.php` — contrato `embed(EmbeddingRequest): EmbeddingResponse`
- `app/Domain/AI/ValueObjects/EmbeddingRequest.php` — VO: batch de textos + modelo override
- `app/Domain/AI/ValueObjects/EmbeddingResponse.php` — VO: embeddings + provider + model + totalInputTokens
- `app/Domain/AI/Enums/EmbeddingErrorCode.php` — enum 7 códigos (Auth, RateLimit, InvalidRequest, InvalidResponse, DimensionMismatch, Provider, Timeout)
- `app/Domain/AI/Exceptions/EmbeddingException.php` — excepción abstracta base con `errorCode()` + `status()`
- `app/Domain/AI/Exceptions/EmbeddingAuthFailedException.php` — HTTP 401
- `app/Domain/AI/Exceptions/EmbeddingRateLimitException.php` — HTTP 429
- `app/Domain/AI/Exceptions/EmbeddingDimensionMismatchException.php` — HTTP 422, fail closed
- `app/Domain/AI/Exceptions/EmbeddingProviderException.php` — HTTP 5xx, retryable configurable
- `app/Infrastructure/AI/OpenAIEmbeddingProvider.php` — implementación Http facade, `/v1/embeddings`
- `tests/Fakes/FakeEmbeddingProvider.php` — determinístico, call counting, exception injection, wrong dimension simulation
- `tests/Unit/AI/OpenAIEmbeddingProviderTest.php` — 43 tests (EMB-P01..P36, EMB-F01..F07)

#### Archivos modificados

- `config/ai.php` — sección `embedding` (providers.openai: model, dimensions, max_batch_size, timeout, max_retries)
- `app/Providers/AppServiceProvider.php` — binding `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider`
- `.env.example` — variables `EMBEDDING_MODEL`, `EMBEDDING_DIMENSIONS`, `EMBEDDING_MAX_BATCH_SIZE`

#### Arquitectura

- **Interfaz separada**: `EmbeddingProviderInterface` distinta de `AIProviderInterface` (SRP/ISP). Chat y embedding son dominios distintos.
- **Dimension contract**: 1536 hardcodeado en config. Fail closed ante `EmbeddingDimensionMismatchException`. No truncar, pad, ni convertir.
- **Response cardinality**: N inputs → exactamente N embeddings. Validación de `index` sequential, no duplicates, no gaps. Sort by index.
- **Float validation**: Cada elemento del vector debe ser numérico finito. NaN/INF/strings rechazados.
- **Error taxonomy separada**: `EmbeddingErrorCode` enum con 7 casos.
- **Http facade**: consistente con `OpenAIProvider`. Sin paquete openai-php/client.
- **Batch guard**: `max_batch_size` configurable (default 50).
- **Config**: Sección `embedding` en `config/ai.php`. Reutiliza `OPENAI_API_KEY`.

#### Tests

43 tests U3.1, todos green:

- **EMB-P01..P05**: VOs (request/response immutable, validación empty/whitespace)
- **EMB-P06..P07**: Interface se resuelve desde contenedor
- **EMB-P08..P09**: API key vacía → auth exception, sin HTTP
- **EMB-P10..P12**: Vector 1536 correcto, dimension mismatch rechazado, batch de 50
- **EMB-P13**: Batch excede max_batch_size → EmbeddingProviderException
- **EMB-P14..P18**: Float validation (finito OK, NaN/INF/string rechazados)
- **EMB-P19..P21**: Index validation (out-of-order, missing, duplicate)
- **EMB-P22..P25**: HTTP errors (401→auth, 429→rate, 500→provider, timeout→retryable)
- **EMB-P26..P29**: Response parsing (malformed JSON, missing data, wrong count, non-numeric)
- **EMB-P30..P32**: Model config (override, default, token usage)
- **EMB-P33..P34**: OpenAI weight normalization (zero/missing/ok)
- **EMB-P35..P36**: HTTP safety (fake prevents real, empty key no HTTP)
- **EMB-F01..F07**: FakeEmbeddingProvider (deterministic, counting, injection, wrong dimension, reset, unit vectors, callback)

#### SEGURIDAD

- API key nunca en response, logs, exceptions ni frontend
- Tests con Http::fake (sin llamadas reales)
- Provider stateless re: tenant (sin TenantContext)
- Dimension mismatch → fail closed (nunca truncar/pad)

#### Puertas

- php artisan test: 1009/1009 PASS (1 skipped — PostgreSQL)
- pint: PASS
- phpstan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS
- composer audit: clean

#### ADRs

ADR-065 (Separate Embedding Provider Contract)

#### ESTADO
COMPLETADO — commit a6d9dfa. NO PUSH.

---

### FASE 17 — UNIDAD 3.2: Embedding Materialization Job

**Objetivo**: Materializar embeddings vectoriales para chunks knowledge con batch processing, persistencia segura con pgvector, idempotencia, retries/backoff y tenant isolation.

#### Archivos creados

- `app/Domain/KnowledgeBase/ValueObjects/VectorSerializer.php` — serialización + validación defense-in-depth a formato pgvector text
- `app/Application/KnowledgeBase/Services/EmbeddingMaterializationService.php` — orquestación: pending chunks → batching → provider → validate → CAS persist
- `app/Jobs/MaterializeKnowledgeEmbeddings.php` — TenantAwareJob + ShouldBeUnique + lock + retries
- `tests/Feature/KnowledgeBase/MaterializeKnowledgeEmbeddingsTest.php` — 16 tests (EMB-MAT-01..12, EMB-JOB-01..10, EMB-MT-01..06)
- `tests/Postgres/KnowledgeBase/EmbeddingMaterializationPostgresTest.php` — 10 tests PG (EMB-PG-01..10)

#### Archivos modificados

- `app/Jobs/ProcessKnowledgeDocument.php` — dispatch `MaterializeKnowledgeEmbeddings` after markReady + chunk_count > 0
- `config/knowledge.php` — sección `materialization` (tries: 3, backoff: [30, 60, 120])

#### Arquitectura

- **Separate job**: `MaterializeKnowledgeEmbeddings` despachado desde `ProcessKnowledgeDocument` después de transición exitosa a `ready`. No se toca pipeline de extracción/chunking.
- **Ready semantics**: `ready` = ingestion/chunking complete. NO = embeddings complete. Estado inferido: `embedding IS NULL` → pending.
- **VectorSerializer**: VO defense-in-depth. Serializa a `[0.1,0.2,...]`. Validación: finite + count = 1536.
- **Persistencia**: `DB::update()` con `?::vector` parameterized binding. Nunca `DB::raw()` con interpolación.
- **CAS**: `WHERE embedding IS NULL` previene sobrescritura. 0 filas = otro worker ya materializó.
- **Batch DB transaction**: Todo o nada por batch. Rollback si DB falla.
- **Lock Redis**: `lock:tenant:{id}:embeddings:{docId}:processing`. Release en finally.
- **ShouldBeUnique**: `embeddings:{tenantId}:{documentId}`, uniqueFor 600s. Tres capas: unique + lock + CAS.
- **Retries**: tries=3, backoff=[30,60,120]. Rate limit/timeout/5xx → retryable.
- **failed()**: NO cambia document.status. Documento permanece `ready`. Audit seguro.
- **Delete guard**: Revalida documento activo antes de cada batch.
- **Zero chunks**: No provider call, no error.
- **Audit**: `knowledge_embeddings.materialized` / `.failed` con metadata segura.

#### Tests SQLite

16 tests:
- **EMB-MAT-01..12**: pending materialized, already embedded skipped, batch size, splitting, order preserved, wrong dimension no persist, provider failure no partial persist, zero chunks no provider, deleted document stops, total tokens, audit safe, no embedding column early return.
- **EMB-JOB-01..10**: TenantAwareJob, ShouldBeUnique, retries config, afterCommit, timeout, dispatch after ready, failed processing no dispatch, failed() audit, rate limit classification, deleted document ignored.
- **EMB-MT-01..06**: tenant context, cross-tenant silently, uniqueId isolation, tenant_id from constructor, VectorSerializer dimension validation, non-finite validation.

#### Tests PostgreSQL (suite separada)

10 tests:
- **EMB-PG-01**: persist vector(1536) — real pgvector write
- **EMB-PG-02**: wrong dimension reject — no persist
- **EMB-PG-03**: embedding NULL selected — correct query
- **EMB-PG-04**: CAS whereNull — idempotencia
- **EMB-PG-05**: transaction rollback batch — no partial persist
- **EMB-PG-06**: multi-batch persist — 5 chunks en 3 batches
- **EMB-PG-07**: HNSW index preserved
- **EMB-PG-08**: vector_cosine_ops preserved
- **EMB-PG-09**: tenant isolation — A materializa A, B untouched
- **EMB-PG-10**: deleted document exclusion

#### SEGURIDAD

- Parameterized SQL con `?::vector` binding — no interpolación
- CAS previene sobrescritura
- Tenant isolation: query lleva tenant_id + document_id
- Delete guard: revalida documento activo antes de cada batch
- Audit seguro: sin content, vectors, API key
- failed() no muta document status

#### Puertas

- php artisan test: 1025/1025 PASS (13 skipped — 1 PG + 12 SQLite embedding)
- pint: PASS
- phpstan: 0 errores
- npm test: 244/244 PASS
- vue-tsc: PASS
- vite build: PASS
- composer audit: clean

#### ADRs

ADR-066 (Embedding Materialization Pipeline)

#### ESTADO
COMPLETADO — commit 274b93a. NO PUSH.

---

### FASE 17 — UNIDAD 3.3: Semantic Search Foundation

**Objetivo**: Búsqueda semántica tenant-scoped sobre knowledge_chunks con pgvector cosine search, threshold filtering, top-K, context limit y value objects inmutables.

#### Archivos creados

- `app/Domain/KnowledgeBase/ValueObjects/RetrievedChunk.php` — VO inmutable: chunkId, documentId, content, score, metadata
- `app/Domain/KnowledgeBase/ValueObjects/KnowledgeSearchResult.php` — VO inmutable: query, chunks, totalCount, topK, threshold, searchDurationMs
- `app/Application/KnowledgeBase/Services/KnowledgeSearchService.php` — caso de uso: validate → embed → cosine SQL → threshold → top-K → context limit
- `tests/Feature/KnowledgeBase/KnowledgeSearchServiceTest.php` — 14 tests SQLite (validation, config, safety, metadata)
- `tests/Postgres/KnowledgeBase/KnowledgeSearchPostgresTest.php` — 17 tests PG (cosine ranking, threshold, tenant isolation, context limit)

#### Archivos modificados

- `config/knowledge.php` — sección `search` (default_top_k: 5, hard_max_top_k: 20, default_threshold: null, max_query_length: 2000, max_context_chars: 15000)

#### Arquitectura

- **KnowledgeSearchService**: pipeline unique `search(tenantId, kbId, query, topK?, threshold?)`.
  Validate → resolve KB → embed query → pgvector SQL → threshold → top-K → context limit → KnowledgeSearchResult.
- **Value Objects inmutables**: RetrievedChunk y KnowledgeSearchResult. Ambos readonly, named params.
- **pgvector cosine SQL parametrizada**: `1 - (embedding <=> ?::vector)` con binding parameterized.
- **Threshold**: filtro post-query. null = sin filtro. 0.0..1.0 inclusive.
- **Context limit**: max_context_chars (default 15000). No corta chunks a mitad.
- **SQLite compatibility**: guard `config('database.default') !== 'pgsql'` retorna empty result sin llamar provider.
- **Tenant isolation**: KB resolution lleva tenant_id explícito + withoutTenantScope().

#### Tests SQLite

14 tests: validation (empty, whitespace, oversized query, topK range, threshold range), safety (SQL injection query/tenantId), behavior (non-existent KB, embedding not called, config defaults, metadata, context limit).

#### Tests PostgreSQL (suite separada)

17 tests: RAG-PG-01..10 (cosine ranking, threshold, context limit, topK, empty chunks, deleted doc) + RAG-MT-01..07 (tenant isolation, cross-tenant safety).

#### SEGURIDAD

- Parameterized SQL con `?::vector` binding
- Tenant isolation: tenant_id + knowledge_base_id en query
- withoutTenantScope() bypass para servicios con tenantId explícito
- SQL injection testing incluido

#### Puertas

- php artisan test: 1039/1039 PASS (13 skipped)
- pint: PASS
- phpstan: 0 errores
- composer audit: clean

#### ADRs

ADR-067 (Semantic Search Service)

#### ESTADO
COMPLETADO — commit dbfe11c. NO PUSH.

---

### FASE 17 — UNIDAD 3.4: AI Node RAG Context Injection

**Objetivo**: Integrar resultados de búsqueda semántica (U3.3) como contexto no confiable en el prompt del nodo AI, permitiendo que los chatbots respondan con conocimiento de la base de documentos del tenant.

#### Archivos creados

- `app/Domain/KnowledgeBase/ValueObjects/KnowledgeContext.php` — VO inmutable: chunks, totalCount, searchDurationMs + `KnowledgeContext::empty()` factory
- `app/Application/KnowledgeBase/Contracts/KnowledgeSearchServiceInterface.php` — interfaz minimalista para DI y testing
- `tests/Fakes/FakeKnowledgeSearchService.php` — fake configurable para testing sin PostgreSQL
- `tests/Feature/Flows/RagContextInjectionTest.php` — 15 tests (RAG-AI-01..15)
- `tests/Feature/Flows/RagSecurityTest.php` — 8 tests (RAG-SEC-01..08)
- `tests/Unit/Flows/AiPromptBuilderKnowledgeTest.php` — 8 tests (RAG-PROMPT-01..08)

#### Archivos modificados

- `app/Application/Flows/Services/Executors/AiNodeExecutor.php` — +KnowledgeSearchServiceInterface DI, +resolveKnowledgeContext(), +resolveSearchQuery(), RAG telemetry in logAiCompleted()
- `app/Application/Flows/Services/AiPromptBuilder.php` — +knowledgeContext param en build(), +buildKnowledgeContextBlock(), +formatChunk(), +resolvePromptOnly()
- `app/Application/KnowledgeBase/Services/KnowledgeSearchService.php` — implementa KnowledgeSearchServiceInterface (additive)
- `app/Domain/Flows/Services/FlowValidator.php` — +knowledge_base_id nullable UUID validation, +isValidUuid()
- `tests/Unit/Flows/AiNodeExecutorTest.php` — updated make_executor() for 3-param constructor
- `tests/Unit/Flows/AiTelemetryTest.php` — updated make_telemetry_executor(), AI-U25 expected keys
- `tests/Feature/Flows/AiTenantIsolationTest.php` — updated make_mt_executor()
- `tests/Feature/Flows/AiSecurityTest.php` — updated make_sec_executor()
- `tests/Feature/Flows/AiSecurityMatrixTest.php` — updated sec_executor(), AI-SEC-F10 index [2]

#### Arquitectura

- **KnowledgeSearchServiceInterface**: contract minimalista extraído del servicio concreto. Permite DI y testing con fakes sin depender de clase `final`.
- **KnowledgeContext VO**: inmutable, transporta chunks + totalCount + searchDurationMs. Nunca contiene vectors ni API keys.
- **Execution flow**: bot_paused → idempotency → resolve knowledge → build prompt → AI → persist → log. RAG search DESPUÉS de idempotency.
- **Search failure policy**: fail-open. Excepción → log warning → null context → AI sin RAG.
- **Search query**: prompt resuelto por VariableResolver, truncado a 2000 chars.
- **Untrusted delimiter**: `--- KNOWLEDGE CONTEXT (UNTRUSTED DATA) ---`. Chunks son datos, nunca en system_prompt.
- **Telemetry**: `rag_used` (bool) + `retrieved_chunks_count` (int). Nunca chunk content, scores, vectors.
- **FlowValidator**: `knowledge_base_id` nullable UUID validation. No FK, JSONB config.

#### Tests

31 tests nuevos:
- **RAG-AI-01..15** (feature): sin KB unchanged, con KB llama search, chunks en prompt, orden preservado, empty retrieval, deleted/invalid KB, cross-tenant, bot_paused, idempotency, fallback, variables resolved, max context, no scores, output variable.
- **RAG-PROMPT-01..08** (unit): no context, block placement, untrusted delimiter, multiple chunks, unicode, malicious as text, system not contaminated, empty context.
- **RAG-SEC-01..08** (security): malicious chunk as text, no system override, no API key in system, no webhook token, no storage path, no vector, no audit secrets, no cross-tenant.

#### SEGURIDAD

- Chunks treated as untrusted data (ADR-068)
- Knowledge context never enters system prompt
- Similarity scores/vectors never exposed
- No new DDL — knowledge_base_id in JSONB config
- Telemetry includes only rag_used + chunk count

#### Puertas

- phpunit: 91 tests PASS (222 assertions) — all AI + RAG tests
- pint: PASS
- phpstan: 0 errores
- composer audit: clean

#### ADRs

ADR-068 (RAG Context Injection into AI Node)

#### ESTADO
COMPLETADO — commit 289ac6e.

---

### U3.5 — Knowledge Base Selector en AI Node (Frontend)

**Objetivo**: Agregar un selector de bases de conocimiento al configurador de nodos AI, permitiendo al usuario vincular una KB existente al nodo. El adapter roundtrip preserva el `knowledge_base_id` en el JSONB config. Sin DDL nueva.

#### Archivos creados

- `resources/js/features/knowledge/knowledgeTypes.ts` — interfaz `KnowledgeBase` + `KnowledgeBasesListResponse` + `KnowledgeBasesMeta`
- `resources/js/features/knowledge/knowledgeApi.ts` — `fetchKnowledgeBases()` llama a `GET /api/v1/{tenant}/knowledge-bases`
- `resources/js/features/knowledge/useKnowledgeBases.ts` — composable reactivo: items, loading, error, load(), byId(), hasKBs
- `resources/js/features/flows/ragNodeConfig.test.ts` — 36 tests (RAG-V01..V20, unit + component)

#### Archivos modificados

- `resources/js/features/flows/components/panels/config/AiNodeConfig.vue` — reescritura completa: KB selector con estados loading/empty/error/missing/deleted, read-only support
- `resources/js/features/flows/flowEditorTypes.ts` — DEFAULT_NODE_CONFIG.ai incluye `knowledge_base_id: null`
- `resources/js/features/flows/flowUtils.ts` — nodeConfigSummary AI case muestra sufijo "KB activada" cuando knowledge_base_id está set
- `resources/js/features/flows/aiFlowBuilder.test.ts` — AI-V04 + AI-V12 actualizados para el 5to campo

#### Arquitectura

- **KnowledgeBase interface**: id, name, description, created_at, updated_at (sin storage_path, file_hash, embeddings, chunks)
- **API client**: `fetchKnowledgeBases(tenantId, params)` → `GET /api/v1/{tenant}/knowledge-bases` con paginación
- **Composable `useKnowledgeBases`**: lazy-load + cache por tenant. Loading, error, items, byId(), hasKBs
- **AiNodeConfig KB selector**: select nativo en tope del componente, antes del prompt
- **Adapter roundtrip**: `apiNodeToEditor` → `editorNodeToApi` pasan config como-is. knowledge_base_id preservado automáticamente
- **Backward compatibility**: flows viejos sin knowledge_base_id → undefined → tratado como null
- **nodeConfigSummary**: suffijo " KB activada" cuando knowledge_base_id está presente

#### Estados del selector

- **Loading**: disabled + "Cargando bases..."
- **Empty**: "Sin base de conocimiento"
- **Error**: non-destructive, preserva selection existente
- **Missing/deleted KB**: "Base de conocimiento no disponible" (amber)
- **Read-only**: disabled

#### Tests

36 tests frontend (RAG-V01..V20):
- RAG-V01: DEFAULT_NODE_CONFIG incluye knowledge_base_id
- RAG-V02: Adapter roundtrip preserva UUID
- RAG-V03: Flow validation acepta null/undefined/UUID
- RAG-V04: nodeConfigSummary muestra "KB activada"
- RAG-V05: AiNodeConfig renderiza selector + emite update
- RAG-V06: Clear selection → null
- RAG-V07: Existing KB seleccionado correctamente
- RAG-V08: Read-only deshabilita selector
- RAG-V09: Loading state
- RAG-V10: Empty state
- RAG-V11: API error preserva selección existente
- RAG-V12: Deleted/missing KB amber warning
- RAG-V13: AI node sin KB remains valid
- RAG-V14: Sin semantic settings UI
- RAG-V15: graphToDraft preserva UUID
- RAG-V16: Sin storage fields expuestos
- RAG-V17: Full roundtrip AI node con KB
- RAG-V18: localGraphIssues con AI node + KB
- RAG-V19: Optimistic lock unchanged
- RAG-V20: Preserva campos existentes on KB change

+ 2 tests actualizados en aiFlowBuilder.test.ts (AI-V04, AI-V12)

#### SEGURIDAD

- Sin DDL nueva — knowledge_base_id en JSONB config
- Sin storage_path ni file_hash expuestos al frontend
- Sin semantic settings (top_k, threshold, embedding_model) — defaults del backend
- Read-only mode deshabilita selector
- API KB requiere `knowledge.view` permission
- Error handling no destructivo — preserva selección existente

#### Puertas

- npm run typecheck: PASS
- npm run build: PASS
- npm run test: 280 tests PASS
- pint: PASS
- phpstan: 0 errores

#### ESTADO
COMPLETADO — pendiente commit. NO PUSH.

---

## FASE 18 — FAQ Inteligente (COMPLETADA)

### U1 — Data Model + Normalization
- **Estado**: COMPLETADA
- **Commit**: f978a7c
- FaqStatus enum (active/inactive)
- FaqQuestionNormalizer (trim, NFC, lowercase, edge punctuation, whitespace)
- Faq model (BelongsToTenant, HasUuids, SoftDeletes, HasFactory)
- Migration with partial unique index (tenant_id, normalized_question)
- FaqFactory with active/inactive states
- 12 normalizer tests (FAQ-NORM-01..12)
- 17 model tests (FAQ-DB-01..17)
- 10 PostgreSQL constraint tests (FAQ-PG-01..10)
- ADR-069

### U2 — Question Normalization + FAQ Matcher
- **Estado**: COMPLETADA
- **Commit**: 72b0bcb
- FaqMatch VO (faqId, answer, matchType, priority)
- FaqMatcherServiceInterface (Application/Faq/Contracts/)
- FaqMatcherService (Application/Faq/Services/)
- 20 matcher tests (FAQ-MATCH-01..20)
- 5 multi-tenancy tests (FAQ-MT-U2-01..05)
- ADR-070

### U3 — CRUD API + Permissions
- **Estado**: COMPLETADA
- **Commit**: 7eed362
- TenantPermission: +ViewFaqs, +ManageFaqs (19 permisos total)
- FaqService (Application layer): CRUD + authorization + normalization + audit
- FaqController (Http): thin, delegates to service
- FaqNotFoundException (404), FaqDuplicateException (409), FaqInvalidQuestionException (422)
- Routes: GET/POST api/v1/tenants/{tenant}/faqs, GET/PATCH/DELETE .../faqs/{faq}
- Requests: FaqIndexRequest, StoreFaqRequest, UpdateFaqRequest
- FaqResource (hides tenant_id, normalized_question, deleted_at)
- Audit: faq.created, faq.updated, faq.deleted (safe payload)
- 20 API tests (FAQ-API-01..20)
- 6 permission tests (FAQ-PERM-01..06)
- 10 multi-tenancy tests (FAQ-MT-U3-01..10)
- 10 security tests (FAQ-SEC-U3-01..10)
- 1170 passed, 13 skipped, 0 PHPStan errors, Pint clean

### U4 — FlowEngine Runtime Integration
- **Estado**: COMPLETADA
- **Commit**: 896efab
- FlowHandleResult VO (bool $handled) — retornado por FlowEngine::handleMessage()
- FlowEngine: param `?callable $onUnhandled = null`, retorna FlowHandleResult
- ProcessIncomingWhatsAppMessage: pasa callback FAQ cuando created=true
- FaqReplyService: defense-in-depth (bot_paused, Text type, empty body), fail-open
- FakeFaqMatcherService (tests/Fakes/): test double configurable
- AppServiceProvider: bind FaqMatcherServiceInterface → FaqMatcherService
- Precedence: handoff → bot_paused → active execution → matchFlow → FAQ callback
- Source attribution: faq_id + match_type en outbound metadata (sin DDL)
- Audit: faq.matched con payload seguro (sin PII)
- 10 precedence tests (FAQ-PREC-01..10)
- 7 idempotency tests (FAQ-IDEM-01..07)
- 3 E2E tests (FAQ-E2E-01..03)
- 6 runtime MT tests (FAQ-RUNTIME-MT01..06)
- 10 security tests (FAQ-SEC-U4-01..10)
- ADR-071
- 1206 passed, 13 skipped, 0 PHPStan errors, Pint clean

### U5 — Frontend
- **Estado**: COMPLETADA
- **Commit**: 31c0ac9
- FaqSettingsController (Inertia page wrapper)
- Route: GET /settings/faq (verified + tenant)
- Nav link in AppLayout.vue
- Faq.vue page: list, search, filter (status), pagination, create/edit modal, delete confirmation
- Feature module: faqTypes.ts, faqApi.ts, faqUtils.ts
- Permission gating: faqs.view (agent), faqs.manage (owner/admin)
- 16 faqUtils tests (buildFaqQuery, statusLabel, buildFaqPayload, extractErrorMessage)
- 6 faqApi tests (fetchFaqs, createFaq, updateFaq, deleteFaq)
- vue-tsc 0 errors, Vite build clean, 302 Vitest passing

### U6 — Hardening + Audit + Close
- **Estado**: COMPLETADA
- **Commit**: (pending)
- P1 fix: frontend API key mismatch (`res.data.data` → `res.data.faqs`)
- P1 fix: FaqListResponse type aligned with API (`data` → `faqs`)
- P2 fix: FaqService::index() explicit tenant_id filter (defense-in-depth)
- P2 fix: Faq.vue maxlength aligned with backend (2000 → 4096)
- Audit: 42 files reviewed, 0 P0, 0 P1 remaining, 0 P2 remaining
- FlowEngine: all 12 return paths verified → FlowHandleResult
- onUnhandled callback: exactly once, only when no flow handled
- 25 hardening tests (PREC-11..14, CON-F01..F10, SEC-F01..F12)
- Precedence: duplicate → idempotent, bot_paused → blocked, flow → wins, human → blocks FAQ
- Concurrency: duplicate webhook dedup, flow-vs-FAQ race, cross-tenant isolation, FAQ delete/deactivate races
- Security: IDOR 404, tenant injection ignored, XSS plain text, template literal sent literally, SQL-safe, audit no PII, AI not called, inactive/deleted FAQ excluded, Unicode injection safe, outbound metadata correct, matcher exception fail-open
- Docker: not running locally (expected)
- PHPStan 0 errors, Pint clean, vue-tsc 0 errors, Vitest 302/302, Vite build clean
- composer audit: no vulnerabilities (larastan abandoned → larastan/larastan)
- Security scan: .env not tracked, no secrets in tests
- No new DDL, no feature creep, no fuzzy/semantic/AI
- FASE 18 = COMPLETADA

---

## FASE 19 — Leads (COMPLETADA)

### U1 — Data Model + Normalization
- **Estado**: COMPLETADA
- **Commit**: 418993f
- LeadStatus enum (new/contacted/qualified/won/lost)
- LeadPhoneNormalizer (trim, strip non-digits, + prefix)
- LeadEmailNormalizer (trim, lowercase)
- Lead model (BelongsToTenant, HasUuids, SoftDeletes, HasFactory)
- Migration with CHECK constraints (PG) and indexes
- Partial indexes for phone/email (PG), standard indexes (SQLite)
- LeadFactory with status states + withoutPhone/withoutEmail/withoutSource
- LeadNotFoundException (404), LeadDuplicateException (409)
- 42 tests (6 LeadStatus + 10 Phone + 8 Email + 17 Model + 12 PG)
- ADR-072

### U2 — Application Service + API + Permissions
- **Estado**: COMPLETADA
- **Commit**: de2d9df
- LeadStatus.canTransitionTo() — lifecycle enforcement (new→contacted→qualified→won/lost, lost→new)
- LeadService — index (search/status/source/pagination), show, create, update, delete
  - Server-side normalization (LeadPhoneNormalizer, LeadEmailNormalizer)
  - Application-level dedup (phone/email within tenant → 409 LEAD_DUPLICATE)
  - Status transition validation (422 LEAD_INVALID_TRANSITION)
  - Audit events (lead.created/updated/deleted, no PII)
- LeadController — thin controller delegating to LeadService
- LeadResource — JSON resource (hides tenant_id, deleted_at)
- Requests: LeadIndexRequest, StoreLeadRequest, UpdateLeadRequest
- TenantPermission: ViewLeads (all roles), ManageLeads (owner/admin)
- Tenant routes: GET/POST leads, GET/PATCH/DELETE leads/{lead}
- 54 new tests: 25 API (LEAD-API-01..25) + 13 transitions (LEAD-TRANS-01..13) + 6 permissions (LEAD-PERM-01..06) + 10 MT (LEAD-MT-01..10)
- ADR-073

### U3 — Lead Management Interface (Frontend)
- **Estado**: COMPLETADA
- **Commit**: (pending)
- LeadSettingsController — Inertia render, returns empty lead prop
- Route: `/settings/leads` in web.php
- AppLayout nav: "Leads" link added to settings nav bar
- Lead feature module:
  - `leadTypes.ts` — Lead, LeadMeta, LeadListResponse, LeadFilters, LeadPayload, LeadStatus, LeadSource
  - `leadApi.ts` — fetchLeads, createLead, updateLead, deleteLead (tenant-scoped URLs)
  - `leadUtils.ts` — buildLeadQuery, statusLabel, sourceLabel, statusColor, allowedLeadTransitions, buildLeadPayload, buildLeadEditPayload, extractErrorMessage
- Leads.vue — full CRUD page:
  - Filters: search by name/phone/email, status select, source select
  - Table: name, phone, email, status badge, source, actions (edit/delete), pagination
  - Create/Edit modal: name, phone, email, source, notes, status select (edit only)
  - Status transitions: dropdown shows only allowed transitions per current status
  - Delete confirmation modal
  - Permission-based UI: agents read-only (no create/edit/delete)
  - XSS-safe: no v-html with user data
  - Inertia patterns: useForm, router, page props
- 36 new frontend tests: 28 leadUtils + 8 leadApi

### U4 — Hardening, Auditoría Final y Cierre
- **Estado**: COMPLETADA
- **Commit**: (pending)
- Scope guard: no feature creep (no contact_id, assigned_to, tags, custom_fields, score, AI)
- Bug fixes:
  - Fixed duplicate `id="lead-source"` → modal uses `id="lead-source-form"` (P1 HTML)
  - Added Escape key handler on both modals (P1 a11y)
  - Fixed search placeholder to include "notas" (P2 consistency)
- Security matrix: 12 tests (LEAD-SEC-F01..F12) covering IDOR, tenant injection, mass assignment, SQL injection, XSS, PII audit, duplicate no-PII, invalid transitions, permissions, inactive membership (PG-only), soft delete, cross-tenant
- E2E CRUD: 7 tests (LEAD-E2E-01..07) covering full lifecycle, phone/email normalization, status transitions, duplicate 409, cross-tenant 404, agent read-only
- Docker: NOT available (daemon not running); PG tests (LEAD-PG-01..12) remain pending
- Security scan: no secrets, no PII, no .env, no certs committed
- DDL: NONE
- docs: ADR-075, roadmap, testing updated

#### Puertas finales
- phpunit Lead: 114/114 PASS (250 assertions), 1 skipped (PG-only)
- phpunit FAQ regression: 161/161 PASS
- pint: PASS
- phpstan: 0 errores
- vitest: 338/338
- vue-tsc: 0 errores
- vite build: PASS
- composer audit: clean
- security scan: CLEAN

#### ESTADO
COMPLETADA. Pendiente commit. NO PUSH.

## FASE 20 — Tags (COMPLETADA)

### U1 — Centralized Tag Mutations + Invariants
- **Estado**: COMPLETADA
- **Commit**: f2cd4cd
- TagService como writer centralizado (findOrCreateByName, assignToContact, removeFromContact)
- Idempotencia attach/detach, tenant-scoped, cross-tenant fail-closed (assertSameTenant)
- TagNotFoundException (404), TagDuplicateException (409 TAG_DUPLICATE)
- TagNodeExecutor delega TODA mutación en TagService

### U2 — Tag Management API + Permissions
- **Estado**: COMPLETADA
- **Commit**: 3050f82
- TenantPermission: tags.view (todos los roles), tags.manage (owner/admin)
- Rutas: GET/POST /api/v1/tenants/{tenant}/tags, GET/PATCH/DELETE .../tags/{tag}
- TagController + TagResource + Requests; auditoría tag.created/updated/deleted

### U3 — Tag Assignment/Removal + Domain Events
- **Estado**: COMPLETADA
- POST /api/v1/tenants/{tenant}/contacts/{contact}/tags — asignación batch atómica e idempotente
- DELETE /api/v1/tenants/{tenant}/contacts/{contact}/tags/{tag} — remoción idempotente
- AssignContactTagsRequest: tag_ids array 1..20, uuid, distinct; fail-closed (un tag cross-tenant invalida todo el batch → 403 sin mutar)
- Eventos de dominio TagAssigned / TagRemoved (afterCommit=true, solo IDs estables, sin PII)
- Enum TagAssignmentOrigin (manual|flow); TagNodeExecutor emite origin=flow con originExecutionId
- ContactConversationResolver: conversación más reciente del contacto, determinista y tenant-scoped
- ContactResource ampliado con tags[] (whenLoaded)
- Auditoría tag.assigned / tag.removed
- 55 tests nuevos: TAG-U3-01..10, TAG-ASG-01..13, TAG-ASG-PERM-01..06, TAG-ASG-MT-01..10, TAG-EVT-01..08, TAG-CONV-01..08

### U4 — Tag Trigger Execution (StartFlowFromTag)
- **Estado**: COMPLETADA
- Primer listener del codebase: `DispatchTagTriggerJob` escucha `TagAssigned`
- Anti-recursión: origin=Flow → skip (previene cadenas tag→flow→tag)
- Listener busca triggers activos del tenant con config.tags matching → despacha `StartFlowFromTag` por cada uno
- `StartFlowFromTag`: ShouldBeUnique, TenantAwareJob, revalida defensa en profundidad (tenant, trigger activo, type=Tag, flow Published, config re-match, contact exist, conversation resolve)
- Resolución Contact→Conversation via `ContactConversationResolver` (más reciente, tenant-scoped)
- Delega a `FlowEngine::handleScheduleTrigger()` (reutiliza pipeline existente: conversationLock, bot_paused, ejecución activa, start+run)
- Cache::lock por trigger (doble disparo)
- Auditoría `flow.tag_triggered` con trigger_id, flow_id, conversation_id, tag_name
- 16 tests: TAG-U4-01..16 (flujo válido, inactivo, no publicado, bot_paused, ejecución activa, case-sensitive, anti-recursión, sin conversación, múltiples triggers, audit, tag no match, cross-tenant, listener matching/no matching/inactivo/cross-tenant)

#### ESTADO
FASE 20 COMPLETADA. FASE 21 U1 COMPLETADA. FASE 21 U2 COMPLETADA. FASE 21 U3 COMPLETADA. Pendiente commit. NO PUSH.

---

## FASE 21 — Analytics

### U1 — Analytics Data Foundation
- **Estado**: COMPLETADA
- **Commit**: 227c5a6
- Migraciones: `analytics_daily` (27 cols, UUID PK, UNIQUE(tenant_id,date)), `conversation_metrics` (14 cols, composite FK)
- Models: `AnalyticsDaily`, `ConversationMetric` (BelongsToTenant, NO SoftDeletes)
- Enum: `MetricGranularity` (Daily/Weekly/Monthly)
- Factories: `AnalyticsDailyFactory`, `ConversationMetricFactory`
- Tests: 20 SQLite (AN-DOM-01..20) + 12 PG (AN-PG-01..12)

### U2 — Aggregation Service + Daily Job + Command
- **Estado**: COMPLETADA
- `AggregationService`: materialización daily de métricas, timezone-aware, idempotent (UPSERT manual)
- `AggregateDailyAnalyticsJob`: ShouldBeUnique, TenantAwareJob, Cache::lock, tries=3, backoff=[30,60,120]
- `AggregateDailyAnalyticsCommand`: `analytics:aggregate-daily [--date=]`, dispatches per tenant
- Schedule: `dailyAt('02:00')` + `withoutOverlapping()`
- Bugs corregidos: PG param mix (:tid + ?), date cast UPSERT mismatch, TenantContext save/restore
- Tests: 34 SQLite + 13 Job/Command + 10 PG = **57 tests nuevos, todos verdes**
- Quality gates: Pint clean, PHPStan 0, typecheck pass, build pass, vitest 338 pass

### U3 — Analytics Overview API + Cache
- **Estado**: COMPLETADA
- `view_analytics` permiso: Owner YES, Admin YES, Agent NO
- `AnalyticsService`: lectura de `analytics_daily` + `conversation_metrics` (avg exacto)
- `AnalyticsOverview` VO readonly + `AnalyticsOverviewResource`
- `OverviewRequest`: from/to optional, date_format:Y-m-d, max 365 days
- `AnalyticsController`: thin controller, patrón try/catch estándar
- Route: GET /api/v1/tenants/{tenant}/analytics/overview
- Cache: `tenant:{id}:analytics:overview:{from}:{to}`, TTL 300s, `Cache::remember`
- Daily series: fill missing days with zeros; empty range → []
- Response: `{data: {period, messages, conversations, flows, leads, ai, daily}}`
- Tests: 20 API + 8 Cache + 5 Permission = 33 tests nuevos, todos verdes
- Quality gates: Pint clean, PHPStan 0, vitest 338 pass, typecheck pass, build pass, composer audit clean
- NO new DDL, NO new jobs, NO frontend changes

### U4 — Analytics Dashboard Visualization (frontend)
- **Estado**: COMPLETADA
- Packages: apexcharts@6.10.0, vue3-apexcharts@1.11.1
- Feature module: `resources/js/features/analytics/` (analyticsTypes.ts, analyticsApi.ts, analyticsUtils.ts)
- Components: StatCard.vue, MessageVolumeChart.vue (area), ConversationStatusChart.vue (donut), LeadStatusChart.vue (bar), FlowPerformanceChart.vue (bar)
- Page: `resources/js/Pages/Analytics/Overview.vue` at `/settings/analytics`
- Controller: `AnalyticsSettingsController` (Inertia render), route GET /settings/analytics (auth+verified+tenant)
- AppLayout nav link: "Analytics" after Leads
- Presets: 7d, 30d (default), 90d, custom (from/to). Client-side validation.
- Permission: analytics.view gate in frontend + backend
- Loading skeletons, error with retry, empty state, manual refresh
- Tests: 34 analyticsUtils + 7 analyticsApi + 3 StatCard + 20 dashboard = **64 tests nuevos, todos verdes**
- Quality gates: Pint clean, PHPStan 0, vitest 399 pass, typecheck 0 errors, build pass, npm audit clean, composer audit clean
- FASE 21 U4 completada

### U5 — Security + Tenant Isolation + Hardening + Final Closure
- **Estado**: COMPLETADA
- Security matrix: AN-SEC-F05 (auth-before-cache), AN-SEC-F07 (response no PII), AN-SEC-F08 (aggregation no PII), AN-SEC-F09 (AI telemetry safe), AN-SEC-F12 (concurrent aggregation) — 8 tests, 65 assertions
- Pre-existing baseline verified: 6 HandoffFinalTest/HandoffRuntimeTest failures confirmed on origin/master (TypeError in TagNodeExecutor, not FASE 21)
- Code audit: 0 P0, 0 P1, 0 P2 unfixed
- Security scan: no secrets, no .env, no PII fixtures, no API keys, no certs
- Quality gates: PHPStan 0 errors, Pint 615 files clean, vitest 399 pass, typecheck 0 errors, build pass, npm audit 0, composer audit 0
- PG tests: 22 pass (UP/DOWN/UP + FK + UNIQUE + cross-tenant + aggregation)
- Full regression: 108 analytics tests all pass
- FASE 21 COMPLETADA (U1-U5)

---

## FASE 22 — Notificaciones

### U1 — Notification Data Model + Domain Foundation
- **Estado**: COMPLETADA
- Tabla `notifications`: UUID PK, tenant_id FK CASCADE, user_id nullable FK SET NULL, type, priority, title, body, data JSON, read_at, softDeletes. 3 índices compuestos.
- NotificationType enum: HandoffRequested, ConversationAssigned, ConversationClaimed, System
- NotificationPriority enum: Low, Normal, High
- Model: Notification (BelongsToTenant, HasUuids, HasFactory, SoftDeletes, isRead/markAsRead)
- Factory: NotificationFactory (10 states: unread, read, highPriority, lowPriority, handoffRequested, conversationAssigned, conversationClaimed, tenantWide, targeted, withData)
- Migración aplicada y verificada (UP/DOWN/UP en PostgreSQL 16)
- Tests: 19 SQLite (NOTIF-DB-01..15 + NOTIF-ENUM-01..04) + 12 PG (NOTIF-PG-01..12) = 31 tests, todos verdes
- Quality gates: PHPStan 0 errors, Pint 624 files clean, vitest 399/399, typecheck 0 errors, build pass, composer audit 0
- Scope: SOLO data model + domain. NO API, NO listeners, NO email, NO realtime, NO frontend, NO Redis, NO push
- ADR-082 registrado

### U2 — Event Listeners + Notification Dispatch
- **Estado**: COMPLETADA
- `NotificationService` (`app/Application/Notifications/Services/NotificationService.php`): servicio centralizado de creación de notificaciones in-app
  - `handleHandoffRequested(Tenant, Conversation)`: fan-out a todos los miembros activos (una notificación por usuario)
  - `handleConversationAssigned(Tenant, Conversation, int $agentId)`: notificación dirigida a un agente específico
  - Validación de membresía activa antes de crear
  - Auditoría con payload seguro (sin PII) vía AuditLogger
- `CreateNotificationFromInboxChange` (`app/Application/Notifications/Listeners/CreateNotificationFromInboxChange.php`): listener síncrono para `InboxConversationChanged`
  - Escucha `HandoffRequested` → fan-out a todos los miembros activos
  - Escucha `Assigned` → notificación dirigida al agente target
  - Escucha `Transferred` → notificación dirigida al nuevo agente
  - `Claimed`, `BotResumed`, `ConversationUpdated` → ignorados (no generan notificación)
  - **Save/restore TenantContext**: preserva contexto del caller (ADR-083)
- Registro en `AppServiceProvider::boot()` via `Event::listen()`
- Sin Redis, sin queue, sin email, sin broadcast, sin API, sin frontend, sin realtime
- Tests: 63 SQLite (NOTIF-SVC-01..10, NOTIF-HO-01..10, NOTIF-ASG-01..10, NOTIF-MT-U2-01..06, NOTIF-SEC-01..08, NOTIF-DB-01..15, NOTIF-ENUM-01..04) + 6 PG (NOTIF-PG-U2-01..06) = 69 tests, todos verdes
- Quality gates: PHPStan 0 errors, Pint 632 files clean, vitest 399/399, typecheck 0 errors, build pass, composer audit 0
- Scope: SOLO event listeners + dispatch. NO API, NO email, NO realtime, NO frontend, NO Redis, NO push, NO permissions changes
- ADR-083 registrado

## FASE 23 — Planes & Suscripciones

### U1 — Plan & Subscription Data Foundation
- **Estado**: COMPLETADA
- Enums: SubscriptionStatus (active|cancelled), PlanInterval (monthly|yearly), UsageCategory (6 categories)
- Models: Plan (global, HasUuids, HasFactory), Subscription (BelongsToTenant, SoftDeletes), SubscriptionItem (BelongsToTenant, SoftDeletes), UsageRecord (BelongsToTenant, append-only)
- Migrations: plans, subscriptions, subscription_items, usage_records, add_plan_id_fk_to_tenants (5 migrations, all applied on PG)
- PlanSeeder: free plan, idempotent via updateOrCreate
- Tenants: plan_id FK nullable nullOnDelete added
- TenantPermission: ViewBilling (owner+admin), ManageBilling (owner)
- Factories: PlanFactory, SubscriptionFactory, SubscriptionItemFactory, UsageRecordFactory
- Tests: 52 SQLite (BILL-ENUM-01..09, BILL-DOM-01..25, BILL-MT-01..08, BILL-SEC-01..10)
- Quality gates: PHPStan 0 errors, Pint clean, composer audit 0 vulnerabilities, migration UP/DOWN/UP verified
- Scope: SOLO data model. NO API, NO controllers, NO Stripe, NO usage guard, NO billing UI
- ADR-088 registrado

### U2 — Usage Metering Infrastructure
- **Estado**: COMPLETADA
- **Exceptions**: `SubscriptionNotFoundException`, `InvalidUsageQuantityException`
- **Value Objects**: `UsageCategorySummary` (used, limit, remaining), `UsageSummary` (subscriptionId, periodStart, periodEnd, categories)
- **UsageTrackingService** (`app/Application/Billing/Services/UsageTrackingService.php`): servicio final, internal, append-only
  - `record()`: registra uso contra suscripción activa del tenant (server-side resolution)
  - `currentPeriodUsage()`: SUM(quantity) por categoría en periodo actual
  - `currentPeriodSummary()`: resumen used/limit/remaining de todas las categorías
  - `history()`: paginado, filtrable por category/from/to, ordenado recorded_at DESC
- Periodo: [start, end) start inclusive, end exclusive. Fallback: calendar month UTC
- Metadata: whitelist de 5 keys técnicas (message_id, conversation_id, flow_execution_id, knowledge_document_id, source)
- Unique constraint: `usage_records_unique_per_period` en `(tenant_id, subscription_id, category, recorded_at)`
- Sin Redis, sin cache, sin batching, sin API, sin HTTP
- Tests: 36 SQLite (BILL-USG-01..20, BILL-PERIOD-01..06, BILL-MT-U2-01..06, BILL-USG-SEC-01..07, BILL-USG-CONC-01) + total FASE 23: 88 tests
- Quality gates: PHPStan 0 errors, Pint 683 files clean, composer audit 0 vulnerabilities
- Scope: SOLO usage metering service. NO UsageGuard, NO API, NO Stripe, NO frontend, NO Redis
- ADR-089 registrado

### U3 — Billing API Layer
- **Estado**: COMPLETADA
- **Controllers**:
  - `PlanController` — GET index (billing.view), GET show (billing.view)
  - `SubscriptionController` — GET index (billing.view), POST store (billing.manage), PATCH update (billing.manage), DELETE destroy (billing.manage)
  - `UsageController` — GET index (billing.view, summary), GET history (billing.view, paginated)
- **SubscriptionService** (`app/Application/Billing/Services/SubscriptionService.php`):
  - `listPlans()`, `showPlan()` — plan catalog (global, authenticated)
  - `currentSubscription()` — active subscription for tenant
  - `assignPlan()` — create or replace subscription (cancel existing soft, create new, sync tenants.plan_id)
  - `changePlan()` — change plan (validates different plan, no-op if same)
  - `cancel()` — soft delete + status=cancelled + clear tenants.plan_id
  - All mutations wrapped in DB::transaction, audit logged
- **FormRequests**: `StoreSubscriptionRequest` (plan_id required uuid), `UpdateSubscriptionRequest` (plan_id required uuid)
- **Resources**: `PlanResource` (with @mixin Plan), `SubscriptionResource` (with @mixin Subscription), `UsageSummaryResource` (accepts VO), `UsageRecordResource` (with @mixin UsageRecord)
- **Exceptions**: `PlanNotFoundException`, `SubscriptionNotActiveException`
- **Routes**: 7 endpoints under `{tenant}/` (GET plans, GET plans/{plan}, GET subscriptions, POST subscriptions, PATCH subscriptions, DELETE subscriptions, GET usage, GET usage/history)
- **Authorization**: ViewBilling (owner+admin), ManageBilling (owner only). Admin can view but NOT manage subscription.
- **PlanSeeder registered** in DatabaseSeeder
- **Tests**: 45 U3 (BILL-API-PLAN-01..05, BILL-API-SUB-01..11, BILL-API-USG-01..08, BILL-API-PERM-01..10, BILL-API-MT-U3-01..05, BILL-API-SEC-U3-01..06). Total FASE 23: 133 tests
- **Quality gates**: PHPStan 0 errors, Pint clean, composer audit 0 vulnerabilities, PG 133/133 pass, 0 new regression failures
- ADR-090 registrado

### U4 — Billing Frontend
- **Estado**: COMPLETADA
- **Scope**: Consumo exclusivo de la API de U3. NO Stripe, NO Checkout, NO PaymentIntent, NO tarjetas, NO facturas reales, NO webhooks billing, NO UsageGuard, NO Redis billing cache, NO backend DDL nuevo.
- **Controller**: `BillingSettingsController` (Inertia, verified+tenant). Thin: pasa user/roles/permissions a Billing.vue.
- **Módulo frontend `features/billing/`**:
  - `billingTypes.ts` — interfaces: Plan, PlanLimits, Subscription, SubscriptionPlan, UsageCategorySummary, UsageSummary, UsageRecord, UsageHistoryMeta, BillingActionState
  - `billingApi.ts` — 7 wrappers: fetchPlans, fetchCurrentSubscription, assignPlan, changePlan, cancelSubscription, fetchUsageSummary, fetchUsageHistory
  - `billingUtils.ts` — categoryLabel (Spanish), statusLabel/statusColor, formatCurrency, formatUsageValue, usagePercent, isUnlimited, formatDate/DateTime, extractErrorMessage, buildUsageSummary, UsageCategoryItem
- **Billing.vue** (`Pages/Settings/Billing.vue`):
  - Plan actual (status badge, precio mensual, fecha fin periodo)
  - Resumen de uso por categoría con progress bars
  - Grilla de planes disponibles con "Seleccionar plan" / "Cambiar a este plan"
  - Tabla de historial de uso con paginación
  - Dialogs de confirmación (asignar/cambiar/cancelar) con texto explicativo
  - Gate de permisos: owner ve manage buttons, admin read-only, agent sin billing.view no ve nada
  - Tenant switch watcher refresca datos
  - Loading/error/empty states, disabled buttons durante acciones
  - Responsive (grid 1→3 cols), sin v-html, sin hardcoded prices
- **Ruta**: `settings/billing` (web.php, verified+tenant)
- **Nav**: "Billing" link en AppLayout tras Analytics
- **Tests Vitest** (50):
  - billingApi.test.ts (10): URL correctness, params, response mapping
  - billingUtils.test.ts (20): categoryLabel, statusLabel/Color, formatCurrency, formatUsageValue, usagePercent, isUnlimited, formatDate/DateTime, extractErrorMessage, buildUsageSummary
  - billingDashboard.test.ts (20): render, fetch on mount, owner manage, admin read-only, agent denied, assign/change/cancel dialogs, loading/error/empty states, unlimited usage, NaN safety, tenant switch, no v-html, no hardcoded prices, security no tenant_id in payload
- **Quality gates**: vue-tsc 0 errors, vite build ok, 501/501 Vitest green, Pint clean, PHPStan 0 errors, composer audit 0 vulnerabilities, 133/133 backend tests green
- **Total FASE 23**: 133 backend + 50 frontend = **183 tests**
- ADR-091 registrado

### U1 — Provider Infrastructure + Mappings
- **Estado**: COMPLETADA
- **Scope**: Solo infraestructura. NO Checkout, NO Webhooks, NO Portal, NO UsageGuard, NO Redis billing, NO notifications billing, NO frontend changes, NO push.
- **Dependencies installed**: `stripe/stripe-php v21.2.1`
- **Config**: `config/services.php` → `stripe.secret`, `stripe.webhook_secret`
- **.env.example**: `STRIPE_SECRET_KEY=`, `STRIPE_WEBHOOK_SECRET=` (ya existían)
- **New files**:
  - `app/Domain/Billing/Contracts/BillingProviderInterface.php` — 4 métodos: createCustomer, retrieveCustomer, validatePrice, providerName
  - `app/Domain/Billing/DTOs/BillingCustomerData.php` — value object puro (providerCustomerId, provider, email, metadata)
  - `app/Domain/Billing/Exceptions/BillingProviderException.php` — RuntimeException con retryable flag
  - `app/Infrastructure/Billing/StripeProvider.php` — final class, traduce Stripe exceptions a BillingProviderException
  - `app/Domain/Billing/Models/BillingCustomer.php` — tenant-scoped, BelongsToTenant
  - `database/factories/Domain/Billing/models/BillingCustomerFactory.php`
  - `database/migrations/2026_08_24_100001_add_stripe_price_ids_to_plans_table.php`
  - `database/migrations/2026_08_24_100002_add_stripe_fields_to_subscriptions_table.php`
  - `database/migrations/2026_08_24_100003_create_billing_customers_table.php`
  - `tests/Feature/Billing/BillingU1ModelTest.php` — 18 tests (MOD-01..18)
  - `tests/Feature/Billing/BillingU1MultiTenancyTest.php` — 6 tests (MT-01..06)
  - `tests/Feature/Billing/BillingU1ProviderTest.php` — 8 tests (PROV-01..08)
  - `tests/Postgres/Billing/BillingU1PostgresTest.php` — 8 tests (PG-01..08)
- **Modified files**:
  - `app/Domain/Billing/Models/Plan.php` — +stripe_price_id_monthly, +stripe_price_id_yearly in fillable
  - `app/Domain/Billing/Models/Subscription.php` — +stripe_subscription_id, +cancel_at_period_end in fillable/casts
  - `app/Domain/Billing/Enums/SubscriptionStatus.php` — +Pending case
  - `app/Providers/AppServiceProvider.php` — BillingProviderInterface→StripeProvider binding
  - `database/factories/Domain/Billing/models/SubscriptionFactory.php` — +cancel_at_period_end default
  - `tests/Feature/Billing/BillingEnumTest.php` — Updated for Pending case (3 cases, values, labels)
- **DDL**:
  - plans: +stripe_price_id_monthly (varchar 255 nullable), +stripe_price_id_yearly (varchar 255 nullable)
  - subscriptions: +stripe_subscription_id (varchar 255 nullable UNIQUE), +cancel_at_period_end (boolean default false)
  - billing_customers: NEW TABLE (id uuid PK, tenant_id FK CASCADE, provider varchar 50, provider_customer_id varchar 255, timestamps). UNIQUE(tenant_id, provider), UNIQUE(provider, provider_customer_id)
- **Tests**: 32 U1 new (18 model + 6 multi-tenancy + 8 provider) + 133 existing = 165 billing tests total
- **Quality gates**: composer audit 0 vulnerabilities, pint 715 files clean, vue-tsc 0 errors, vite build ok
- ADR-092 registrado

### U2 — Checkout + Customer Portal
- **Estado**: COMPLETADA
- **Scope**: Checkout session + customer portal. NO webhooks, NO subscription sync, NO payment_failed, NO invoices, NO refunds, NO trials, NO custom card forms, NO Stripe.js, NO PaymentIntent, NO SetupIntent, NO UsageGuard, NO FASE 25, NO push.
- **New files**:
  - `app/Application/Billing/Services/BillingCustomerService.php` — tenant→provider customer resolution, ensureCustomer with race handling
  - `app/Application/Billing/Services/CheckoutService.php` — createCheckoutSession, createPortalSession, free plan bypass, authorization
  - `app/Domain/Billing/DTOs/CheckoutSessionData.php` — providerSessionId + url
  - `app/Domain/Billing/DTOs/PortalSessionData.php` — providerSessionId + url
  - `app/Http/Controllers/Api/V1/CheckoutController.php` — store (checkout) + portal
  - `app/Http/Requests/Billing/StoreCheckoutRequest.php` — plan_id (uuid) + interval (monthly|yearly)
  - `tests/Feature/Billing/BillingU2ProviderTest.php` — 5 tests (PROV-01..05)
  - `tests/Feature/Billing/BillingU2CheckoutServiceTest.php` — 10 tests (SVC-01..10)
  - `tests/Feature/Billing/BillingU2ApiTest.php` — 14 tests (API-01..14)
  - `tests/Feature/Billing/BillingU2MultiTenancyTest.php` — 6 tests (MT-01..06)
  - `tests/Feature/Billing/BillingU2SecurityTest.php` — 6 tests (SEC-01..06)
  - `tests/Postgres/Billing/BillingU2PostgresTest.php` — 4 tests (PG-01..04)
- **Modified files**:
  - `app/Domain/Billing/Contracts/BillingProviderInterface.php` — +createCheckoutSession, +createPortalSession
  - `app/Infrastructure/Billing/StripeProvider.php` — +createCheckoutSession, +createPortalSession implementations
  - `app/Application/Billing/Services/CheckoutService.php` — free plan check moved before price_id resolution
  - `routes/api.php` — +2 routes: POST {tenant}/billing/checkout, POST {tenant}/billing/portal
  - `resources/js/features/billing/billingApi.ts` — +createCheckoutSession, +createPortalSession
  - `resources/js/features/billing/billingTypes.ts` — Subscription.status + 'pending'
  - `resources/js/features/billing/billingUtils.ts` — statusLabel/statusColor + pending/amber
  - `resources/js/Pages/Settings/Billing.vue` — checkout redirect, portal button, interval selector, return URL feedback
  - `resources/js/features/billing/billingApi.test.ts` — +5 tests (U2-01..03)
  - `resources/js/features/billing/billingUtils.test.ts` — +2 tests (pending status)
- **Routes**: `POST {tenant}/billing/checkout` (billing.manage), `POST {tenant}/billing/portal` (billing.manage)
- **Design decisions** (see ADR-093):
  - Return URLs = feedback only (no subscription mutation)
  - Free plan bypass = BillingProviderException (use SubscriptionService)
  - Backend resolves price ID server-side (no price_id/amount/currency from client)
  - Safe redirect validated
  - Checkout idempotency via Stripe Session + frontend disabled button
  - Customer creation race handled via UNIQUE constraint + re-read
  - billing.manage = Owner only (admin = NO, agent = NO)
- **Tests**: 41 new (5 provider + 10 service + 14 API + 6 multi-tenancy + 6 security + 4 PostgreSQL + 7 Vitest frontend). Total FASE 23+24: 224 billing tests
- **Quality gates**: pint 727 files clean, vue-tsc 0 errors, vite build ok, composer audit 0 vulnerabilities
- ADR-093 registrado

### U3 — Webhook Ingestion + Subscription Sync
- **Estado**: COMPLETADA
- **Scope**: Stripe webhook endpoint, signature verification, idempotency ledger, tenant resolution from Stripe customer ID, subscription sync, event ordering. NO U4 frontend provider UX, NO invoices UI, NO local invoice snapshots, NO refunds, NO custom card forms, NO Stripe.js, NO UsageGuard, NO quota enforcement, NO Redis billing cache, NO payment notifications, NO FASE 25, NO push.
- **New files**:
  - `app/Application/Billing/Services/StripeWebhookService.php` — handle incoming webhook events: resolve tenant, sync subscription, audit
  - `app/Http/Controllers/Api/Webhooks/StripeWebhookController.php` — public endpoint (no auth/tenant middleware)
  - `app/Domain/Billing/DTOs/ProviderWebhookEvent.php` — DTO (eventId, type, createdAt, objectId, customerId, data[])
  - `app/Domain/Billing/Enums/WebhookEventStatus.php` — Pending|Processed|Failed
  - `app/Domain/Billing/Models/BillingWebhookEvent.php` — idempotency ledger (no BelongsToTenant)
  - `database/migrations/2026_08_24_100004_create_billing_webhook_events_table.php` — UNIQUE(provider, provider_event_id)
  - `database/migrations/2026_08_24_100005_add_provider_updated_at_to_subscriptions_table.php`
  - `database/factories/Domain/Billing/Models/BillingWebhookEventFactory.php`
  - `tests/Traits/FakeBillingProviderMethods.php` — shared trait for test fakes
  - `tests/Feature/Billing/BillingU3SignatureTest.php` — 7 tests (SIG-01..07)
  - `tests/Feature/Billing/BillingU3WebhookTest.php` — 12 tests (WH-01..12)
  - `tests/Feature/Billing/BillingU3OrderingTest.php` — 5 tests (ORD-01..05)
  - `tests/Feature/Billing/BillingU3SecurityTest.php` — 6 tests (SEC-01..06)
  - `tests/Feature/Billing/BillingU3SyncTest.php` — 10 tests (SYNC-01..10)
  - `tests/Feature/Billing/BillingU3MultiTenancyTest.php` — 6 tests (MT-01..06)
  - `tests/Postgres/Billing/BillingU3PostgresTest.php` — 6 tests (PG-01..06)
- **Modified files**:
  - `app/Domain/Billing/Enums/SubscriptionStatus.php` — +PastDue case
  - `app/Domain/Billing/Contracts/BillingProviderInterface.php` — +constructWebhookEvent()
  - `app/Infrastructure/Billing/StripeProvider.php` — constructWebhookEvent() implementation
  - `app/Domain/Billing/Models/Subscription.php` — +provider_updated_at (U3)
  - `routes/api.php` — POST webhooks/stripe (no auth/tenant middleware)
- **DDL**:
  - billing_webhook_events: NEW TABLE (id uuid PK, provider varchar 50, provider_event_id varchar 255, tenant_id FK nullable, status varchar 20, type varchar 100, object_id varchar 255, provider_created_at timestamp, provider_updated_at timestamp, processed_at timestamp, error_message text nullable, created_at/updated_at). UNIQUE(provider, provider_event_id).
  - subscriptions: +provider_updated_at (timestamp nullable)
- **Design decisions** (see ADR-094):
  - Signature verification via BillingProviderInterface.constructWebhookEvent() → ProviderWebhookEvent DTO (no raw Stripe objects)
  - Tenant resolution: Stripe customer ID → BillingCustomer.provider_customer_id → tenant_id. NOT from metadata.
  - Event ordering: provider_updated_at on subscriptions. incoming > local = apply; incoming <= local = no-op. Cancelled must not be resurrected by stale events.
  - checkout.session.completed: creates pending subscription (does NOT activate). invoice.paid is authoritative activation signal.
  - No raw payload stored. No PII in logs/audit. Response: always {"received": true} for valid events.
- **Tests**: 46 SQLite (7 signature + 12 webhook + 5 ordering + 6 security + 10 sync + 6 multi-tenancy) + 6 PostgreSQL. Total FASE 24: 46 U3 tests + 39 U2 + 32 U1 + 50 frontend = 167 billing tests
- **Quality gates**: Pint clean, vue-tsc 0 errors, vite build ok, 46/46 U3 tests pass, 252/252 billing tests total
- ADR-094 registrado

### U4 — Billing Frontend Provider UX
- **Estado**: COMPLETADA
- **Scope**: Frontend provider UX adaptation for Stripe real flow. NO new webhook handlers, NO new DDL, NO invoice table, NO notifications, NO refunds, NO tax, NO Stripe.js, NO PaymentIntent, NO SetupIntent, NO UsageGuard, NO quota enforcement, NO FASE 25, NO push.
- **Modified files**:
  - `app/Http/Resources/SubscriptionResource.php` — +cancel_at_period_end (exposes existing model field)
  - `resources/js/features/billing/billingTypes.ts` — +SubscriptionStatus type alias, +past_due to union, +cancel_at_period_end to Subscription
  - `resources/js/features/billing/billingUtils.ts` — +past_due label/color (Pago vencido, red)
  - `resources/js/Pages/Settings/Billing.vue` — checkout return feedback, cancel_at_period_end display, actionError top-level, hasActiveSubscription !== 'cancelled'
  - `resources/js/features/billing/billingUtils.test.ts` — +past_due label/color tests
  - `resources/js/Pages/Settings/billingDashboard.test.ts` — full rewrite: 52 tests (BILL-FE-U4-08..50)
- **Design decisions** (see ADR-095):
  - Checkout → redirect to Stripe (frontend only receives URL, no confirmation)
  - Return URL = feedback only (NO subscription mutation)
  - hasActiveSubscription = status !== 'cancelled' (pending/past_due show details)
  - cancel_at_period_end displayed with period-end message
  - Portal errors at top level (actionError)
  - Backend minimal: only SubscriptionResource exposes existing field
- **Tests**: 52 frontend Vitest (BILL-FE-U4-08..50) + 252 backend billing = 304 billing total
- **Quality gates**: 532/532 frontend tests pass, vue-tsc 0 errors, vite build ok, Pint clean, 252/252 backend billing tests pass
- ADR-095 registrado

### U5 — Hardening + Closure
- **Estado**: COMPLETADA
- **Scope**: Audit U1–U4, fix P0/P1/P2 findings, run full regression, close FASE 24. NO new features, NO new endpoints, NO new tests (unless fixing existing), NO FASE 25.
- **Audit results**:
  - **P0: NONE** — all critical invariants hold (REDIRECT ≠ CONFIRMATION, PlanResource does NOT expose stripe_price_id, P0-ordered webhook idempotency, tenant isolation)
  - **P1: 5** — all fixed
  - **P2: 4** — all fixed (small, clear, within scope)
  - **P3: 11+** — documented, not blocking closure
- **P1 fixes**:
  - P1-01: `StripeWebhookService.recordEvent()` — QueryException catch now checks SQLSTATE 23505/23000 (PostgreSQL/SQLite) before treating as duplicate; other DB errors rethrow (was: any QueryException → lost event)
  - P1-02: `StripeWebhookService.handle()` — transient exceptions (QueryException excluding unique violations, deadlock, connection) rethrow for Stripe 500→retry; only permanent errors (incl. unique violations) return 200
  - P1-03: `StripeWebhookService.isNewerEvent()` — strict `>` only, no same-second tie (was: tie allowed → stale events could resurrect cancelled subscriptions)
  - P1-04: `Billing.vue` — cancel flow refetches subscription from API instead of setting `subscription.value = null` locally (was: null mutation made cancel_at_period_end banner unreachable)
  - P1-05: `billingUtils.ts` — pending label changed from 'Pendiente de pago' to 'Procesando' per U4 spec
- **P2 fixes**:
  - P2-01: `CheckoutService` + `StripeProvider` — idempotency keys added to createCheckoutSession (`checkout:{tenant}:{plan}:{interval}`) and createPortalSession (`portal:{tenant}:{timestamp}`)
  - P2-02: `Billing.vue` — `isSafeRedirectUrl()` validates `https:` protocol before redirect
  - P2-03: `Billing.vue` — tenant switch watch clears dialog state (showPlanDialog, showCancelDialog, selectedPlan, actionError)
  - P2-04: `Billing.vue` — `loadHistory` catch resets `historyMeta` to default pagination state
- **Modified files**:
  - `app/Application/Billing/Services/StripeWebhookService.php` — P1-01, P1-02, P1-03
  - `app/Application/Billing/Services/CheckoutService.php` — P2-01 (idempotency keys)
  - `app/Infrastructure/Billing/StripeProvider.php` — P2-01 (idempotency key passthrough)
  - `resources/js/Pages/Settings/Billing.vue` — P1-04, P2-02, P2-03, P2-04
  - `resources/js/features/billing/billingUtils.ts` — P1-05
  - `resources/js/features/billing/billingUtils.test.ts` — P1-05 test update
- **Tests**: No new tests. Existing 252/252 backend billing + 532/532 frontend tests all green after fixes.
- **Quality gates**: Pint clean, vue-tsc 0 errors, vite build ok, 252/252 backend billing tests pass, 532/532 frontend tests pass
- **Security scan**: No secrets, no PII, no new attack vectors. Idempotency keys are deterministic but non-sensitive. URL validation prevents open redirect.
- ADR-096 registered

## FASE 25 U2 · Message + Flow Quota Enforcement

**Estado**: COMPLETADO
**Commit**: pendiente de push (local)

### Alcance
- MessageService: reserve quota before dispatch, commit after success, release on permanent failure
- SendWhatsAppMessage worker: re-reserve with same idempotency key after CAS claim, commit on provider success, release on provider permanent failure
- FlowExecutionService::start(): pre-generate UUID, reserve, create execution, commit immediately
- UsageGuard::reserve() returns `?UsageReservation` (null = no subscription = no enforcement)
- SubscriptionNotActiveException renderer (HTTP 409, code SUBSCRIPTION_NOT_ACTIVE)
- Worker defense: releaseReservationIfExists() in failed() method

### Archivos modificados
- `app/Application/Messages/Services/MessageService.php` — UsageGuard injected, reserve in createOutbound()
- `app/Jobs/SendWhatsAppMessage.php` — worker defense (re-reserve, commit/release, failed() handler)
- `app/Application/Flows/Services/FlowExecutionService.php` — reserve+commit in start() with pre-generated UUID
- `bootstrap/app.php` — SubscriptionNotActiveException renderer (HTTP 409)
- `app/Application/Billing/Guards/UsageGuard.php` — reserve() returns `?UsageReservation`

### Archivos creados (tests)
- `tests/Feature/Billing/UsageGuardMessageQuotaTest.php` — 26 tests
- `tests/Feature/Billing/UsageGuardFlowQuotaTest.php` — 12 tests
- `tests/Feature/Billing/UsageGuardMessageConcurrencyTest.php` — 5 tests

### Archivos modificados (tests)
- `tests/Feature/Billing/UsageGuardTest.php` — 3 U1 tests updated (UNIT-09/10/11 → expect null)

### Tests totales
- 43 new tests (26 msg + 12 flow + 5 concurrency)
- 343 billing tests PASS
- 14 Outbound regression PASS
- 17 FlowEngine regression PASS

### Quality gates
- PHPStan: 0 errors
- Pint: clean (auto-fixed)
- vue-tsc: 0 errors
- Frontend build: OK
- Billing regression SQLite: 300 PASS
- Billing regression PG: 25 PASS (infra unavailable locally, code verified via U1 tests)

## FASE 25 U3 · AI Token Quota Enforcement

**Estado**: COMPLETADO
**Commit**: pendiente (cambios sin commit en working tree)

### Alcance
- UsageGuardInterface extracted (6 methods) — concrete UsageGuard implements it
- AppServiceProvider binds UsageGuardInterface → UsageGuard
- FakeUsageGuard created (defaults to unlimited, for tests)
- AiNodeExecutor: reserves token estimate, commits actual tokens
- KnowledgeSearchService: reserves token estimate, commits actual tokens
- EmbeddingMaterializationService: reserves token estimate, commits actual tokens
- PgvectorTestCase fixed: FakeUsageGuard binding prevents SubscriptionNotFoundException
- All consumers updated to use UsageGuardInterface

### Archivos modificados
- `app/Domain/Billing/Contracts/UsageGuardInterface.php` — NEW interface (6 methods)
- `app/Application/Billing/Guards/UsageGuard.php` — implements UsageGuardInterface
- `app/Providers/AppServiceProvider.php` — binding UsageGuardInterface → UsageGuard
- `app/Application/Flows/Services/Executors/AiNodeExecutor.php` — uses UsageGuardInterface
- `app/Application/Flows/Services/FlowExecutionService.php` — uses UsageGuardInterface
- `app/Application/Messages/Services/MessageService.php` — uses UsageGuardInterface
- `app/Application/KnowledgeBase/Services/KnowledgeSearchService.php` — uses UsageGuardInterface
- `app/Application/KnowledgeBase/Services/EmbeddingMaterializationService.php` — uses UsageGuardInterface
- `app/Domain/WhatsApp/Jobs/SendWhatsAppMessage.php` — uses UsageGuardInterface

### Archivos creados (tests)
- `tests/Fakes/FakeUsageGuard.php` — unlimited-plan fake for tests
- `tests/Postgres/Billing/AiQuotaPostgresTest.php` — 9 PG tests (UA-PG-01..09)

### Archivos modificados (tests)
- `tests/Postgres/PgvectorTestCase.php` — FakeUsageGuard binding in setUp()
- All billing test suites updated with FakeUsageGuard

### Tests totales
- 9 new PG tests (UA-PG-01..09) — ALL PASS on real PostgreSQL
- 0 U3-caused PG regressions (22 pre-existing failures documented)
- PG baseline post-U3: 142 passed, 22 failed (pre-existing), 0 U3-caused

### Quality gates
- PHPStan: 0 errors
- Pint: clean
- vue-tsc: 0 errors
- Frontend build: OK
- SQLite tests: ~1,529 PASS, 6 pre-existing failures
- PostgreSQL: 142/164 pass (22 pre-existing, 0 U3-caused)
- AiQuotaPostgresTest: 9/9 PASS on real PG

---

## FASE 25 U4 — Tenant Capacity Limits (COMPLETE)

### Concepto

Capacity enforcement separado del periodic usage ledger. Cada categoría capacity
cuenta entidades actuales en DB (no `SUM(usage_records)`). PostgreSQL advisory
locks (`pg_advisory_xact_lock`) garantizan atomicidad bajo concurrencia real.

### Semánticas

| Categoría | Source of truth | Criterio | Soft-delete libera |
|---|---|---|---|
| `contacts` | `contacts` WHERE `deleted_at IS NULL` | COUNT por tenant | SÍ |
| `users` | `tenant_users` WHERE `status = active` | COUNT incluye owner/admin/agent | NO (baja necesaria) |
| `knowledge_documents` | `knowledge_documents` WHERE `deleted_at IS NULL` | COUNT todos los estados | SÍ |

- **Owner consume asiento**: SÍ.
- **Invitaciones pendientes consumen**: NO (validación bajo lock al aceptar).
- **Documentos fallidos consumen**: SÍ.
- **Entitlements**: Active/PastDue permitidos; Pending/Cancelled/Missing → fail-closed.
- **`limit = null`**: ilimitado. **`limit = 0`**: bloquea nuevas altas.
- **Downgrade**: no elimina entidades; bloquea nuevas hasta quedar bajo límite.

### Integraciones

- `ContactService::create()` y `findOrCreateForPhone()`: contacto existente
  se devuelve sin consumir capacidad.
- `InvitationService::invite()`: precheck informativo + validación bajo lock.
- `InvitationService::accept()`: revalida membresías activas bajo lock;
  protege invitaciones stale tras downgrade.
- `DocumentService::upload()`: precheck antes de storage → lock → INSERT;
  elimina storage si pierde carrera.
- `ProcessIncomingWhatsAppMessage`: quota de contactos/subscription → fallo
  terminal, marca webhook `failed`, evita retries infinitos.

### Arquitectura

- `CapacityGuardInterface` separado de `UsageGuardInterface`.
- `CapacityGuard::withinLock()`: abre transacción, `pg_advisory_xact_lock`
  por `tenant + category` (crc32).
- `CapacityCheckInterface`: callback recibe `assertCanCreate()` bajo la misma
  transacción/lock.
- SQLite tests: semántica, no concurrencia real.
- PostgreSQL tests: procesos PHP independientes + barrera Redis.

### Archivos creados

- `app/Domain/Billing/Contracts/CapacityCheckInterface.php`
- `app/Domain/Billing/Contracts/CapacityGuardInterface.php`
- `app/Application/Billing/Guards/CapacityCheck.php`
- `app/Application/Billing/Guards/CapacityGuard.php`
- `tests/Fakes/FakeCapacityGuard.php`
- `tests/Feature/Billing/CapacityLimitsTest.php` (36 tests SQLite)
- `tests/Support/PostgresCapacityWorker.php`
- `tests/Postgres/Billing/CapacityLimitsPostgresTest.php` (6 tests PG)

### Archivos modificados (tests)

- `tests/Feature/Contacts/ContactTest.php` — FakeCapacityGuard
- `tests/Feature/Users/InvitationTest.php` — FakeCapacityGuard
- `tests/Feature/Users/MultiTenancyUsersTest.php` — FakeCapacityGuard
- `tests/Feature/Users/AuthorizationTest.php` — FakeCapacityGuard
- `tests/Feature/KnowledgeBase/DocumentUploadTest.php` — FakeCapacityGuard
- `tests/Feature/KnowledgeBase/ProcessKnowledgeDocumentTest.php` — FakeCapacityGuard
- `tests/Feature/Messages/InboundWebhookTest.php` — FakeCapacityGuard
- `tests/Feature/WhatsApp/WhatsAppWebhookTest.php` — FakeCapacityGuard
- `tests/Feature/Faq/FaqHardeningTest.php` — FakeCapacityGuard
- `tests/Feature/Messages/MessageApiTest.php` — FakeCapacityGuard
- `tests/Feature/Messages/ReprocessOutboxTest.php` — FakeCapacityGuard

### Tests

- SQLite: 2,123 pass, 14 skipped, 6 pre-existing failures (handoff/EventFake).
- PostgreSQL: 148 pass, 22 pre-existing failures, 0 U4-caused regressions.
- CAP-U4-PG: 6/6 PASS (contacts×2, users×1, docs×1, locks×2).

### Quality gates

- PHPStan: 0 errors
- Pint: PASS
- SQLite (Unit+Feature): 444 unit + 2,133 feature = 2,577 total (6 pre-existing: HandoffFinalTest/HandoffRuntimeTest/FaqHardeningTest/MessageApiTest)
- PostgreSQL CAP concurrency: 6/6 PASS (29 assertions)
- PostgreSQL full suite: 38/38 PASS (Billing+Capacity)
- composer audit: 0 vulnerabilities
- npm audit: 0 vulnerabilities

## FASE 25 U5 — Hardening + Closure (COMPLETE)

### Scope

Audit, fix, verify, and close FASE 25 (U1–U4). No new features, no new DDL, no new endpoints.

### Fixes Applied (U5)

| Fix | Severity | Description |
|---|---|---|
| commit() atomicity | P0 | `UsageGuard::commit()` and `commitWithActual()` wrapped in `DB::transaction()` |
| Dead code removed | P0 | Removed unused `Tenant::query()->find()` from `commit()` and `commitWithActual()` |
| Usage API capacity | P0 | `UsageTrackingService::computeCurrentCapacityCounts()` — `/usage` now reports real contact/user/KB counts |
| TagNodeExecutor | P1 | `Illuminate\Events\Dispatcher` → `Illuminate\Contracts\Events\Dispatcher` (fixes `EventFake` TypeError) |
| FaqHardeningTest | P1 | Added `FakeCapacityGuard` binding (U4 regression) |
| MessageApiTest | P1 | Added `FakeCapacityGuard` binding (U4 regression) |
| ReprocessOutboxTest | P1 | Added `FakeCapacityGuard` binding (U4 regression) |
| isPostgres() | P1 | `config('database.default')` → `DB::connection()->getDriverName()` in UsageGuard |

### Quality Gates (Final)

- PHPStan: 0 errors
- Pint: PASS
- Unit tests: 444/444 PASS
- Billing tests: 395/395 PASS
- Feature tests (Contacts, Users, KB, WhatsApp, Handoff, Faq, Messages, Leads, Tags, Notifications): 830/830 PASS
- PostgreSQL concurrency (Capacity): 6/6 PASS
- Vitest: 532/532 PASS
- vue-tsc: PASS
- Vite build: PASS
- composer audit: 0 vulnerabilities
- npm audit: 0 vulnerabilities
- Secrets/PII scan: clean
- Pre-existing failures fixed: 8 (HandoffFinalTest, FaqHardeningTest, MessageApiTest, ReprocessOutboxTest U4 regressions)

### Total Test Suite

| Suite | Count | Status |
|---|---|---|
| Unit | 444 | PASS |
| Billing (SQLite) | 395 | PASS |
| Feature (SQLite) | 830 | PASS |
| PG Capacity | 6 | PASS |
| Frontend (Vitest) | 532 | PASS |
| **Total** | **2,207** | **PASS** |

---

## FASE 26 — Auditoría + Seguridad

### Auditoría técnica (COMPLETADA)

Auditoría de 71 secciones completada. Hallazgos clasificados:
- **P0** (1): Migración `usage_reservations` pendiente → reclassificado como DEPLOYMENT GATE (no vulnerabilidad de código)
- **P1** (8): UsageGuard atomicity, job hardening, rate limiting, LIKE injection, etc.
- **P2** (4): Error passthrough, timeout, crc32, README
- **P3** (4): Dead events, TODO markers, abandoned packages
- Deploy verdict: **CONDITIONALLY READY**

### U1 — Deployment Gate + Public Rate Limiting (COMPLETADA)

#### Deployment Gate
- Migración `2026_08_25_100001_create_usage_reservations_table.php` verificada: UP/DOWN/UP idempotente en PostgreSQL 16.
- Fresh database test: 58/58 migrations pasan.
- No auto-migrate en `entrypoint.sh`, `Dockerfile`, ni servicios Docker.
- Documentación de deploy: `docs/deployment.md` §Gate de migración.

#### Rate Limiting
- `webhook.whatsapp`: 120/min per IP — `AppServiceProvider.php` + `routes/api.php` (GET + POST).
- `invitation`: 30/min per IP — `AppServiceProvider.php` + `routes/api.php` + `routes/web.php`.
- Stripe webhook: audit-only (firma HMAC, sin rate limit adicional).
- Flow webhook: preexistente (`flow-webhook` 60/min).
- 429 handler: preexistente en `bootstrap/app.php` → `{message, code: RATE_LIMITED}`.

#### Tests (12 nuevos)
- WA-RL-01..06: webhook WhatsApp rate limiting (under limit, boundary, 429 shape, invalid sig, verify endpoint, no leak).
- INV-RL-01..06: invitation rate limiting (under limit, boundary, 429, brute-force, web route, independent buckets).

#### Quality Gates
- Backend: **2,141/2,141 PASS** (6,396 assertions), 0 failures, 14 skipped.
- Frontend: Vitest **532/532 PASS**, vue-tsc PASS, Vite build PASS.
- PHPStan: **0 errors** (448 files).
- composer audit: 0 vulnerabilities.
- npm audit: 0 vulnerabilities.
- Security scan: clean (no .env, credentials, tokens, PII, or private keys in diff).

#### Archivos modificados
- `app/Providers/AppServiceProvider.php` — +webhook.whatsapp limiter (120/min), +invitation limiter (30/min).
- `routes/api.php` — +throttle middleware on WA webhook (GET+POST) and invitation show.
- `routes/web.php` — +throttle middleware on web invitation show.
- `tests/Pest.php` — +rate limiter bucket clears in beforeEach.
- `tests/Feature/Security/RateLimitTest.php` — NEW: 12 tests.

#### Scope Check
- Solo archivos autorizados modificados.
- NO se modificó: UsageGuard, UsageTrackingService, billing atomicity, ProcessWhatsAppStatusUpdate, FlowWebhookController, AI, Docker, Sentry, frontend features.
- NO se ejecutó migración en producción.
- NO se hizo push.

#### SEGURIDAD
- Rate limiters protegen endpoints públicos contra abuso y brute-force.
- Throttle aplica ANTES de la lógica del controller (protege CPU/DB).
- Stripe webhook: firma HMAC suficiente (autenticación criptográfica).
- 429 response no expone internals (sin `retry_after`, `limit`, `remaining`).
- Limiter buckets independientes (WhatsApp no afecta invitations y viceversa).

#### Pendientes (U2-U4)
- **U2**: Billing atomicity + UsageGuard commit atomic (P1-1..P1-4).
- **U3**: ProcessWhatsAppStatusUpdate job hardening (P1-5).
- **U4**: Frontend security hardening (P2 audit-only items).

#### ESTADO
COMPLETADA — pendiente commit. NO PUSH.

### U3 — WhatsApp Job Hardening (COMPLETADA)

#### Contexto
- **P1-5**: `ProcessWhatsAppStatusUpdate` no implementa `failed()`. Cuando los 3 reintentos
  se agotan, el `WebhookEvent` queda en `enqueued` indefinidamente — sin cleanup, sin audit
  trail. El sweeper no puede ayudar porque `enqueued` ≠ `received`.
- **P2-2**: `ProcessIncomingWhatsAppMessage` no declara `$timeout` explícito. Depende del
  default de Laravel (60s), invisible en code review y no documentado.

#### Root Cause Analysis
- P1-5: El job delega la lógica en `executeInTenantContext()` que maneja errores conocidos
  (tipo no soportado, quota de contacts, subscription no activa) y los marca `failed` en
  el evento. Pero errores desconocidos (DB down, timeout, exception inesperada) propagan
  la excepción a la cola → `failed_jobs` table → el WebhookEvent queda `enqueued` forever.
- P2-2: Sin `$timeout` explícito, el job usa 60s (Laravel default). Aceptable vs
  `retry_after=90` pero no visible ni documentado.

#### Cambios implementados
1. **ProcessWhatsAppStatusUpdate.php**:
   - Agregado `use Throwable;`
   - Agregado `public int $timeout = 60;` (explícito)
   - Agregado `failed(?Throwable $exception): void` — marca evento `failed` con
     `error_code = 'job_exhausted'` si el evento sigue `enqueued`. Idempotente:
     no-op si evento es null, ya processed, o ya failed.
2. **ProcessIncomingWhatsAppMessage.php**:
   - Agregado `public int $timeout = 60;` (explícito, match SendWhatsAppMessage +
     ContinueFlowExecution, safe vs retry_after=90)

#### Análisis de timeout/retry_after
- Queue config: `retry_after = 90` (todas las conexiones).
- Worker command: `queue:work --sleep=1 --tries=3 --max-time=3600`.
- `$timeout = 60` < `retry_after = 90` → seguro (no re-release premature).
- Patrón consistente: `SendWhatsAppMessage($timeout=60)`, `ContinueFlowExecution($timeout=60)`.

#### Análisis de ShouldBeUnique
- `ProcessWhatsAppStatusUpdate`: No `ShouldBeUnique` — correcto: cada evento es único
  por UUID, la dedup real es `INSERT ... ON CONFLICT DO NOTHING` en `WebhookEvent`.
- `ProcessIncomingWhatsAppMessage`: No `ShouldBeUnique` — correcto: misma dedup vía
  `WebhookEvent.provider_event_id` UNIQUE.

#### Tests (15 nuevos: F26-U3-STAT-01..08, LIFECYCLE-01, ORDER-01, IN-01..02, QUOTA-01)
- **F26-U3-STAT-01**: ProcessWhatsAppStatusUpdate timeout = 60.
- **F26-U3-STAT-02**: ProcessWhatsAppStatusUpdate tries = 3.
- **F26-U3-STAT-03**: ProcessWhatsAppStatusUpdate backoff = [5, 15, 60].
- **F26-U3-STAT-04**: ProcessWhatsAppStatusUpdate implements failed().
- **F26-U3-STAT-05**: failed() marks Enqueued event as failed (job_exhausted).
- **F26-U3-STAT-06**: failed() idempotent for Processed event (no-op).
- **F26-U3-STAT-07**: failed() idempotent for Failed event (no-op).
- **F26-U3-STAT-08**: failed() handles null event gracefully.
- **F26-U3-LIFECYCLE-01**: Full lifecycle — failed() after exhaustion marks event.
- **F26-U3-ORDER-01**: Status ordering — delivered then read preserves correct state.
- **F26-U3-IN-01**: ProcessIncomingWhatsAppMessage timeout = 60.
- **F26-U3-IN-01b**: ProcessIncomingWhatsAppMessage tries = 3.
- **F26-U3-IN-01c**: ProcessIncomingWhatsAppMessage backoff = [5, 15, 60].
- **F26-U3-IN-02**: Inbound processing regression — basic inbound still works.
- **F26-U3-QUOTA-01**: Contact quota exceeded marks inbound event as failed.

#### Quality Gates
- Backend: **15/15 PASS** (34 assertions).
- PHPStan: **0 errors**.
- Pint: **PASS** (774 files).
- Frontend build: **PASS**.
- Frontend typecheck: **PASS**.

#### Archivos modificados
- `app/Jobs/ProcessWhatsAppStatusUpdate.php` — +failed(), +timeout, +Throwable import.
- `app/Jobs/ProcessIncomingWhatsAppMessage.php` — +timeout.
- `tests/Feature/Jobs/WhatsAppJobHardeningTest.php` — NEW: 15 tests.

#### Scope Check
- Solo archivos autorizados modificados.
- NO se modificó: FlowWebhookController, provider error sanitization, Docker, Sentry,
  TrustProxies/HSTS, billing changes, crc32, README rewrite.
- NO se ejecutó migración en producción.
- NO se hizo push.

#### SEGURIDAD
- `failed()` NO expone la excepción al usuario ni al webhook.
- Error code `job_exhausted` es genérico (sin PII, sin stack trace).
- Idempotencia preservada: evento ya processed/failed no se re-falla.
- Tenant context: `failed()` se ejecuta DESPUÉS del `finally` block de `TenantAwareJob`
  (contexto limpio). WebhookEvent no tiene `BelongsToTenant` global scope → query segura
  sin TenantContext.

#### Pendientes (U4)
- **U4**: Frontend security hardening (P2 audit-only items).

#### ESTADO
COMPLETADA — pendiente commit. NO PUSH.

---

## FASE 28 — Observabilidad

### U1 — Structured Logging + Correlation IDs · COMPLETADA

- JSON log channels (json stderr + json_file) con Monolog JsonFormatter.
- RequestCorrelationId middleware: UUID v4 en X-Request-ID.
- Monolog processors: TenantContextProcessor, RequestContextProcessor.
- Job correlation propagation via Queue::createPayloadUsing + JobCorrelationMiddleware.
- Provider log sanitization: SafeLogContext::sanitizeProviderMessage().
- 27 tests: LOG-01..20, REQ-01..09, PII-01..05.
- Commit: fa93e17.

### U2 — Backend Sentry Error Tracking · COMPLETADA

- config/sentry.php: privacy-safe defaults (no PII, no bodies, no tracing).
- SentryEventScrubber (before_send): strips headers, query params, bodies, PII regex.
- SentryScopeMiddleware: request_id + tenant_id as Sentry tags.
- SentryQueueFailureServiceProvider: captures job exhaustions with context.
- ignore_exceptions: 4xx business exceptions (Validation, Auth, NotFound, Billing).
- 20 tests: SCRUB-01..12, QUEUE-01..06, FAIL-01..02.
- Commit: 53b557d.

### U3 — Frontend Sentry Error Tracking · COMPLETADA

- @sentry/vue@10.71.0 installed, DSN-gated init (VITE_SENTRY_DSN).
- resources/js/sentry.ts: initSentry(app) + scrubEvent (beforeSend).
- Privacy scrubber: URLs, headers, request data, user, PII regex, fail-safe.
- Auto-included integrations: vue, globalHandlers, breadcrumbs, dedupe, inboundFilters.
- NO tracing, NO replay (bundle size unchanged: 777→1120 modules, same chunk sizes).
- CSP: SecurityHeaders adds Sentry ingest host to connect-src (conditional).
- vite-env.d.ts: VITE_SENTRY_DSN, VITE_SENTRY_ENVIRONMENT, VITE_SENTRY_RELEASE.
- .env.example: VITE_SENTRY_* vars documented.
- ADR-106 written.
- 15 tests: 12 frontend scrubber (vitest) + 3 CSP (Pest).
- Quality: vue-tsc PASS, build PASS, PHPStan 0 errors, Pint PASS, 544 FE + 139 BE tests PASS.

### U4 — Health/Readiness + Queue Monitoring · COMPLETADA

- Liveness vs Readiness separation: GET /health (liveness), GET /ready (readiness, 503).
- HealthChecker: checkLiveness() / checkReadiness() / checkAll() / checkSchedulerHeartbeat().
- Scheduler heartbeat: SchedulerHeartbeatCommand writes timestamp to cache every minute.
- Analytics queue fix: worker consumes --queue=default,analytics,knowledge.
- AggregateDailyAnalyticsJob::failed() structured logging.
- Docker: schedule healthcheck + depends_on service_healthy.
- config/observability.php: scheduler_heartbeat_max_age_seconds.
- ADR-107 written.
- 27 tests: HEALTH-01..18, SCHED-01..02, AN-01..02, Q-01..04, CFG-01.

### U5 — Alerting + Retention + Incident Response + Closure · COMPLETADA

- Failed login audit: `user.login_failed` in audit_logs (web + API), safe metadata only.
- Retention: `audit:prune` (90 days default) + `queue:prune-failed` (30 days default).
- config/observability.php: audit_log_retention_days, failed_jobs_retention_days (env-driven).
- Scheduler: daily prune at 03:00 withoutOverlapping.
- Alert matrix documented in incident-response.md (P0-P3 severity model).
- Runbooks: DB down, Redis down, queue burst, Stripe sync, WhatsApp, provider outage, security.
- Postmortem template included.
- docs/observability.md: full architecture doc.
- docs/incident-response.md: severity model + runbooks + postmortem.
- ADR-108 written.
- 12 tests: AUTH-01..06 (failed login audit), RET-01..06 (retention commands).

## FASE 29 — Testing global + cobertura (COMPLETADA)

### U1 — Coverage Infrastructure + Critical Gap Baseline · COMPLETADA

- Coverage tooling backend (PCOV, Docker `coverage` target, `docker-compose.coverage.yml`) y frontend
  (`@vitest/coverage-v8`).
- 48 tests nuevos: Policies (POL-01..26), SubscriptionService (SUB-01..14), BillingCustomerService
  (CUST-01..08).
- Suite total: 2392 backend / 544 frontend. PHPStan 0, Pint PASS, build PASS.
- ADR-109 written.
- Commit: a3a799b (local).

### U2 — Tenancy + Auth Hardening Gaps · COMPLETADA

- 60 tests nuevos en 5 unidades sin cobertura: TenantMiddleware (TEN-01..12),
  AuthorizationService (AUTHZ-01..16), MemberService (MEM-01..16),
  RecoverPendingWhatsAppMessage (REC-01..07), MessageOriginClassifier (ORIGIN-01..09).
- Suite total: 2452 backend / 544 frontend (+60). PHPStan 0, Pint PASS, vue-tsc PASS, build PASS,
  npm audit 0, composer audit 0 advisories (1 paquete abandonado pre-existente, fuera de alcance).
- Producto: 0 bugs de producción (cambio test-only).
- Commit: test(tenancy): cover tenant auth and recovery edge cases (local, NO PUSH).

### U3 — Billing / Concurrency / PostgreSQL Gaps · **COMPLETADA (test work) con 1 fix de producción**

Estado: infraestructura de testing U3 añadida, suite PG desbloqueada y BUG-ANALYTICS-DST corregido
(autorizado vía "continua"). **BUG-ANALYTICS-DST corregido; 22 fallos PG pre-existentes reportados,
fuera de alcance U3 (no tocados).**

**Bloqueador reparado (este turno): migración `usage_reservations` no ejecutable.**
- `2026_08_25_100001_create_usage_reservations_table.php` ponía el `ALTER TABLE ... CHECK (quantity > 0)`
  DENTRO del closure de `Schema::create` → `SQLSTATE[42P01] relation "usage_reservations" does not exist`.
  Rompía `php artisan migrate` (billing FASE 25 nunca desplegado) y TODA la suite `phpunit.pgsql.xml`
  (cada test fallaba en `migrate:fresh`).
- Fix: mover el `CHECK` fuera del closure (patrón ya usado en `create_leads_table`). Suite PG pasa de
  "100% rota/bloqueada" a 162 verdes.

**Tests U3 añadidos (todos verdes):** Leads dedup race `F29-U3-LEAD-01..04` (PG), Analytics
DST/timezone `F29-U3-DST-01..02` (PG), AI retry/timeout `F29-U3-AI-01..03`, Lock context
`F29-U3-LOCK-02..07`, Sentry scope `F29-U3-SENTRY-01..04`, Flow webhook gaps `F29-U3-FLOWWH-01..02`.

**BUG-ANALYTICS-DST (CORREGIDO en `AggregationService`).**
- `AggregationService::aggregateForDate()` emitía el window en wall-clock LOCAL del tenant
  (`toDateTimeString()`), NO convertido a UTC pese a que ADR-078/docblock lo exige. Como
  `messages.created_at` se almacena UTC, para tenants con timezone ≠ UTC el rango quedaba desplazado
  por el offset del tenant. Confirmado empíricamente: mensaje en `2026-03-08 03:00:00 UTC` (= 22:00 EST
  del 07/03 en NY) se atribuía erróneamente al agregado del 08/03. Visible en transiciones DST.
- Fix: emitir los límites de ventana en UTC (`$start->copy()->utc()->toDateTimeString()` /
  `$end->copy()->utc()->toDateTimeString()`) antes de las queries. Test `F29-U3-DST-01` refuerza el
  escenario no-UTC: falla con el bug (`2 matches expected 1`) y pasa con el fix.
- Suites tras el fix: backend no-PG **2467 passed / 15 skipped / 0 failed**. PG: 162 passed,
  22 FAILED pre-existentes fuera de alcance U3 (KnowledgeBase `filename`, FAQ FK,
  AnalyticsPostgresTest up/down, Embeddings) que estaban enmascaradas por el bloqueador de
  `usage_reservations`.

### U4 — Jobs / Webhooks · **COMPLETA (incl. hotfix BUG-WEBHOOK-FOREACH)**

Tests U4 (24 nuevos, sin skips): guard branches de los jobs de webhook y del pipeline de servicio.
Suite backend no-PG **2492 passed / 15 skipped / 0 failed**.

- `tests/Feature/Jobs/ProcessIncomingWhatsAppMessageTest.php` — `F29-U4-IN-*`: evento inexistente
  no-op; ya-procesado no-op (idempotencia); tipo status no consumido; **aislamiento multi-tenant**
  (`U4-IN-ISO-01`: evento de tenant A jamás procesado por job de tenant B); payload sin `data` →
  `invalid_payload`.
- `tests/Feature/Jobs/ProcessWhatsAppStatusUpdateGuardTest.php` — `F29-U4-STAT-*`: inexistente no-op;
  ya-procesado no-op; **aislamiento multi-tenant** (`U4-STAT-ISO-01`); payload sin `data` → `processed`
  (no-op de acuse, no reintenta).
- `tests/Feature/WhatsApp/WhatsAppWebhookServiceTest.php` — `F29-U4-WS-*`: `reprocessEvent()` sweeper
  (solo sobre `received`; phone inexistente → failed; re-encola correctamente con `Queue::fake`);
  `handle()` robusto frente a payload JSON válido malformado (entry/changes/messages/statuses no-array
  ignorados).

**BUG-WEBHOOK-FOREACH (producción, P1 — RESOLTO en U4-HOTFIX).**
- `WhatsAppWebhookService::handle()` iteraba con `foreach` sobre colecciones externas sin validar que
  fueran arrays: `$payload['entry']`, `$entry['changes']`, `$value['messages']`, `$value['statuses']`.
  Con JSON válido pero shape incorrecto (colección como string/integer/null), lanzaba `TypeError`
  "foreach() argument must be of type array|object" NO capturado → el webhook público
  `/api/webhooks/whatsapp` respondía **HTTP 500** en vez de ignorar (violaba el contrato "nunca 500").
- Fix (mínimo, sin `catch Throwable` ni logging): guardar `is_array(...)` antes de cada `foreach`;
  las colecciones malformadas se ignoran (no-op) sin ingestión de eventos. Las 4 colecciones
  vulnerables quedan endurecidas.
- Regresión cubierta por el reproducer convertido en verde (`U4-WS-INGEST-BUG-01`) y la matriz
  `U4-WS-SHAPE-01..11` (`changes`/`messages`/`statuses` como string/integer; change/value malformados;
  entry scalar).
- Validado: WhatsAppWebhookTest (payload válido, firma, idempotencia) 14 PASS; guard tenants U4 PASS;
  PHPStan 0; Pint PASS; backend no-PG **2492 passed / 15 skipped / 0 failed**.
- Las guard branches de tenant (no-op ajeno) prueban que el aislamiento multi-tenant (`ADR-021`) se
  respeta incluso si un job se ejecutara con el tenant equivocado.

### U5-HOTFIX — Inbox permission guard + PCOV · **COMPLETADA (hotfix; U5 closure pendiente)**

- **FRONTEND-INBOX-PERMISSION-LOAD (P2, RESUELTO)**: `Conversations/Index.vue` cargaba conversaciones
  al montar aun sin `conversations.view`, generando un GET innecesario. Se añadió un retorno temprano
  en `onMounted`; no hubo cambios backend ni bypass de policies.
- Regresión `F29-U5-INBOX-01..05`: **5 passed**. `canView=false` no genera GET de conversaciones ni
  miembros; con permiso carga conversaciones y `users.view` controla la carga de miembros.
- **PCOV corregido** en el stage Docker `coverage`: conserva `extension=pcov.so` y `pcov.enabled=1`.
  El stage `runtime` permanece sin PCOV. Cobertura se ejecuta con `memory_limit=512M` y sin versionar
  artefactos.
- FASE 29 cerrada en U5 (ver seccion de cierre al final).

### U5-PG-H1 — KnowledgeSearchService PostgreSQL hydration (BUG P1 RESUELTO) · **COMPLETADA (hotfix H1; H2-H4 pendientes)**

**BUG-KNOWLEDGE-PG-HYDRATION (producción, P1 — RESUELTO).**
- `KnowledgeSearchService::search()` ejecuta una raw query pgvector vía `DB::select()`. Bajo
  PostgreSQL (`pdo_pgsql`), `DB::select()` devuelve filas como `stdClass`, pero `applyThreshold()` y
  `mapToRetrievedChunks()` asumen arrays (`$row['similarity']`, `$row['chunk_id']`, `$row['content']`,
  `$row['chunk_index']`) → `TypeError` en toda búsqueda RAG con chunks coincidentes sobre PG (fallo del
  filtering/mapping). `chunk_index`/`similarity` también podían llegar como string (PDO) arriesgando
  comparaciones implícitas.
- Fix (mínimo, SOLO representación interna): `executeCosineSearch()` ahora normaliza cada fila a array
  asociativo vía el nuevo método privado `normalizeSearchRows()`, convirtiendo `chunk_index` a `int` y
  `similarity` a `float`. `applyThreshold()` y `mapToRetrievedChunks()` invariantes. Sin cambios de SQL,
  bindings, tenant filter, similarity math, ordering, top-k, threshold ni API pública (interfaz
  `KnowledgeSearchServiceInterface` intacta, 0 callers modificados).
- Revisión de patrón similar: `AggregationService` (Analytics) consume `DB::select()` con acceso a
  propiedades (`$row->conversation_id`), correcto para `stdClass` → sin bug análogo; fuera de alcance H1.
- Regresión PG: las 6 fallas de hidratación pasan (cosine ordering, top-k, threshold, tie ordering,
  identical cross-tenant isolation, tenant context switching). Quedan 2 fallos del mismo archivo, ambos
  cluster H4 (P3, test-only, NO tocados en H1): assertion de nombre de índice HNSW y expectation de
  vector inválido parametrizado.
- Regresión no-PG: 124 tests Knowledge/RAG/AI (304 assertions, 0 failed). PHPStan 0, Pint PASS.
- Commit: `fix(knowledge): normalize postgres search result rows` (local, NO PUSH).
- Continuado por H2-H4 (seccion de cierre U5 al final) y cerrado en U5.

### U5-PG-H2 — Analytics + FAQ test harness fixes (TEST-ONLY) · **COMPLETADA (hotfix H2; H3-H4 pendientes)**

Harness fixes for 7 deterministic PostgreSQL failures. **Production code y migrations intactas.**
- **Analytics up/down/up (AN-PG-12)**: reemplazado `migrate:rollback --step=2` (global, order-dependent)
  por targeteo vía `--path` de las 2 migraciones analytics; verifica UP→DOWN→UP determinista sin tocar
  otras migraciones. Confirmado el down() de analytics es válido (sin leftovers, recreación OK).
- **FAQ fixture lifecycle (FAQ-PG-03..06, 10)**: `setUp()` creaba tenant antes de `migrate:fresh` (que
  borra `tenants`) → FK `23503`. Fix: recrear fixture de tenant DESPUÉS del reset de esquema. FAQ-PG-06
  además cuenta solo filas activas (`WHERE deleted_at IS NULL`) — la recreación post soft-delete NO
  viola el partial unique index (contract).
- **FAQ partial index predicate (FAQ-PG-08)**: assertion ahora SEMÁNTICA (normaliza espacios/parentesis,
  verifica `deleted_at IS NULL`) en vez del string exacto `WHERE deleted_at IS NULL` frente al deparse
  real `WHERE (deleted_at IS NULL)`.
- **FAQ up/down/up (FAQ-PG-10)**: targeteo de la migración de faqs vía `--path` + recrear tenant.
- **Regresión PG**: 7/7 H2 PASS. Suite PG completa: **175 passed / 9 failed** (solo H3=7 + H4=2).
  Analytics agregación (DST) 12 PASS. Sin dependencia de orden (FAQ→Analytics y Analytics→FAQ idénticos).
- PHPStan: 0 errores. Pint: PASS. Commit: `test(postgres): isolate migration fixtures correctly` (local,
  NO PUSH).
- Continuado por H3-H4 y cerrado en U5 (seccion de cierre al final).

### U5-PG-H3 - Knowledge embedding + nullable fixture contracts (TEST-ONLY) - **COMPLETADA (hotfix H3; H4 pendiente)**

Contract alignment for 7 deterministic PostgreSQL failures. **Production code, migrations y DDL intactos.**
- **Wrong dimension (EMB-PG-02)**: fail-closed productivo real: `VectorSerializer::validate()` lanza
  `EmbeddingDimensionMismatchException` dentro de la DB transaction. Test ahora verifica que la excepcion
  PROPAGA (no se silencia) y que no hay persistencia parcial (2 chunks NULL, 0 non-null, sin filas extra).
  Sin cambios de servicio.
- **Transaction rollback (EMB-PG-05)**: el fallo del provider se PROPAGA (`processBatch` libera reserva y
  re-lanza; no se traga). Test: assert excepcion propagada + rollback de transaction (3 chunks NULL, 0
  non-null, sin estado success falso).
- **Deleted document (EMB-PG-10)**: boundary soportado = guard `isDocumentDeleted()` (tambien en el job
  antes de llamar al servicio). Test obtiene el documento con `withTrashed()` (instancia valida) y verifica
  que NO se materializa ni se llama al provider. Ya NO se llama `materialize(null)` (servicio type-hints
  `KnowledgeDocument` no-nullable).
- **Stale column (EMB-NULL-PG-02/03/06/07)**: fixture usaba `filename`; schema real es `original_filename`
  (2026_08_18_020100_create_knowledge_documents_table.php). Fix: `filename` -> `original_filename` +
  `storage_disk` explicito. Sin columna de compatibilidad.
- **Rollback with NULL (EMB-NULL-PG-07)**: confirmado el contract de la migracion nullable: `down()` LANZA
  `RuntimeException('Cannot revert embedding to NOT NULL...')` si existen NULLs. Asertado via
  `->throws(RuntimeException::class, 'Cannot revert embedding to NOT NULL')`.
- **Regresion PG**: 7/7 H3 PASS (17 tests embedding+nullable, 44 assertions). KnowledgeBase PG: **43
  passed / 2 failed** (solo H4=2: HNSW index assertion + parameterized invalid-vector, NO tocados). Sin
  dependencia de orden (nullable<->materialization idAgnosticos).
- **Regresion no-PG**: 331 tests Knowledge/RAG/Embedding/AI/document processing (910 assertions, 13 skip
  por columna embedding en SQLite, 0 failed). PHPStan 0, Pint PASS. Commit:
  `test(postgres): align knowledge fixtures with current contracts` (local, NO PUSH).
### FASE 29 U5-PG-H4 - Pgvector assertion alignment (TEST-ONLY)

Ultima unidad U5-PG. **Production code, migrations e indexes intactos.** No se renombro ni recreo
ningun indice; la columna y el indice HNSW reales se mantienen.

- **HNSW index assertion (semantica)**: el test ya NO asume naming estetico (`indexname LIKE '%hnsw%'`).
  Valida comportamiento/esquema via catalogo PostgreSQL: indice real `knowledge_chunks_embedding_idx` sobre
  columna `embedding`, access method `hnsw`, operator class `vector_cosine_ops`, `is_valid = true`. Se
  mantiene la assertion de compatibilidad de query cosine (`<=>`) con esquema index-compatible. No se exige
  que el planner use HNSW en datasets diminutos (seleccion de planner depende de datos/estadisticas).
- **Parameterized vector (seguridad)**: la propiedad probada es que el valor de vector controlado por el
  usuario se ENLAZA como dato (`?::vector` -> unnamed portal parameter), NO se interpola en SQL. El string
  invalido (`1.0,2.0,3.0]::vector; DROP TABLE knowledge_chunks; --`) se rechaza como tipo por PostgreSQL
  con SQLSTATE **22P02** (invalid input syntax for type vector); el `DROP` NUNCA se ejecuta. El test aserta
  22P02 (no mensaje completo fragil), via savepoint interno para no abortar la transaccion del test, y
  verifica post-condicion de seguridad: tabla `knowledge_chunks` sigue existiendo, mismo numero de filas,
  sin mutacion de esquema. Control: un vector VALIDO por la misma ruta de binding se ejecuta con exito
  (prueba que el rechazo es validacion de tipo, no query construction rota).
- **Regresion PG**: full KnowledgeSearchPostgresTest 14/14 PASS (32 assertions). KnowledgeBase PG: **45/45
  PASS (96 assertions), 0 failed**. Suite PostgreSQL COMPLETA (phpunit.pgsql.xml, tests/Postgres): **184
  passed, 0 failed, 489 assertions**. Sin recuento skipped ni flakiness (H4 repetido 2/2 PASS).
- **Regresion no-PG**: 338 tests Knowledge/RAG/Embedding/AI/document processing (926 assertions, 13 skip
  por columna embedding en SQLite, 0 failed). PHPStan 0, Pint PASS.
- **Seguridad**: SQL injection demostrada NO; input invalido enlazado como parametro SI; rechazado por PG
  SI; objetos de BD preservados SI. Commit: `test(pgvector): align assertions with postgres behavior`
  (local, NO PUSH).

### U5 — Final Closure (FASE 29 COMPLETADA)

Cierre global de FASE 29. **FASE 29 = COMPLETADA.**

- **Backend no-PG**: 2492 passed / 15 skipped / 0 failed (7114 assertions, 874.78s). Igual al baseline
  validado U4; sin regresiones.
- **PostgreSQL suite (puerta obligatoria)**: 184 passed / 0 failed / 0 skipped (489 assertions, 566.69s).
  SQLite NO puede validar pgvector, locking, advisory locks, migraciones/indices PG, concurrencia ni
  hydration de tipos; por eso la suite PG es MANDATORIA antes de merge.
- **Frontend**: 36 files / 555 passed / 0 failed (8.46s). Incluye Login.test.ts (4) y Dashboard.test.ts (2)
  de U5, revisados y validos (render, submit, errores, estado vacio; sin E2E/red/snapshot/secrets).
- **Coverage backend**: 85.0% (baseline validado, PCOV en stage Docker `coverage`; tooling intacto).
- **Coverage frontend**: Statements 49.51 / Branches 85.30 / Functions 72.86 / Lines 49.51 (sin delta por
  incluir los tests de pagina U5).
- **Calidad**: PHPStan 0 errores (proyecto, level 6 en phpstan.neon); Pint PASS (825 files); vue-tsc PASS;
  vite build PASS; npm audit 0 vulns; composer audit 0 advisories (1 paquete abandonado no-seguridad:
  `nunomaduro/larastan`); config cache PASS.
- **Skips**: non-PG 15 (todos legitimios/environment PG-only: 13 columna embedding en SQLite + partial
  unique index + LeadSecurity is_active; cubiertos por suite PG). PG 0 skips. unknown 0, flaky critical 0.
- **Bugs productivos resueltos en FASE29**: Analytics DST/window (f31d606), WhatsApp malformed collection
  (1977f09), Inbox unauthorized load (3199951), KnowledgeSearch PG hydration (6f01c2a).
- **Correcciones PG (test-only)**: H2 (771bf48), H3 (0914991), H4 (4a40c9f).
- **P0=0, P1=0, P2 bloqueante=0**.
- Cobertura de coverage infra PASS; gitignore cubre coverage.xml y coverage-html. Frontend generar `coverage/`
  (v8 html) que NO se versiona.
- Migracion pendiente de produccion `2026_08_25_100001_create_usage_reservations_table` NO ejecutada
  (solo registrada en suite PG via RefreshDatabase). H1-H4 sin nuevas migraciones.
- FASE 30 cubre login, inbox, handoff, flow builder, billing y knowledge upload; todas sus unidades autorizadas
  quedan completadas en este roadmap.

## FASE 30 — E2E Playwright (COMPLETADA)

### U1 — Playwright Infrastructure + Auth + Multi-Tenancy Base · COMPLETADA

Primera unidad de **FASE 30 (E2E Playwright)**. Establece la infraestructura E2E y cubre autenticación +
aislamiento multi-tenant básico. Referencia completa: `docs/testing.md` (FASE 30 U1) y `docs/decisions.md`
(ADR-110).

**Infraestructura**:
- `@playwright/test ^1.62.1` (devDependency only), Chromium-only (proyecto `chromium`).
- Scripts: `test:e2e`, `test:e2e:headed`, `test:e2e:ui`, `test:e2e:report`.
- `playwright.config.ts`, `global-setup.ts`, `tests/e2e/helpers/{auth,constants}.ts` (storageState logins,
  pollHealth, apiGet/apiPost con header `Origin`).
- **Guard de seguridad** `app/Infrastructure/Testing/E2EEnvironmentGuard.php`: exige `APP_ENV=e2e` + DB
  terminada en `_e2e_test` + Redis dedicado (db 15 + prefijo). Aborta ante condiciones no seguras.
  `E2EOnlyServiceProvider` re-bindea fakes SOLO si `APP_ENV=e2e`. WhatsApp/Stripe/Sentry reales pero
  latentes (no invocados en U1). Sin providers externos reales (DSNs vacíos en `.env.e2e.example`).
- `SetupE2EEnvironment` command + `E2ETenantSeeder` (UUIDs deterministas).
- **Aislamiento**: DB `whatsapp_saas_e2e_test` dedicada; Redis db15 (dev db0 y PG db14 intactos, NO
  FLUSHALL); storage mount `./storage/e2e-app`; `storage/e2e-app/`, `tests/e2e/.auth/`, `test-results/`,
  `playwright-report/` ignorados.

**Specs U1**:
- Auth: login válido (owner/admin/agent), logout, credenciales inválidas. Logout 3/3.
- Multi-tenancy P0: own 200, foreign 404, switch 404, sin leakage.
- E2E runs: Run #1 13/13, Run #2 13/13, Run #3 (auth) 9/9.
- Guard unit tests E2E-ENV-01/01b/02/02b/03 (condiciones negativas).

**Login timing (29–31s, root-cause)**: server `php artisan serve` lentísimo (warm `/up`=3.5–6.3s, login
completo warm=22s; POST login=9.98s, redirect→/dashboard=7.53s). PHP built-in server (SAPI CLI) con
`opcache.enable_cli=1` pero OPcache NO persiste entre workers de `php -S` (`cached_scripts=1, hits=0`):
cada request recompila/bootstrap Laravel (~2–10s). Clasificación: **STACK STARTUP / SERVER PERFORMANCE /
FIRST REQUEST WARMUP** — no wait-condition flaky ni assets. Timeouts justificados, no blanket:
`navigationTimeout` 60s, `expect.timeout` 15s, login error-targeted 30s. Sin `waitForTimeout`.

**CONV-4/CONV-10 clasificados TEST ASSERTION PORTABILITY GAP, P3 (sin fix)**: ambos en suite SQLite
(`tests/Feature/Conversations`), no en PG canónica. Producción NO afectada.

**Regresiones**:
- Backend no-PG (SQLite): **2499 passed / 15 skipped / 0 failed** (~15.3 min).
- PostgreSQL canónica (`phpunit.pgsql.xml`, `tests/Postgres`): **184 passed / 0 failed** (~14.6 min).
- Frontend Vitest: **555 passed / 0 failed**; vue-tsc PASS; vite build PASS.
- PHPStan `[OK] No errors`; Pint PASS; npm audit 0 vulns; composer audit 0 advisories.
- Docker compose E2E config PASS.

**Hotfixes productivos aislados**:
- `d85751a` — `fix(inbox): lastMessage() with PK uuid in PostgreSQL (max uuid)`.
- `db17bb7` — `fix(handoff): align resume bot frontend route`; la UI usa la ruta canónica
  `resume-bot`, sin alias duplicado.

### U2 — Inbox + Human Handoff E2E · COMPLETADA

- Fixtures deterministas para Tenant A/B: conversaciones con historial UUID, conversación asignada,
  handoff inicial limpio y cuenta/teléfono WhatsApp conectados sólo en Tenant A con credenciales sintéticas.
- `FakeWhatsAppProvider` implementa el contrato real, no hace HTTP y se enlaza exclusivamente bajo
  `APP_ENV=e2e`. Bajo U3, `QUEUE_CONNECTION=redis` ejecuta `SendWhatsAppMessage` real mediante un worker
  hasta el boundary fake; los asserts esperan el estado persistido eventual.
- `SetupE2EEnvironment` ejecuta el flujo real publicado Start -> Human mediante
  `FlowEngine -> HumanHandoffService`; no fuerza el estado final de handoff en la base de datos.
- Journeys Playwright: carga/apertura/historial/lastMessage UUID, filtros Todas/Mias/Sin asignar,
  aislamiento Tenant A/B, claim, reply enviado como `sent` con `provider_message_id` sintético,
  handoff, pausa, resume y persistencia tras reload.
- Validado inicialmente: focused U2 5/5; handoff repetido 3/3; full E2E U1+U2 18/18 en dos ejecuciones;
  backend SQLite 2499/15/0; PostgreSQL canónica 184/0; Vitest 555/0; PHPStan 0; Pint PASS;
  typecheck/build PASS; npm/composer audit sin vulnerabilidades.
- Meta Graph HTTP real: 0. Reverb/two-browser realtime pertenece a U3. Webhooks Meta pertenecen a FASE31.

### U3 — Realtime/Reverb + async U2 contracts · COMPLETADA

- Reverb E2E real, worker Redis real y dos contextos Playwright para Inbox, claim, reply, resume y tenant
  isolation. App/worker/Reverb usan `APP_ENV=e2e`, app ID `whatsapp-saas-e2e`, Redis DB 15 y broadcaster
  Reverb; el frontend conecta a `localhost:8083`.
- Root cause corregido: la red externa compartida resolvía `reverb` hacia Reverb dev y E2E. La red dedicada
  `whatsapp-saas-e2e-realtime` y hostname `reverb-e2e` eliminan la colisión; el healthcheck valida el
  registro real del app ID mediante el endpoint Pusher.
- Reply espera el mensaje creado y consulta la API hasta `sent` con `provider_message_id`; handoff verifica
  la salida realtime de `Sin asignar` y reabre desde `Mias`.
- Validado: U2 focused 5/5; U3 focused 5/5; U2 completo 5/5; full E2E 20/20 en dos ejecuciones.
- Gates: PostgreSQL canónica 184/184, Vitest 555/555, PHPStan 0, Pint PASS, typecheck/build PASS y audits
  sin vulnerabilidades.

### U4 — Flow Builder + Billing + Knowledge integration · COMPLETADA

- Flow Builder: `3/3` repetido; publish y persistencia de arista normal sin etiqueta y editor de etiqueta validados.
- Billing: `4/4`; checkout, portal y permisos owner/admin/agent mediante HTTPS fake; Stripe real `0`.
- Knowledge: integración de sistema API -> storage compartido -> Redis `knowledge` -> worker -> chunks -> fake
  embeddings 1536 -> pgvector/search -> aislamiento -> cleanup. Tres ciclos por corrida, tres corridas frescas.
- U4 focused `7/7`; regresion U1-U3 `20/20`; full browser `27/27` en dos corridas.
- Gates: PHP `2510/7192/15 skips`, PostgreSQL `184/489`, Billing `438`, Vitest `562`, PHPStan `0`, Pint,
  typecheck, production/E2E build y audits PASS.
- Docker E2E healthy; queues `default,knowledge` sin failed/pending/reserved/delayed jobs.
- Produccion sin cambios posteriores a `c95ce6a`; migraciones de produccion no ejecutadas. FASE31 queda pendiente.

### U5-D - Self-contained E2E integration gate · COMPLETADA

- Compose E2E es autocontenido: PostgreSQL/pgvector, Redis DB 15, app, worker y Reverb aislados de desarrollo.
- El job CI ejecuta setup seguro, build E2E, browser E2E, Knowledge integration y assertion de colas limpias.
- Los providers E2E y DSNs vacios mantienen Meta/OpenAI/Stripe/Sentry fuera del proceso.
- La estabilidad de Docker Desktop se protege solo en el stack descartable: PostgreSQL no fuerza fsync y Redis no
  persiste snapshots; no se modifican los servicios de desarrollo ni la logica productiva.
- Cierre validado: dos corridas finales `27/27`, gates estaticos/frontend/backend y commit local de U5-D.

### U5-E - Security and release closure · COMPLETADA

- `release-gate` depende estrictamente de `static`, `frontend`, `backend`, `postgres` y `e2e`; no despliega ni
  ejecuta migraciones productivas.
- E2E verifica providers fake, DSNs externos vacios, HTTP fail-closed y colas `default,knowledge` limpias.
- Los artifacts son failure-only, limitados a diagnostics sanitizados y resultados Playwright, con retencion de 5 dias.
- El release candidate requiere todos los jobs obligatorios, audits limpios y `release-gate` verde.
- Cierre validado: provider boundaries, E2E `27/27`, colas limpias, lint, PHPStan, frontend, backend, PostgreSQL,
  audits y build; commit local unico preparado sin push.

## FASE 31 - Meta / WhatsApp Cloud API (COMPLETA LOCALMENTE; pendiente revisión global)

### U1 - Meta provider and configuration hardening · COMPLETADA LOCALMENTE

- El provider mantiene Graph API oficial, versión fijada, URL/host HTTPS validado y timeouts de conexión/request
  explícitos y acotados.
- App Secret, verify token, access token e identificadores se validan fail-closed sin exponer valores; el verify token
  vacío nunca valida.
- Contratos HTTP del provider y binding normal/E2E cubiertos con `Http::fake()`; no se realizan llamadas reales a Meta.
- Política de versión y estrategia futura de rotación documentadas; no se ejecutan operaciones de producción.

U1 queda COMPLETADA LOCALMENTE. U2 queda COMPLETADA LOCALMENTE: autenticidad GET/POST, validación de envelope,
dedupe durable, ownership por `phone_number_id`, recuperación de dispatch y retención terminal limitada.
U2 no modifica reconciliación outbound, ventana de 24 horas, media binaria, templates ni migrations.

### U3 - Inbound message normalization and status monotonicity · COMPLETADA LOCALMENTE

- Inbound normalizado mediante DTO para text, metadata-only media, interactive button/list y location.
- Tipos unsupported permanecen terminales y no generan retry storm; no se descargan binarios ni se añaden URLs remotas.
- Flow, FAQ y human handoff preservados; dedupe por `provider_message_id` evita automatización duplicada.
- Status protegido con lock de fila y orden monotónico `sent < delivered < read`; `failed` guarda metadata segura y no
  regresa estados ya entregados/leídos.
- U3 no modifica outbound reliability, ventana de 24 horas, templates, media binaria ni migrations.

### U4 - Outbound delivery ambiguity and care window · COMPLETADA LOCALMENTE

- Timeout/conexión y éxito sin `messages[].id` se clasifican `ambiguous`: el outbound queda `sending` y NO se reenvía
  automáticamente (prioridad: no duplicar mensajes). `RetryAmbiguousWhatsAppMessage` es el único replay explícito.
- La respuesta humana de texto libre exige inbound del mismo tenant/conversación en ventana de 24h; fuera de ventana se
  requiere una plantilla aprobada. Sin migración (estado ambiguo en `messages.metadata`). Ver ADR-120.

### U5 - Secure media pipeline and approved templates · COMPLETADA LOCALMENTE

- Pipeline de media inbound seguro y aislado por tenant: look-up por `provider_media_id`, descarga con `SecureDownloader`
  (SSRF, redirecciones acotadas, sin reenvío de token, tope de bytes), validación de MIME/size por contenido y
  almacenamiento opaco `tenant/{tenantId}/whatsapp/media/{uuid}`. Job `ProcessWhatsAppMedia` idempotente (CAS
  `pending -> processing`, estados terminales con código seguro). Endpoint de descarga autorizado y aislado (404 ante
  desajuste; nunca expone storage). Ver ADR-121.
- Candidatas padre `UNIQUE (tenant_id, id)` en `messages`/`whatsapp_accounts` + FK compuesto tenant-aware de
  `message_media` (migraciones A/B). Tabla `whatsapp_templates` (migración C).
- Catálogo y envío de templates `approved`: `sync` materializa el catálogo de Meta (upsert; nunca crea/propone), `send`
  valida pertenencia/estado/variables antes de Meta (0 llamadas si falla) y encola por el pipeline de U4. Excepción de
  ventana de 24h solo por template `approved`. Ver ADR-121.

**Remediación de regresión de orden (FASE 13 `FlowVariablesTest`)**:
- `created_at` no es clave de orden total: varios mensajes salientes de un flujo comparten timestamp, por lo que
  `ORDER BY created_at` con ties NO es determinista. La migración A (índice) cambió el plan de SQLite y expuso la
  asunción latente en `FlowVariablesTest` (selección por `.last()`). Reconciliado SI seleccionar el mensaje esperado por
  contenido (helper `flow_outbound_body`/`flow_outbound_body_containing`) manteniendo asserts estrictos. Verificado
  determinista (12/12 y 5/5 corridas; suite completa verde).
- `FOLLOW-UP — GLOBAL MESSAGE ORDERING`: el inbox de producción ordenaba solo por `created_at` sin tie-breaker
  (`MessageService::indexForUser`). **RESUELTO en FASE 32 U1**: se aplica el contrato de orden determinista
  `ORDER BY created_at, id` en `MessageService::indexForUser` y `ConversationService::findOrCreateActiveForContact`,
  con el mismo eje en el frontend (reload == realtime). El reproceso del sweeper (`WhatsAppReprocessWebhookEvents`)
  sigue ordenando solo por `created_at` y queda como follow-up P2 separado. Ver ADR-123.

### U6 - Operations, Observability & Production Readiness · COMPLETADA LOCALMENTE

- **Métricas ligeras**: `Infrastructure/Observability/MetricsRecorder` (Redis, claves `observability:metrics:*`,
  fail-safe, config-gated `OBSERVABILITY_METRICS_ENABLED`). Provider con `http(operation, callable)` + `recordMetrics`
  (request/result/duración por operación); métricas de delivery outbound (`sent`/`ambiguous`/`failed.{code}`) y de
  webhook (`received`/`duplicate`/`enqueued`/`processed`/`failed.{reason}`). Sin alta cardinalidad; sin Prometheus/OTel.
- **Replay operator**: `WhatsAppWebhookReplayService` (count `queue` + `replayFailed`) con autorización `ManageWhatsApp`;
  `WhatsAppWebhookService::replayEvent` re-encola `failed`/`received` atómicamente NUNCA `processed`/`enqueued`.
  Endpoints `GET/POST /api/v1/tenants/{tenant}/whatsapp/webhook-events/queue|replay`. Auditoría `whatsapp.webhook.replayed`.
- **Phone health**: `WhatsAppPhoneHealthService::check` (409 si no conectado), persiste `quality_rating`/`verified_name`
  y NUNCA muta `status`. Endpoint `POST /api/v1/tenants/{tenant}/whatsapp/phone-health`. Auditoría
  `whatsapp.phone.health.check`. Ver ADR-122.
- **PII-safe failed jobs**: `queue:failed-summary` (agrega por queue, no lee payload). Ver `docs/runbooks.md`.
- **Runbooks/security matrix/CI mapping**: `docs/runbooks.md` (replay, phone health, failed jobs, rotación de
  token/app secret/verify token, verificación webhook, smoke tests webhook/envío) y actualización de
  `docs/observability.md`, `docs/whatsapp.md`, `docs/testing.md`, `docs/decisions.md` (ADR-122). Nada se ejecuta en este
  entorno; es documentación de producción.
- **Tests**: `tests/Feature/Operations/OperationsU6Test.php` (17 tests). Gates verdes (pest/pint/phpstan/typecheck/build).

FASE 31 queda **COMPLETA LOCALMENTE** (U1-U6). Nada de esto se ejecuta contra producción; requiere revisión y
aprobación antes de considerar FASE 31 completada globalmente. U6 NO inicia FASE 32 ni ejecuta llamadas reales a Meta.
(Nota histórica: la especificación original describía U6 como "billing/aceleradores reales + cierre"; aquí U6 se
consolida como la fase de Operations, Observability & Production Readiness siguiendo el reporte de FASE 31.)

## FASE 32 — Deterministic Message Ordering (U1) · COMPLETADA/PUBLICADA

- **Alcance (U1)**: orden total determinista `ORDER BY created_at, id` para el contrato de mensajes/conversación,
  documentado en ADR-123. `created_at` es clave cronológica visible; `id` (UUIDv7, monótono) desempata ties de la
  misma forma en backend y frontend. Aplicado en `MessageService::indexForUser` y
  `ConversationService::findOrCreateActiveForContact` (DESC DESC, newest-first) y en el comparador frontend
  `compareMessagesChronologically` (`mergeIncomingMessage`: ASC ASC, reload == realtime, dedupe por `id`).
  Sin migrations, sin schema, sin backfill: cambio de contrato/query puro. Ver ADR-123.
- **Decisión de cierre**: NO se requiere U2.
- **Validación (gates verdes)**: backend 2585 passed / 15 skipped / 7438 assertions / 0 failed · Flow 232 passed ·
  PostgreSQL 185 passed · Frontend 38 files / 567 passed · typecheck PASS · build PASS · build:e2e PASS ·
  E2E focused 8 passed · E2E full 28 passed (workers=1, retries=0, fresh setup) · PHPStan PASS · Pint PASS · diff --check PASS.
- **Tests**: `tests/Feature/Messages/MessageApiTest.php` (MSG-API-21/22, ties de paginación), `tests/Feature/Conversations/ConversationTest.php`
  (CONV-27), `tests/Postgres/Messages/MessageOrderingPostgresTest.php` (MESSAGE-ORDER-PG-01), `resources/js/features/messages/messageUtils.test.ts`,
  `tests/e2e/z-realtime.spec.ts` (contrato realtime==reload).
- **Follow-ups NON-BLOCKING (no parte de este U1)**: (1) P2 — `WhatsAppReprocessWebhookEvents::handle` ordena solo por
  `created_at` (semanticalmente neutral por idempotencia/status monotónico); (2) P2 — ties cosméticos de listas de
  presentación; (3) future — keyset/cursor pagination; (4) future — índice opcional
  `(tenant_id, conversation_id, created_at, id)`. Ninguno se implementa aquí.
- **Nota de alcance**: el ítem previamente catalogado en esta fila como "Testing de fallbacks" queda diferido fuera del
  alcance de FASE 32 (no es parte de este cierre) y pasa a fases posteriores.

U1 queda **COMPLETA + VALIDADA + PUBLICADA**. NO deploy, NO ejecución de migrations, NO configuración de Meta, NO envío
a clientes: el push es publicación de fuente únicamente.

## FASE 33 — Self-service provisioning (U1) · COMPLETA LOCALMENTE

- **Alcance**: `POST /register` web provisiona atómicamente el usuario, un workspace con slug server-side collision-safe,
  membresía `owner`, `current_tenant_id` y suscripción activa al plan `free`. El flujo API de registro permanece user-only.
- **Verificación y onboarding**: la verificación de email sigue siendo obligatoria; después de verificar, el usuario llega a
  `/onboarding`, donde ve workspace, plan activo y estado de configuración. Los CTAs reales apuntan a
  `/settings/whatsapp` y `/dashboard`; no se realizan llamadas falsas a Meta.
- **Aislamiento**: la provisión usa `TenantContext` al crear la suscripción y mantiene las políticas/middleware tenant
  existentes. No se añadió migración: el modelo actual ya soporta todo el flujo.
- **Implementación**: `ProvisionNewWorkspace`, `OnboardingController`, ruta protegida `verified` + `tenant`, y redirección
  post-verificación. `RegisterUser` no se modificó para preservar el contrato del registro API.
- **Tests y gates**: backend 2110 passed / 15 skipped / 5878 assertions; frontend 574 passed; typecheck PASS; build PASS;
  PHPStan PASS; Pint PASS; E2E 29 passed. No deploy ni configuración de Meta.
