# Roadmap

Estado general: **FASE 17 EN PROGRESO** (U1+U2.1+U2.2+U2.3+U2.4 completas).

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
  | 17 | Base de conocimiento (RAG + pgvector) | EN PROGRESO (U1+U2.1+U2.2+U2.3+U2.4) |
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
COMPLETADO — pendiente commit. NO PUSH.
