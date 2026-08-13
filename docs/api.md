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
Excepción: los endpoints de **usuarios/roles** (FASE 4) y **business profile** (FASE 5) llevan
`{tenant}` en el path por claridad REST, pero el enforcement sigue exigiendo que `{tenant}` sea
el tenant activo del usuario (otro tenant al que se pertenezca → **404**; ver §3.2 y §3.3).

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
agentes (sin `roles.assign`); `agent` = solo lectura (`tenants.view` + `business_profile.view`).
`super_admin` es global de plataforma (sin permisos de tenant).

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
| WhatsApp | `POST /api/v1/whatsapp/connect`, `GET /api/v1/whatsapp/accounts`, `POST /api/v1/whatsapp/accounts/{id}/verify` |
| Contacts | `GET/POST /api/v1/contacts`, `PATCH/DELETE /api/v1/contacts/{id}`, `POST /api/v1/contacts/import` |
| Tags | `GET/POST /api/v1/tags`, `PATCH/DELETE /api/v1/tags/{id}` |
| Conversations | `GET /api/v1/conversations`, `GET/PATCH /api/v1/conversations/{id}`, `POST /api/v1/conversations/{id}/assign`, `POST .../transfer`, `POST .../close`, `POST .../reopen`, `POST .../resume-bot` |
| Messages | `GET /api/v1/conversations/{id}/messages`, `POST /api/v1/conversations/{id}/messages` (enviar, con `Idempotency-Key`) |
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

## 4. Webhooks (sin auth Bearer; autenticados por firma y dedupe)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/webhooks/whatsapp` | Verificación de Meta (`hub.mode`, `hub.verify_token`, `hub.challenge`) |
| POST | `/api/webhooks/whatsapp` | Evento de mensaje/estado. Valida `X-Hub-Signature-256` + dedupe por `provider_event_id` |
| POST | `/api/webhooks/stripe` | Eventos de Stripe (invoice, subscription). Firma `Stripe-Signature` + dedupe por `event id` |

## 5. Errores

Formato estándar:

```json
{
  "message": "Rate limit exceeded. Try again in 30 seconds.",
  "code": "RATE_LIMITED",
  "errors": {}
}
```

Excepciones de dominio con código propio (`FLOW_INVALID`, `TENANT_QUOTA_EXCEEDED`,
`WHATSAPP_MESSAGE_FAILED`...).

## 6. Versionado

El prefijo `v1` queda fijo. Cambios rompedores introducen `v2`. Los recursos nuevos dentro de
v1 se añaden sin romper contratos existentes.

## 7. Documentación

OpenAPI 3.1 (spec en `docs/openapi.yaml`, se generará en FASE 35). Los recursos se validan con
`FormRequest` cuyas reglas se comparten con la spec donde sea posible.
