# API

## 1. Convenciones generales

- Prefijo: `/api/v1`.
- RESTful, plural. Recurso → colección → item: `/api/v1/conversations/{id}`.
- Autenticación: ver §2 (dos modos: sesión interna + Bearer externo).
- Respuestas JSON consistentes:
  - Success: el recurso o colección (con `meta` de paginación).
  - Error: `{ "message": "...", "errors": {...}, "code": "MODULE_ERROR_CODE" }`.
- HTTP status: `200/201/204`, `400` validación, `401` sin auth, `403` sin permiso,
  `404` no existe (u oculto por tenant), `409` conflicto, `422` validación, `429` rate limit.
- Paginación: cursor-based para listas grandes (conversaciones, mensajes); page-based para
  catálogos pequeños.
- Filtros: query params tipados (`?status=open&agent=uuid`), documentados por recurso.
- Idempotencia: mutaciones con `Idempotency-Key` donde aplique (webhooks, envío).

## 2. Autenticación (dos modos)

- **Interno (SPA Inertia)**: Laravel Sanctum en **modo stateful** (cookies + CSRF) para las
  páginas y las llamadas API del frontend en el mismo origen. `auth:web` + Sanctum middleware
  stateful. No se usan Bearer tokens en el navegador.
- **Externo (integración/partners)**: tokens Bearer Sanctum (`personal_access_tokens`) con
  scopes. Los tokens expiran y se rotan.
- Ambos modos pasan por el middleware `tenant` (que resuelve el tenant desde
  `users.current_tenant_id`). Ver ADR-011.

### Endpoints auth (implementados en FASE 2)

| Método | Ruta | Descripción | Detalle |
|---|---|---|---|
| POST | `/api/v1/auth/register` | Registro (web + API) | 201 + `{message, token, user}`. API no verifica email automáticamente; envía notificación de verificación |
| POST | `/api/v1/auth/login` | Login API | 200 + `{message, token, user}`. Credenciales inválidas → 422 genérico (no revela cuál falló) |
| POST | `/api/v1/auth/logout` | Revoca el token actual | Requiere `auth:sanctum` |
| POST | `/api/v1/auth/forgot-password` | Solicita reset | 200 con mensaje genérico (nunca revela si el email existe) |
| POST | `/api/v1/auth/reset-password` | Confirma reset | Token inválido → 422 `INVALID_RESET_TOKEN`. Revoca tokens del usuario |
| GET  | `/api/v1/auth/me` | Usuario + tenants + rol activo | Requiere `auth:sanctum`. Devuelve `{user, tenants[], current_tenant, current_tenant_id, roles[], current_role, permissions[], is_super_admin}` |
| POST | `/api/v1/tenants/{tenant}/switch` | Cambia `users.current_tenant_id` (valida membresía) | **FASE 3**. Implementado en el recurso `tenants`, ver §3.1 |

### Rate limits (FASE 2)

| Limiter | Límite | Clave |
|---|---|---|
| `auth-login` | 10/min | `email` o IP |
| `auth-register` | 5/min | IP |
| `auth-password` | 3/min | `email` o IP |

Respuesta 429 con `code: "RATE_LIMITED"`.

### Error estándar API (implementado en FASE 2)

Todos los errores de `/api/v1/*` usan `{message, code, errors}`:

| Situación | HTTP | `code` |
|---|---|---|
| Validación de FormRequest | 422 | `VALIDATION_ERROR` |
| No autenticado | 401 | `UNAUTHENTICATED` |
| Rate limit | 429 | `RATE_LIMITED` |

### Rutas web (Inertia, sesión + CSRF)

| Método | Ruta | Descripción |
|---|---|---|
| GET/POST | `/login` | Iniciar sesión (throttle `auth-login`) |
| GET/POST | `/register` | Registro (throttle `auth-register`) |
| GET/POST | `/forgot-password` | Solicitar reset (throttle `auth-password`) |
| GET | `/reset-password?token=&email=` | Formulario de reset (query params, no path) |
| POST | `/reset-password` | Confirmar reset (throttle `auth-password`) |
| GET | `/verify-email` | Aviso de verificación |
| POST | `/email/resend` | Reenviar enlace (throttle `6,1`) |
| GET | `/email/verify/{id}/{hash}` | Verificación (URL firmada) |
| POST | `/logout` | Cerrar sesión |
| GET | `/dashboard` | Panel (requiere `verified`) |

## 3. Recursos

Todos los recursos de negocio operan sobre el **tenant activo** del usuario. Las rutas NO llevan
`{tenantId}` en el path (evita confusión cross-tenant): el tenant lo decide el middleware.
Excepción: los endpoints de **usuarios/roles** (FASE 4), **business profile** (FASE 5),
**WhatsApp** (FASE 6) y **contactos** (FASE 7) llevan `{tenant}` en el path por claridad REST,
pero el enforcement sigue exigiendo que `{tenant}` sea el tenant activo del usuario (otro tenant
al que se pertenezca → **404**; ver §3.2, §3.3, §3.4 y §3.5).

### 3.1 Tenants (implementado en FASE 3)

| Método | Ruta | Descripción | Detalle |
|---|---|---|---|
| GET | `/api/v1/tenants` | Tenants disponibles (solo activos) + actual | `{tenants: TenantResource[], current_tenant_id}`. `can:viewAny` (filtra por membresía) |
| GET | `/api/v1/tenants/{tenant}` | Perfil del tenant activo | Middleware `tenant`. Enforcement vía `TenantService` + controller. Solo el tenant activo es visible (otro tenant al que se pertenezca requiere `switch`); no-miembro/no-activo → **404** |
| PUT | `/api/v1/tenants/{tenant}` | Actualiza `name/timezone/locale` | Middleware `tenant`. Enforcement vía `TenantService` + controller. Solo tenant activo → 404 en otro caso. Audita `tenant.updated`. Body: `{name, timezone, locale}` |
| POST | `/api/v1/tenants/{tenant}/switch` | Cambia el tenant activo | Enforcement vía `SwitchTenant` + controller. No-miembro → **404**; tenant suspendido → **409** `TENANT_NOT_ACTIVE`. Audita `tenant.switched` + evento `TenantSwitched`. Respuesta: `{message, current_tenant, current_tenant_id}` |

`TenantResource`: `{id, name, slug, status, timezone, locale, role (rol del usuario en el
pivot, si aplica), created_at}`.

### 3.2 Usuarios y roles (implementado en FASE 4)

Todos los endpoints exigen `auth:sanctum` + middleware `tenant` + **membresía activa** y evalúan
los permisos con `AuthorizationService` (matriz de código, ADR-026): `{tenant}` debe ser el
tenant **activo** del usuario; otro tenant → **404**; sin permiso → **403** `PERMISSION_DENIED`.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/users` | `users.view` | Miembros del tenant (status activo) → `{data: MemberResource[]}`. `MemberResource`: `{id, user{id,name,email}, role, status, joined_at, invited_at}` |
| PATCH | `/api/v1/tenants/{tenant}/users/{user}` | `users.update` + `roles.assign` | Cambia el rol. Body `{role: owner|admin|agent}`. Owner puede cambiar admin↔agent; admin no asigna roles (403); quitar el último owner → **422** `ROLE_CHANGE_NOT_ALLOWED`. Audita `user.role_changed` |
| DELETE | `/api/v1/tenants/{tenant}/users/{user}` | `users.remove` | Remueve del tenant (y de spatie). Owner remueve no-owners u otro owner si quedan más; admin solo agents (422 para owner/admin). Audita `user.removed`. Si el miembro tenía este tenant activo, `current_tenant_id` se pone a null |
| GET | `/api/v1/tenants/{tenant}/users/invitations` | `users.invite` | Invitaciones del tenant (todas). `MemberInvitationResource`: `{id, email, role, status, invited_by, expires_at, created_at}` |
| POST | `/api/v1/tenants/{tenant}/users/invitations` | `users.invite` | Crea invitación → **201**. Body `{email, role: owner|admin|agent}`. Email ya miembro → **422** `INVITATION_NOT_ALLOWED`; pendiente duplicada → **409** `INVITATION_ALREADY_PENDING`. Expira a los 7 días. Audita `user.invited`. Notificación por email con enlace `/invitations/{token}` |
| POST | `/api/v1/tenants/{tenant}/users/invitations/{invitation}/revoke` | `users.invite` | Revoca una invitación **pending** → **200**. No pending → **409** `INVITATION_NOT_PENDING`; ajena al tenant → 404. Audita `user.invitation_revoked` |
| POST | `/api/v1/tenants/{tenant}/users/invitations/{invitation}/resend` | `users.invite` | Reenvía el email con **nuevo token** (rota el anterior) → **200**. Mismas reglas que revoke. Audita `user.invitation_resent` |
| GET | `/api/v1/invitations/{token}` | Público (el enlace es la credencial) | Estado de la invitación: `{tenant{id,name}, email, role, expires_at}`. Aceptada → **409** `INVITATION_ALREADY_ACCEPTED`; revocada/expirada → **410** `INVITATION_REVOKED`/`INVITATION_EXPIRED`; inexistente → **404** |
| POST | `/api/v1/invitations/{token}/accept` | `auth:sanctum` + email del usuario == email invitado | Acepta → **200** `{tenant_id, role}`. Email distinto → **403** `INVITATION_EMAIL_MISMATCH`. Crea/reactiva la membresía activa + materializa el rol en spatie. Audita `user.invitation_accepted` |

`GET /api/v1/auth/me` se amplía en FASE 4: `current_role` (rol en el tenant activo o `null`),
`permissions` (matriz de permisos del rol activo) e `is_super_admin`.

Roles por tenant (matriz ADR-026): `owner` = todos los permisos; `admin` = gestión operativa y de
agentes (sin `roles.assign`); `agent` = solo lectura (`tenants.view` + `business_profile.view` +
`whatsapp.view` + `contacts.view`). `super_admin` es global de plataforma (sin permisos de tenant).

### 3.3 Business profile (implementado en FASE 5)

El perfil de negocio es 1:1 con el tenant (invariante de `BusinessProfileService`: se crea bajo
demanda en la primera lectura, ADR-028). Mismas reglas de enforcement que §3.2: `{tenant}` debe
ser el **activo**; otro tenant → **404**; sin permiso → **403** `PERMISSION_DENIED`. El
`tenant_id` nunca se acepta del frontend (TenantContext + `BelongsToTenant` lo deciden).

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/business-profile` | `business_profile.view` (todos los roles) | Perfil del tenant activo. Si no existe, se crea (audita `business_profile.created`). `BusinessProfileResource`: `{id, name, description, category, address, website, email, phone, working_hours, updated_at}` |
| PUT | `/api/v1/tenants/{tenant}/business-profile` | `business_profile.update` (owner/admin) | Actualización **parcial** de cualquier campo (todos opcionales). Body: `{name, description, category, address, website, email, phone, working_hours}`. `working_hours`: `[{day: mon..sun, open: 'HH:mm', close: 'HH:mm', closed: bool}]` (máx 7 días). Valida email/url/formatos. Audita `business_profile.updated` |

Campos: `name` (255), `description` (5000), `category` (100), `address` (255), `website` (URL),
`email`, `phone` (40), `working_hours` (JSON). `logo` no existe aún (requiere upload/media;
pendiente de la fase de storage).

| Recurso | Endpoints principales |
|---|---|
| Tenants | Ver §3.1: `GET/PUT /api/v1/tenants/{tenant}` (solo el activo), `POST /api/v1/tenants/{tenant}/switch`. La creación de tenants se añade en una fase posterior |
| Users/Agents | Ver §3.2: `GET/PATCH/DELETE /api/v1/tenants/{tenant}/users`, `GET/POST .../users/invitations`, `POST .../invitations/{id}/revoke|resend`, `GET /api/v1/invitations/{token}`, `POST /api/v1/invitations/{token}/accept` |
| Business profile | Ver §3.3: `GET/PUT /api/v1/tenants/{tenant}/business-profile` |
| WhatsApp | Ver §3.4: `GET /api/v1/tenants/{tenant}/whatsapp`, `POST .../connect`, `POST .../disconnect` |
| Contacts | Ver §3.5: `GET/POST /api/v1/tenants/{tenant}/contacts`, `GET/PATCH/DELETE /api/v1/tenants/{tenant}/contacts/{id}` (import pendiente) |
| Tags | `GET/POST /api/v1/tags`, `PATCH/DELETE /api/v1/tags/{id}` (pendiente, FASE 20) |
| Conversations | `GET /api/v1/conversations`, `GET/PATCH /api/v1/conversations/{id}`, `POST /api/v1/conversations/{id}/assign`, `POST .../transfer`, `POST .../close`, `POST .../reopen`, `POST .../resume-bot` |
| Messages | Ver §3.7: `GET/POST /api/v1/tenants/{tenant}/conversations/{conversation}/messages` (`conversations.view` / `messages.send`) |
| Chatbots | `GET/POST /api/v1/chatbots`, `PATCH/DELETE /api/v1/chatbots/{id}` |
| Flows | `GET/POST /api/v1/chatbots/{id}/flows`, `PATCH /api/v1/flows/{id}`, `POST /api/v1/flows/{id}/validate`, `POST /api/v1/flows/{id}/publish`, `POST /api/v1/flows/{id}/deactivate` |
| Triggers | `GET/POST /api/v1/flows/{id}/triggers` |
| Leads | `GET/POST /api/v1/leads`, `PATCH /api/v1/leads/{id}` |
| Knowledge | `GET/POST /api/v1/knowledge-bases`, `POST /api/v1/knowledge-bases/{id}/documents`, `POST .../process`, `DELETE .../documents/{id}` |
| FAQ | `GET/POST/PATCH/DELETE /api/v1/faqs` |
| Analytics | `GET /api/v1/analytics/overview?from=&to=` |
| Plans | `GET /api/v1/plans` |
| Subscriptions | `GET/POST /api/v1/subscriptions`, `GET /api/v1/usage` |
| Notifications | `GET /api/v1/notifications`, `PATCH /api/v1/notifications/{id}/read` |
| Audit | `GET /api/v1/audit-logs` (solo owner/admin) |

### 3.4 WhatsApp (implementado en FASE 6)

Cuenta de WhatsApp Business del tenant (una por tenant, ADR-029). Mismas reglas de enforcement
que §3.2/§3.3: `{tenant}` debe ser el **activo**; otro tenant → **404**; sin permiso → **403**
`PERMISSION_DENIED`. El `access_token` **jamás** se devuelve (atributo `hidden` + resource que no
lo incluye). El `tenant_id` nunca se acepta del frontend (`BelongsToTenant`).

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/whatsapp` | `whatsapp.view` (todos los roles) | Estado de la cuenta: `{whatsapp_account: {id, whatsapp_business_account_id, display_name, status, phone_numbers[]} \| null}`. `phone_numbers[]`: `{id, phone_id, display_phone_number, verified_name, quality_rating, status, is_default}` |
| POST | `/api/v1/tenants/{tenant}/whatsapp/connect` | `whatsapp.manage` (owner/admin) | Conecta una WABA. Valida SIEMPRE el token contra Meta (`GET /{phone_number_id}`) antes de persistir. Body: `{whatsapp_business_account_id, phone_number_id, access_token}` (+ `phone_number`, `display_name` opcionales). Token guardado **cifrado**. Respuesta: `{message, whatsapp_account, webhook_subscribed}` |
| POST | `/api/v1/tenants/{tenant}/whatsapp/disconnect` | `whatsapp.manage` (owner/admin) | Desconecta: cancela suscripción del WABA (best-effort), anula el token y marca `disconnected`. Conserva el historial. Sin cuenta → **409** `WHATSAPP_NOT_CONNECTED` |

Códigos de error WhatsApp (HTTP + `code`): **401** `WHATSAPP_AUTH_FAILED` (token inválido en Meta),
**404** `WHATSAPP_PHONE_NOT_FOUND` (phone_number_id inexistente), **409** `WHATSAPP_NOT_CONNECTED`
(sin cuenta), **502** `WHATSAPP_MESSAGE_FAILED` (fallo permanente en envío; `retryable` indica si
es reintentable). Verificación GET webhook: 403 `WHATSAPP_WEBHOOK_INVALID`; firma POST: 401
`WHATSAPP_SIGNATURE_INVALID`.

### 3.5 Contactos (implementado en FASE 7)

CRM básico. Mismas reglas de enforcement que §3.2–§3.4: `{tenant}` debe ser el **activo**; otro
tenant → **404**; sin permiso → **403** `PERMISSION_DENIED`. El `{contact}` del path se resuelve
por el servicio filtrando SIEMPRE por `tenant_id` autorizado (sin route-model binding implícito:
`SubstituteBindings` corre antes que el middleware `tenant`); contacto de otro tenant o inexistente
→ **404** (oculta existencia, ADR-010/023). El `tenant_id` nunca se acepta del frontend.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/contacts` | `contacts.view` (todos los roles) | Listado paginado + filtros. Query: `search` (nombre/teléfono/email, parcial), `phone` (prefijo, se normaliza a E.164), `email` (parcial), `page`, `per_page` (1..100). Respuesta: `{contacts: ContactResource[], meta: {current_page, last_page, per_page, total}}` |
| POST | `/api/v1/tenants/{tenant}/contacts` | `contacts.manage` (owner/admin) | Crea → **201**. Body: `{name*, phone*, email?, avatar_url?, metadata?, provider_contact_id?}`. `phone` libre (`+`, dígitos, espacios, `().-`) con **7–15 dígitos**; se normaliza a E.164 con `+`. Duplicado activo → **409** `CONTACT_DUPLICATE`. Audita `contact.created` |
| GET | `/api/v1/tenants/{tenant}/contacts/{contact}` | `contacts.view` | Detalle del contacto. 404 si no existe/no es del tenant |
| PATCH | `/api/v1/tenants/{tenant}/contacts/{contact}` | `contacts.manage` | Actualización **parcial** (mismos campos que store, todos opcionales). `phone` se normaliza y valida unicidad → **409** `CONTACT_DUPLICATE`. Audita `contact.updated` |
| DELETE | `/api/v1/tenants/{tenant}/contacts/{contact}` | `contacts.manage` | **Soft delete**: libera el teléfono (índice único parcial) y permite re-crear el contacto. Las filas de `contact_tag` se conservan (sin cascade hasta el borrado físico). Audita `contact.deleted` |

`ContactResource`: `{id, phone, name, email, avatar_url, metadata, provider_contact_id,
last_interaction_at, created_at, updated_at}` (jamás incluye `tenant_id`).

`provider_contact_id` es un campo editable por el owner/admin (correlación manual con el `wa_id`
de Meta); el outbound aún no lo rellena automáticamente (pendiente, FASE 10+).
`findOrCreateForPhone` (uso interno, sin auth) ya está disponible para los jobs del webhook.

### 3.6 Conversaciones (implementado en FASE 8)

Inbox del tenant. Mismas reglas de enforcement que §3.2–§3.5: `{tenant}` debe ser el **activo**;
otro tenant → **404**; sin permiso → **403** `PERMISSION_DENIED`. El `{conversation}` del path se
resuelve por el servicio filtrando SIEMPRE por `tenant_id` autorizado (sin route-model binding
implícito); conversación o contacto de otro tenant/inexistente → **404** (oculta existencia,
ADR-010/023). El `tenant_id` nunca se acepta del frontend.

Máquina de estados de `status`: `open` ↔ `pending`; `open`/`pending` → `resolved`;
`resolved` → `archived`; cualquier estado ≠ `open` → `open` (reabrir). Un PATCH con el mismo
estado es **no-op (200)**; una transición inválida → **409** `CONVERSATION_INVALID_STATE`.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/conversations` | `conversations.view` (todos los roles) | Listado paginado + filtros. Query: `search` (sobre el contacto: nombre/teléfono/email), `status` (open/pending/resolved/archived), `agent_id`, `page`, `per_page` (1..100). Orden: `last_interaction_at` DESC (sin interacción al final). Respuesta: `{conversations: ConversationResource[], meta: {current_page, last_page, per_page, total}}` |
| POST | `/api/v1/tenants/{tenant}/conversations` | `conversations.manage` (owner/admin) | Crea para un contacto del MISMO tenant → **201**. Body: `{contact_id* (uuid), status? (default open), bot_paused?, context?}`. Contacto de otro tenant/inexistente → **404**. Audita `conversation.created`. *Endpoint añadido en FASE 8 (no estaba en la especificación original) por ser el punto de alta natural para CONV-1 y para el webhook de FASE 9 (ADR-031)* |
| GET | `/api/v1/tenants/{tenant}/conversations/{conversation}` | `conversations.view` | Detalle con contacto, agente, participantes y asignaciones (`whenLoaded`). 404 si no existe/no es del tenant |
| PATCH | `/api/v1/tenants/{tenant}/conversations/{conversation}` | `conversations.manage` | Actualización **parcial**: `status` (máquina de estados → 409 inválida) y `context` (merge por claves, `null` lo limpia). Audita `conversation.updated` (solo si hay cambios) |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/assign` | `conversations.assign` (owner/admin) | Asigna a un agente (miembro ACTIVO del tenant) → **200**. Body: `{agent_id*}`. Usuario no miembro → **422** `AGENT_NOT_IN_TENANT`. Registra `conversation_assignments` (reason `manual`) + participante. Audita `conversation.assigned` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/transfer` | `conversations.assign` | Transfiere a otro agente: cierra la asignación/participación previa (`unassigned_at`/`left_at`) y crea la nueva (reason `transfer`). Mismas validaciones/errores que assign. Audita `conversation.transferred` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/close` | `conversations.manage` | Cierra (→ `resolved`). Sobre archivada → **409** `CONVERSATION_INVALID_STATE`; sobre resuelta → no-op. Audita `conversation.closed` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/reopen` | `conversations.manage` | Reabre (→ `open`). Sobre abierta → no-op. Audita `conversation.reopened` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/pause-bot` | `conversations.manage` | Pausa el bot (`bot_paused=true`), handoff a humano. Audita `conversation.bot_paused` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/resume-bot` | `conversations.manage` | Reanuda el bot (`bot_paused=false`). Audita `conversation.bot_resumed` |

`ConversationResource`: `{id, status, status_label, contact (ContactResource), agent {id, name,
email} | null, last_message_at, last_interaction_at, auto_assigned, bot_paused, context,
flow_execution_id, participants[], assignments[], created_at, updated_at}` (jamás incluye
`tenant_id`). `context`/`flow_execution_id` los gestionará el motor de flujos (FASE 10+).

### 3.7 Mensajes (persistencia en FASE 9; REST + Realtime en FASE 10)

En FASE 9 los mensajes se **persisten y procesan por backend** (sin endpoints REST todavía):

- **Inbound** (webhook `POST /api/webhooks/whatsapp`): el job `ProcessIncomingWhatsAppMessage`
  ejecuta `MessageService::handleInboundMessage()` → find-or-create contacto (FASE 7) →
  find-or-create conversación (FASE 8) → crea el mensaje con idempotencia por
  `provider_message_id` (UNIQUE `(tenant_id, provider_message_id)` + backstop `QueryException`).
  Tipo no soportado → `UnsupportedMessageTypeException` → el evento se marca `failed` y el webhook
  responde 200. Reabre conversaciones `resolved`/`archived`. Audita `message.received`. Emite
  `MessageCreated` y (si reabre) `ConversationUpdated`.
- **Status** (mismo webhook): `MessageService::handleStatusUpdate()` actualiza el mensaje por
  `provider_message_id` (nunca crea): `sent`/`delivered`/`read` rellenan su columna temporal y
  `failed` pasa la conversación a `pending`. Audita `message.status_updated`. Emite
  `MessageStatusUpdated` (con `previous_status`).
- **Outbound**: `MessageService::createOutbound()` crea el mensaje `pending` y encola
  `SendWhatsAppMessage` (CAS `pending → sending`, `message_send_attempts`, reintento retryable con
  backoff; éxito → `sent` + `provider_message_id`, fallo permanente → `failed`). Emite
  `MessageCreated` y toca timestamps de la conversación (`last_message_at`/`last_interaction_at`
  sin reabrir) con `ConversationUpdated`.

**REST (FASE 10)** — ambos bajo `middleware('tenant')` (aislamiento A/B: tenant ajeno/no-miembro →
404; sin permiso → 403 `PERMISSION_DENIED`; tenant suspendido → 409):

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/conversations/{conversation}/messages` | `conversations.view` | Historial **DESC** paginado (`per_page` 1..100, default 30) → `{messages: MessageResource[], meta}` |
| POST | `/api/v1/tenants/{tenant}/conversations/{conversation}/messages` | `messages.send` (owner/admin/agent) | Envía `{body*}` (required, string, max 4096) → **201** `{message, created_message}` (pending; el envío es async por job) |

**MessageResource**: `id`, `conversation_id`, `provider_message_id`, `direction`, `type`, `status`,
`body`, `media_url`, `media_mime`, `media_size`, `metadata`, `sent_at`, `delivered_at`, `read_at`,
`failed_at`, `created_at`, `updated_at`.

**Realtime (FASE 10)** — eventos broadcast (`ShouldBroadcast`) en el canal privado
`tenant.{tenantId}.conversations.{conversationId}` (sin canales globales; ADR-033):

| Evento | Payload | Cuándo |
|---|---|---|
| `MessageCreated` | `{message}` | inbound (webhook) y outbound (envío del agente) |
| `MessageStatusUpdated` | `{message, previous_status}` | status de Meta y `sent`/`failed` del job |
| `ConversationUpdated` | `{conversation}` | touch timestamps, reabrir por inbound, update/close/reopen/pause-bot/resume-bot/assign/transfer |

### 3.8 Flujos, chatbots, triggers y ejecuciones (implementado en FASE 11)

Motor de flujos (ADR-034..039). Mismas reglas de enforcement que §3.1–§3.7: `{tenant}` debe ser
el **activo**; otro tenant → **404**; sin permiso → **403** `PERMISSION_DENIED`; tenant
suspendido → 409 `TENANT_NOT_ACTIVE`. `{chatbot}`, `{flow}`, `{trigger}` y `{execution}` se
resuelven por servicio filtrando SIEMPRE por `tenant_id` autorizado (sin route-model binding
implícito); recurso de otro tenant/inexistente → **404** (oculta existencia). **El `tenant_id`
nunca se acepta del frontend**: la pertenencia la fuerza `BelongsToTenant` en escrituras.

Permisos nuevos: `flows.view` (owner/admin/agent) y `flows.manage` (owner/admin).

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/tenants/{tenant}/chatbots` | `flows.view` | Listado paginado + `search` (nombre) → `{chatbots: ChatbotResource[], meta}` |
| POST | `/api/v1/tenants/{tenant}/chatbots` | `flows.manage` | Crea `{name*, description?}` → **201**. Audita `chatbot.created` |
| GET | `/api/v1/tenants/{tenant}/chatbots/{chatbot}` | `flows.view` | Detalle con `flows` (cuando el query pide `with=flows`). 404 si no existe/no es del tenant |
| PATCH | `/api/v1/tenants/{tenant}/chatbots/{chatbot}` | `flows.manage` | Actualización parcial. Audita `chatbot.updated` |
| DELETE | `/api/v1/tenants/{tenant}/chatbots/{chatbot}` | `flows.manage` | Elimina (soft). Con flujos publicados → **409** `CHATBOT_HAS_PUBLISHED_FLOWS`. Audita `chatbot.deleted` |
| GET | `/api/v1/tenants/{tenant}/chatbots/{chatbot}/flows` | `flows.view` | Flujos del chatbot, paginados + filtro `status` (draft/published/inactive). `{flows: FlowResource[], meta}` |
| POST | `/api/v1/tenants/{tenant}/chatbots/{chatbot}/flows` | `flows.manage` | Crea `{name*, description?}` (estado `draft`) → **201**. Audita `flow.created` |
| GET | `/api/v1/tenants/{tenant}/flows/{flow}` | `flows.view` | Detalle con `nodes`, `connections` y `triggers` eager-loaded. 404 si no existe/no es del tenant |
| PATCH | `/api/v1/tenants/{tenant}/flows/{flow}` | `flows.manage` | Actualización de nombre/descripción (no del grafo). Publicado → **409** `FLOW_PUBLISHED`. Audita `flow.updated` |
| PUT | `/api/v1/tenants/{tenant}/flows/{flow}/draft` | `flows.manage` | Reemplaza el grafo **atómicamente** (transacción nodes+connections). Body: `{nodes[]*, connections[], base_updated_at?}`. `base_updated_at` (ISO) es el lock optimista: si la versión guardada del cliente no coincide con `flows.updated_at` → **409** `FLOW_CONFLICT` sin escribir. Valida forma (422 `VALIDATION_ERROR`) y grafo (422 `FLOW_INVALID`). Publicado → **409**. Audita `flow.draft_replaced` |
| GET | `/api/v1/tenants/{tenant}/flows/{flow}/validate` | `flows.view` | Valida el grafo SIN mutar → `{valid: bool, errors: string[]}` |
| GET | `/api/v1/tenants/{tenant}/flows/{flow}/variables` | `flows.view` | Catálogo DERIVADO de variables del flujo (FASE 13, UNIDAD 3) → `{variables: VariableDefinitionResource[]}`. Devuelve definiciones, nunca valores runtime. `custom.*` se deriva de los nodos `question`; `business.*` está whitelistado (`BusinessProfile::PUBLIC_FIELDS`). Solo lectura (POST/PUT/PATCH/DELETE → **405**) |
| POST | `/api/v1/tenants/{tenant}/flows/{flow}/publish` | `flows.manage` | Publica (valida grafo → 422 `FLOW_INVALID`). También valida la **config de los triggers** del flujo (422 `FLOW_INVALID` con los errores en `errors`) y aplica la regla de publicación: otro flujo publicado del mismo tenant con un **trigger genérico activo del mismo tipo** (`new_message`/`start`) → **409** `FLOW_ALREADY_PUBLISHED` (los triggers específicos `keyword`/`tag`/`schedule`/`webhook` pueden coexistir). Transición inválida → **409** `FLOW_INVALID_STATE`. Audita `flow.published` |
| POST | `/api/v1/tenants/{tenant}/flows/{flow}/deactivate` | `flows.manage` | Desactiva (requiere `published`; si no → **409** `FLOW_INVALID_STATE`). Audita `flow.deactivated` |
| DELETE | `/api/v1/tenants/{tenant}/flows/{flow}` | `flows.manage` | Elimina. Publicado → **409** `FLOW_PUBLISHED`. Audita `flow.deleted` |
| GET | `/api/v1/tenants/{tenant}/flows/{flow}/triggers` | `flows.view` | Lista de triggers del flujo → `{triggers: TriggerResource[]}`. La config del `webhook` **no incluye `token_hash`** |
| POST | `/api/v1/tenants/{tenant}/flows/{flow}/triggers` | `flows.manage` | Crea `{type* (keyword/new_message/start/tag/schedule/webhook), keyword? (requerido si type=keyword), config?, priority?, active?}` → **201**. La config se valida por tipo (422 `VALIDATION_ERROR`, `errors.config`). Para `webhook` la respuesta incluye `webhook_token` (**única vez**): solo su hash se persiste. Flujo publicado → **409** `FLOW_PUBLISHED`. Audita `trigger.created` |
| PATCH | `/api/v1/tenants/{tenant}/flows/{flow}/triggers/{trigger}` | `flows.manage` | Actualiza (misma validación de config por tipo). El `token_hash` de un webhook se preserva al actualizar; nunca es editable por el cliente. Flujo publicado → **409**. Audita `trigger.updated` |
| DELETE | `/api/v1/tenants/{tenant}/flows/{flow}/triggers/{trigger}` | `flows.manage` | Elimina. Flujo publicado → **409**. Audita `trigger.deleted` |
| GET | `/api/v1/tenants/{tenant}/flow-executions` | `flows.view` | Ejecuciones paginadas. Query: `status` (running/waiting/completed/failed/handed_off), `flow_id`, `chatbot_id`, `page`, `per_page` → `{executions: FlowExecutionResource[], meta}` |
| GET | `/api/v1/tenants/{tenant}/flow-executions/{execution}` | `flows.view` | Detalle con `flow`. 404 si no existe/no es del tenant |
| POST | `/api/v1/tenants/{tenant}/flow-executions/{execution}/pause` | `flows.manage` | Pausa una ejecución **activa**. Terminal → **409** `EXECUTION_INVALID_STATE`. Audita `execution.paused` |
| POST | `/api/v1/tenants/{tenant}/flow-executions/{execution}/resume` | `flows.manage` | Reanuda una ejecución pausada/waiting. Terminal → **409**. Audita `execution.resumed` |
| POST | `/api/v1/tenants/{tenant}/flow-executions/{execution}/cancel` | `flows.manage` | Cancela (→ `failed`). Terminal → **409**. Audita `execution.cancelled` |

**Resources** (ninguno incluye `tenant_id`): `ChatbotResource`
`{id, name, description, flows_count, created_at, updated_at}`; `FlowResource`
`{id, chatbot_id, name, description, status, status_label, config, nodes[], connections[],
triggers[], created_at, updated_at}`; `FlowNodeResource` `{id, type, type_label, name,
position_x, position_y, config, is_start}`; `FlowConnectionResource`
`{id, source_node_id, target_node_id, label}`; `TriggerResource`
`{id, flow_id, type, type_label, keyword, config, priority, active, created_at, updated_at}`;
`FlowExecutionResource` `{id, flow_id, conversation_id, status, status_label, current_node_id,
variables, attempts, last_inbound_message_id, created_at, updated_at}`;
`VariableDefinitionResource` `{key, label, namespace, source, type, default, writable}`.

**Contrato de config de triggers (FASE 14, UNIDAD 1, ADR-047)**: el backend es la única
autoridad; la config se valida con `TriggerValidator` al crear, actualizar y publicar. Por tipo:

- `keyword`/`new_message`/`start`: sin `config`. `keyword` exige palabra no vacía (≤ 255).
- `tag`: `config.tags` (lista de 1..10 etiquetas únicas, cada una ≤ 100 chars). Solo define el
  contrato; la ejecución por etiqueta llega en FASE 20.
- `schedule`: `config.cron` (expresión cron determinista de 5 campos; sin eval) +
  `config.conversation_id` (UUID de una conversación **del tenant**; inexistente o de otro tenant
  → **404** genérico, nunca filtra existencia cross-tenant).
- `webhook`: `config.conversation_by` (`conversation_id`|`contact_id`|`phone`). El servidor
  añade `config.token_hash` (sha256 del token CSPRNG); el cliente jamás envía `token`/
  `token_hash` (422). El `webhook_token` en claro se devuelve una única vez en la respuesta de
  creación y nunca aparece en `TriggerResource`, auditoría o logs. El endpoint público del
  webhook es UNIDAD 3 (no implementado).

**Catálogo de variables (FASE 13, UNIDAD 3)**: el endpoint
`GET /api/v1/tenants/{tenant}/flows/{flow}/variables` expone **definiciones derivadas**
(`VariableCatalogService`, ADR-046), no valores de ejecución. Mismas reglas de enforcement que
el resto de §3.8: `flows.view` (owner/admin/agent) → 200; usuario sin `flows.view` → **403**
`PERMISSION_DENIED`; flujo de otro tenant/inexistente → **404** (aislamiento A/B); tenant
suspendido → **409** `TENANT_NOT_ACTIVE`. El catálogo se construye íntegramente server-side:
`contact.*`/`conversation.id` fijos en solo lectura, `business.*` SOLO vía
`BusinessProfile::PUBLIC_FIELDS` (nunca tokens/credenciales) y `custom.*` derivado de los nodos
`question` del flujo (con `field`, `type` y `default`; claves duplicadas colapsan, las peligrosas
se omiten). El Resource expone únicamente `VariableDefinition`, por lo que nunca filtran
`tenant_id`, config de nodos, headers/body del webhook ni secretos.

**El nodo `webhook` solo expone `method` + `url`** (el `config` completo no sale por API para no
filtrar headers/secrets; el frontend muestra un resumen, no el config crudo).

**Editor y catálogo de variables (FASE 13, UNIDAD 4)**: el editor consume el catálogo del
endpoint de la UNIDAD 3 con un `VariablePicker` (agrupado por namespace, con búsqueda) que
inserta la referencia literal `{{clave}}` en los campos que el motor resuelve:
`message.text`, `buttons.text` y `question.prompt`. Los títulos de botón y el `field` de
pregunta siguen siendo literales/validados (backend autoridad). En nodos `condition`, `field`
se elige del catálogo (`contact.*`/`business.*`/`conversation.*`/`custom.*`) y se conservan
`match`/`not`/operadores sin cambios. El cliente NUNCA envía `tenant_id`, `namespace`, `type`,
`writable` ni `source`; el catálogo es de solo lectura. Las referencias que el motor no podrá
  resolver (`{{custom.inexistente}}`, `{{unknown.*}}`, `{{node.*}}`, `business.*` fuera de la
  whitelist) generan warnings locales (nunca errores) que no bloquean la edición ni el guardado.

**FASE 13, UNIDAD 5 (ADR-045)**: `{{contact.<campo>}}` es alias de `contact.metadata[<campo>]`
(la traversión `contact.metadata.<clave>` sigue bloqueada). El nodo `question` admite
`type` (uno de `VariableType`) y `default` (coercible al tipo declarado o `string`); el panel del
editor los conserva y edita al guardar. Límites de validación: textos ≤ 4096, campo de condition
≤ 128 (namespaces `contact/business/conversation/custom`, segmentos seguros), URL webhook ≤ 2048
con esquema `http(s)` y host literal (sin variables, sin credenciales). Los logs/auditoría del
webhook usan URLs saneadas (sin userinfo/query/fragment).

**FASE 13, UNIDAD 6 (ADR-046)**: `default` también tiene efecto en runtime: ante una respuesta
**vacía** a un nodo `question` con `question.config.default` usable, el motor persiste el default
**coerceado al tipo declarado** en `flow_executions.variables.custom.<field>` (con su tipo real,
p. ej. `integer` `'42'` → `42` int), que luego fluye a interpolación y condiciones. Sin default o
con respuesta no vacía, el comportamiento de la UNIDAD 2/5 no cambia. El endpoint del catálogo
sigue siendo de solo lectura (definiciones, nunca valores de ejecución).

**Editor visual (FASE 12)**: el frontend edita sobre los endpoints de la tabla (página
`settings/flows/{chatbot}/{flow}`, ADR-040..044). El editor envía ids de nodo UUID, posiciones
enteras, aristas con `label` (`true`/`false` para ramas de condición) y `base_updated_at`
(ADR-041); el `tenant_id` nunca viaja en el body. Conflictos de concurrencia → **409**
`FLOW_CONFLICT` con `{message, code, current_updated_at}`; la resolución (recargar / seguir /
sobrescribir) ocurre en el cliente con `ConflictDialog`.

## 4. Webhooks (sin auth Bearer; autenticados por firma y dedupe)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/webhooks/whatsapp` | Verificación de Meta (`hub.mode`, `hub.verify_token`, `hub.challenge`). Token correcto → 200 con el challenge en **texto plano**; inválido → 403 (FASE 6) |
| POST | `/api/webhooks/whatsapp` | Evento de mensaje/estado. Valida `X-Hub-Signature-256` (HMAC-SHA256 sobre el body crudo) → 401 si falla; dedupe por `provider_event_id`; resuelve tenant por `metadata.phone_number_id` y encola el job. Respuesta **siempre 200** para eventos válidos/duplicados/desconocidos (nunca 500) (FASE 6) |
| POST | `/api/webhooks/stripe` | Eventos de Stripe (invoice, subscription). Firma `Stripe-Signature` + dedupe por `event id` |
| POST | `/api/webhooks/flows/{trigger}` | Webhook público de flujos. Autenticación por `Authorization: Bearer {token}` (SHA-256 hash comparado con `config.token_hash`); tenant resuelto desde el trigger. Idempotencia por `Idempotency-Key` header (409 duplicado). Rate limit 60 req/min por IP. Despacha `StartFlowFromWebhook` job → 202 `{"status": "accepted"}` (FASE 14 U3, ADR-049) |

## 5. Errores

Formato estándar:

```json
{
  "message": "Rate limit exceeded. Try again in 30 seconds.",
  "code": "RATE_LIMITED",
  "errors": {}
}
```

Excepciones de dominio con código propio (`FLOW_INVALID`, `FLOW_CONFLICT`,
`TENANT_QUOTA_EXCEEDED`, `WHATSAPP_MESSAGE_FAILED`...).

## 6. Versionado

El prefijo `v1` queda fijo. Cambios rompedores introducen `v2`. Los recursos nuevos dentro de
v1 se añaden sin romper contratos existentes.

## 7. Documentación

OpenAPI 3.1 (spec en `docs/openapi.yaml`, se generará en FASE 35). Los recursos se validan con
`FormRequest` cuyas reglas se comparten con la spec donde sea posible.
