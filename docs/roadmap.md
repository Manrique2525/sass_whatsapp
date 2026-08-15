# Roadmap

Estado general: **FASE 13 COMPLETADA** (Variables de conversación). FASE 14 aún NO iniciada (requiere autorización explícita).

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

### FASE 14 — Triggers (EN CURSO — requiere autorización explícita por unidad; SIN push)

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
- [ ] **UNIDAD 1 — Commit local** `feat(flows): harden trigger validation` (SIN push).
- [ ] **UNIDAD 2** — Scheduler (disparo por cron; pendiente).
- [ ] **UNIDAD 3** — Webhook público (endpoint + verificación de token; pendiente).
- [ ] **UNIDAD 4** — Ejecución por etiqueta (ver FASE 20; pendiente).
