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

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/v1/auth/register` | Registro (crea tenant de prueba al primer owner) |
| POST | `/api/v1/auth/login` | Login (interno: cookie; externo: token) |
| POST | `/api/v1/auth/logout` | Cierra sesión / revoca token |
| POST | `/api/v1/auth/forgot-password` | Solicita reset |
| POST | `/api/v1/auth/reset-password` | Confirma reset |
| GET  | `/api/v1/auth/me` | Usuario + tenants a los que pertenece + tenant actual + roles/permisos |
| POST | `/api/v1/auth/switch-tenant` | Cambia `users.current_tenant_id` (valida membresía) y devuelve el nuevo contexto |

## 3. Recursos

Todos los recursos de negocio operan sobre el **tenant activo** del usuario. Las rutas NO llevan
`{tenantId}` en el path (evita confusión cross-tenant): el tenant lo decide el middleware.

| Recurso | Endpoints principales |
|---|---|
| Tenants | `GET /api/v1/tenants/current` (perfil del propio tenant), `PUT /api/v1/tenants/current/business-profile`, `POST /api/v1/tenants` (crear). Cualquier `GET /api/v1/tenants/{id}` valida membresía en `tenant_users` → 404 si no pertenece |
| Users/Agents | `GET/POST /api/v1/users`, `POST /api/v1/users/invitations`, `PATCH/DELETE /api/v1/users/{id}` (siempre dentro del tenant activo) |
| Business profile | `GET/PUT /api/v1/tenants/current/business-profile` |
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
