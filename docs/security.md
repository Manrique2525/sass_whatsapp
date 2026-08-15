# Seguridad

Alineado a OWASP Top 10. Cada fase incluye controles de seguridad + tests.

## 1. Principios

- **Nunca confiar en datos del frontend.** Toda validación de negocio y límites en backend.
- **Defensa en profundidad** (validación + policies + scopes + auditoría).
- **Menor privilegio**: roles y permisos explícitos (spatie/laravel-permission).
- **Ocultar existencia**: accesos a datos ajenos devuelven 404 (no 401/403 cuando revelaría).
- **Secrets fuera del código**: todo vía `.env` (ver `deployment.md`).

## 2. Controles por vector

### Autenticación y sesiones
- Sanctum en modo stateful (cookies + CSRF) para el SPA interno y tokens Bearer para clientes
  externos (ADR-011). Passwords con `bcrypt` (cast Eloquent `hashed`, nunca en texto plano
  ni en logs).
- Login rate-limit: `auth-login` 10/min por email/IP.
- Registro rate-limit: `auth-register` 5/min por IP. Con verificación de email.
- Password reset rate-limit: `auth-password` 3/min por email/IP; tokens de un solo uso con
  expiración (60 min); el reset **revoca todos los tokens Sanctum** del usuario.
- **No filtración de emails**: `forgot-password` responde siempre igual (exista o no el email),
  tanto en web como en API.
- Rotación/revocación de tokens en logout (API revoca el token actual; web invalida sesión +
  `regenerateToken`). Regeneración de sesión tras login.
- Passwords mínimos: `Password::min(8)` (policy global en `AppServiceProvider`).
- Error de login genérico (mismo mensaje para email inexistente o contraseña incorrecta).

### Autorización
- `Policies` por entidad (TenantPolicy, ConversationPolicy, ContactPolicy, FlowPolicy...).
- `Middleware tenant` resuelve el tenant activo (ver `multi-tenancy.md`).
- Roles por tenant con spatie en modo `teams` (`team_id = tenant_id`): `owner`, `admin`,
  `agent`; `super_admin` global de plataforma. Permisos: `manage_contacts`,
  `manage_chatbots`, `manage_agents`, `view_analytics`, `manage_billing`, etc. Ver ADR-012.

### Aislamiento multi-tenant (FASE 3)
- **Scope global `TenantScope`** en todo modelo de dominio tenant. Sin `TenantContext` activo,
  las lecturas devuelven vacío (`WHERE 1 = 0`) y las escrituras lanzan
  `TenantContextMissingException` (fail-safe, ADR-020).
- **Escrituras**: `tenant_id` SIEMPRE se fuerza desde `TenantContext::id()` en el hook
  `creating` de `BelongsToTenant`. El `tenant_id` enviado por el frontend se ignora/rechaza
  (test `RequestTenantIdTamperTest`).
- **Middlewares**: `tenant` rechaza (403) sin tenant activo válido, si el tenant está
  suspendido o si `current_tenant_id` ya no corresponde a una membresía real (valida contra
  `tenant_users`, nunca confía en el valor persistido).
- **Política de no-revelación**: acceso a tenant/recurso ajeno → 404 (no 403). El `switch`
  devuelve 404 para no-miembros y 409 `TENANT_NOT_ACTIVE` para tenants suspendidos.
- **Policies**: `TenantPolicy::switch` exige membresía + tenant activo; `view`/`update` exigen
  pertenencia y el servicio exige además que sea el tenant activo.
- **Jobs**: `TenantAwareJob` (ADR-021) transporta `tenantId`, restablece su propio contexto y lo
  libera en `finally`. Un job de A jamás contamina a un job de B en el mismo worker.
- **Tiempo real**: canales `tenant.{tenantId}.<recurso>.{recursoId}` (sin comodín, ADR-022);
  `routes/channels.php` autoriza con pertenencia real al tenant. Usuario de A → `false` en
  canales de B.
- **Auditoría**: switch y updates de tenant registrados en `audit_logs` (`tenant.switched`,
  `tenant.updated`).
- **Revisión de seguridad FASE 3** (ver `docs/roadmap.md` §Fase 3): IDOR, tampering de
  `tenant_id`, enumeración de tenants, fuga cross-tenant en colas/webhooks/Reverb, y
  determinismo de tests en Docker (ADR-024).

### Autorización por tenant (FASE 4 — usuarios y roles)
- **`AuthorizationService`** (ADR-026): la matriz `TenantPermission::permissionsForRole()` es la
  fuente de verdad. Toda operación exige: (1) el recurso es el tenant **activo**
  (`isCurrentTenant`), (2) membresía con `status = active`, (3) permiso en la matriz. Sin
  membresía/no-activa → 404 (no revela existencia); sin permiso → 403 `PERMISSION_DENIED`;
  tenant suspendido → 409 `TENANT_NOT_ACTIVE`.
- **23 permisos granulares** (`tenants.view/update`, `users.view/invite/update/remove`,
  `roles.view/assign`, `agents.view/manage`, `audit.view`, `business_profile.view/update`,
  `whatsapp.view/manage`, `contacts.view/manage`, `conversations.view/manage/assign`,
  `flows.view/manage`). Matriz:
  owner = todos; admin = operativo sin `roles.assign`; agent = solo `tenants.view`,
  `business_profile.view`, `whatsapp.view`, `contacts.view`, `conversations.view` y
  `flows.view`;
  `super_admin` = global (sin permisos de tenant).
- **Roles espejo en spatie**: `TenantRoleManager` sincroniza spatie con `tenant_users.role`
  usando `syncRoles` (reemplaza, no acumula) con el team del `TenantTeamResolver`
  (override → `TenantContext` → `current_tenant_id` → null). Cambiar de tenant activo cambia
  los permisos efectivos al instante (test CRITICO de FASE 4).
- **Invitaciones (ADR-027)**: el token plano solo viaja en el email (`/invitations/{token}`);
  en BD solo `token_hash` sha256. Máquina de estados `pending → accepted/revoked/expired` con
  `expires_at` a 7 días. Endpoints: `show` público (el enlace es la credencial), `accept` exige
  sesión con email == email invitado. Códigos: 409 `INVITATION_ALREADY_ACCEPTED` /
  `INVITATION_ALREADY_PENDING` / `INVITATION_NOT_PENDING`, 410 `INVITATION_REVOKED` /
  `INVITATION_EXPIRED`, 403 `INVITATION_EMAIL_MISMATCH`, 422 `INVITATION_NOT_ALLOWED` /
  `ROLE_CHANGE_NOT_ALLOWED`, 404 no encontrada/ajena.
- **Reglas de negocio críticas**: el último owner no puede degradarse/removerse (422); admin no
  asigna roles ni gestiona owners; remover al miembro que tiene el tenant como activo pone
  `current_tenant_id` a null; aceptar una invitación NO cambia el tenant activo.
- **Auditoría FASE 4**: `user.login`, `user.logout`, `user.invited`, `user.invitation_accepted`,
  `user.invitation_revoked`, `user.invitation_resent`, `user.role_changed`, `user.removed`.

### Perfil de negocio (FASE 5)
- **Autorización**: `business_profile.view` (todos los roles del tenant) y
  `business_profile.update` (solo owner/admin) en la matriz ADR-026. Agent → lectura
  únicamente (403 `PERMISSION_DENIED` en PUT).
- **Aislamiento**: `business_profiles` usa el trait `BelongsToTenant` (scope global + forzado de
  `tenant_id` por `TenantContext` en creación). El `tenant_id` NO es fillable y NO existe regla
  de validación para él: un `tenant_id` enviado en el body se ignora (test BP-8). El perfil de
  otro tenant es **404** (no revela existencia, ADR-010/023).
- **Validación (backend)**: `UpdateBusinessProfileRequest` valida email, URL (`website`),
  longitudes (`name` 255, `description` 5000, `category` 100, `address` 255, `phone` 40),
  `working_hours` (array máx 7, días en `mon..sun`, horas `HH:mm` 24h, `closed` booleano).
  Nada se confía al frontend.
- **Auditoría FASE 5**: `business_profile.created` (primera lectura, invariante 1:1) y
  `business_profile.updated` (con `changed` y `tenant_id`).

### WhatsApp (FASE 6)
- **Webhook público pero autenticado por diseño**: el GET de verificación exige
  `hub.verify_token` global (hash_equals) → 403 `WHATSAPP_WEBHOOK_INVALID` si no; el POST valida
  SIEMPRE `X-Hub-Signature-256 = sha256=HMAC-SHA256(WHATSAPP_APP_SECRET, body_crudo)` con
  `hash_equals` → 401 `WHATSAPP_SIGNATURE_INVALID`. La firma se calcula sobre el **body crudo
  exacto** (`$request->getContent()`), jamás sobre un JSON re-serializado (test WHATSAPP-3/4/31).
- **Idempotencia**: `webhook_events.provider_event_id` UNIQUE + insert `ON CONFLICT DO NOTHING`;
  eventos duplicados (secuenciales o concurrentes) se marcan `duplicate` y no se reprocesan.
  Los jobs solo marcan `processed` eventos `enqueued` + `event_type` + `tenant_id` coincidentes.
- **Aislamiento**: la resolución de tenant del webhook consulta `whatsapp_phone_numbers.phone_id`
  sin scope y escribe en `webhook_events` (tabla de plataforma) — un webhook de un número del
  tenant B jamás toca datos del tenant A (test WHATSAPP-11 CRITICO). Los modelos
  `whatsapp_accounts`/`whatsapp_phone_numbers`/`message_send_attempts` usan `BelongsToTenant`.
- **Credenciales**: el `access_token` de la WABA se guarda **cifrado** (atributo `encrypted` con
  la `APP_KEY`), es `$hidden` y el `WhatsAppAccountResource` no lo incluye nunca (test
  WHATSAPP-15/29). Se usa solo para llamadas de ese tenant (token por llamada, nunca global).
- **Autorización**: `whatsapp.view` (todos los roles) y `whatsapp.manage` (owner/admin) en la
  matriz ADR-026 (→ 15 permisos). Sin permiso → 403 `PERMISSION_DENIED`; `{tenant}` distinto del
  activo → 404; conectar sin credenciales válidas en Meta → 401 `WHATSAPP_AUTH_FAILED`.
- **Nunca confiar en el frontend**: `ConnectWhatsAppRequest` valida los campos; el token se
  valida contra Meta antes de persistir; `tenant_id` nunca se acepta (BelongsToTenant).
- **Auditoría FASE 6**: `whatsapp.connected`, `whatsapp.disconnected`, `whatsapp.webhook_configured`,
  `whatsapp.message_sent`, `whatsapp.message_failed`.
- **DoS**: los payloads de webhook se procesan en la cola (nunca trabajo pesado en el request);
  eventos desconocidos/malformados responden 200 (Meta no reintenta en bucle).

### Contactos (FASE 7)
- **Autorización**: `contacts.view` (todos los roles del tenant) y `contacts.manage`
  (owner/admin: crear, editar, eliminar) en la matriz ADR-026 (→ 17 permisos). Agent → solo
  lectura; cualquier mutación → 403 `PERMISSION_DENIED`.
- **Aislamiento**: `contacts`/`tags` usan `BelongsToTenant` (scope global + forzado de
  `tenant_id` por `TenantContext`). El `tenant_id` no es fillable ni tiene regla de validación:
  un `tenant_id` enviado en el body se ignora (test CONTACT-13). El `{contact}` del path se
  resuelve SIN route-model binding implícito (evita el bug de orden `SubstituteBindings` →
  `tenant`): el servicio filtra SIEMPRE por `tenant_id` del tenant autorizado; contacto ajeno o
  inexistente → **404** (no revela existencia, ADR-010/023; test CRITICO CONTACT-12).
- **Normalización de teléfono**: `ContactService::normalizePhone` convierte a E.164 canónico con
  `+` y solo dígitos; la unicidad se protege en DB con índice único **parcial**
  `(tenant_id, phone) WHERE deleted_at IS NULL` y en app con `assertPhoneUnique` (backstop ante
  carreras). Duplicado activo → 409 `CONTACT_DUPLICATE`. Un contacto soft-deleted libera el
  teléfono (se puede re-crear).
- **Validación (backend)**: `StoreContactRequest`/`UpdateContactRequest` validan `phone`
  (regex `/^\+?[0-9\s().\-]+$/` + **7–15 dígitos** por closure), `name` (255), `email`,
  `avatar_url` (URL máx 2048), `metadata` (JSON). `ContactIndexRequest` acota `per_page` a 100.
- **Uso interno sin auth**: `findOrCreateForPhone` (FASE 9) busca fuera del scope pero SIEMPRE
  filtrando por `tenant_id`; setea y libera `TenantContext` en `finally` (sin contaminación
  entre jobs). Ante una carrera (`QueryException` por el índice único) re-consulta; si sigue sin
  existir, relanza (no traga excepciones).
- **Auditoría FASE 7**: `contact.created`, `contact.updated` (con `changed`), `contact.deleted`
  (con `phone`).

### Conversaciones (FASE 8)
- **Autorización**: `conversations.view` (todos los roles del tenant), `conversations.manage`
  (owner/admin: crear, editar, cerrar/reabrir, pausar/reanudar bot) y `conversations.assign`
  (owner/admin: asignar/transferir) en la matriz ADR-026 (→ 20 permisos). Agent → solo lectura;
  cualquier mutación → 403 `PERMISSION_DENIED` (test CONV-22).
- **Aislamiento**: `conversations`/`conversation_participants`/`conversation_assignments` usan
  `BelongsToTenant` (scope global + forzado de `tenant_id` por `TenantContext`). El `tenant_id` no
  es fillable ni tiene regla de validación: un `tenant_id` enviado en el body se ignora (test
  CONV-20). El `{conversation}` del path se resuelve SIN route-model binding implícito: el
  servicio filtra SIEMPRE por `tenant_id` del tenant autorizado; conversación ajena o inexistente
  → **404** (no revela existencia, ADR-010/023). Crear sobre un contacto de otro tenant → 404
  (tests CRITICOS CONV-18/19 A/B: crear sobre contacto de B y leer/modificar/asignar con usuario
  de B).
- **Asignación segura**: `assign`/`transfer` validan que el agente destino sea miembro del
  tenant con `status = active` en `tenant_users` (sin confiar en el frontend). Usuario fuera del
  tenant → 422 `AGENT_NOT_IN_TENANT`; sin permiso → 403. La transferencia cierra la asignación y
  participación previas (`unassigned_at`/`left_at`) y registra historial acumulativo.
- **Máquina de estados**: transiciones inválidas → 409 `CONVERSATION_INVALID_STATE` (nunca se
  muta `status` libremente vía PATCH); mismo estado = no-op. `status` validado contra el enum.
- **Validación (backend)**: `ConversationIndexRequest` acota `per_page` a 100 y valida
  `status`/`agent_id`; `StoreConversationRequest` exige `contact_id` uuid; `AssignConversationRequest`
  exige `agent_id`. `ConversationResource` jamás expone `tenant_id`.
- **Uso interno sin auth**: `findOrCreateActiveForContact` (FASE 9) busca fuera del scope pero
  SIEMPRE filtrando por `tenant_id`; setea y libera `TenantContext` en `finally`. Reutiliza la
  conversación activa del contacto o crea una nueva; un contacto soft-deleted jamás se resucita.
- **Auditoría FASE 8**: `conversation.created`, `conversation.updated`, `conversation.assigned`,
  `conversation.transferred`, `conversation.closed`, `conversation.reopened`,
  `conversation.bot_paused`, `conversation.bot_resumed`.

### Mensajes (FASE 9)
- **Aislamiento**: `messages` usa `BelongsToTenant` (scope global + forzado de `tenant_id` por
  `TenantContext`). `MessageService` y los jobs (`ProcessIncomingWhatsAppMessage`,
  `ProcessWhatsAppStatusUpdate`, `SendWhatsAppMessage`) resuelven SIEMPRE con
  `withoutTenantScope()->where('tenant_id', ...)` del tenant resuelto/encolado → un webhook de un
  número del tenant B jamás persiste en datos del A (tests CRITICOS MSG-6 y STAT-8 A/B).
  `TenantContext` se setea solo alrededor de los creates y se libera en `finally` (sin
  contaminación entre jobs; el audit pasa `tenantId:` explícito porque el contexto ya se limpió).
- **Idempotencia / anti-duplicados**: UNIQUE `(tenant_id, provider_message_id)` (mensaje inbound
  creado una sola vez; backstop `QueryException` → re-consulta, ADR-032) + dedupe de plataforma
  `webhook_events.provider_event_id` (para statuses, clave compuesta `id|status|timestamp`: Meta
  reusa el id de mensaje en delivered/read). Un status update NUNCA crea mensajes (solo actualiza
  por `provider_message_id`).
- **Envío sin doble entrega**: `SendWhatsAppMessage` es `ShouldBeUnique` por `message_id` (300s)
  y usa CAS `pending → sending` con update atómico: un job duplicado/concurrente no re-envía.
  Reintento solo de errores retryable de Meta (timeout/5xx/429) con backoff; errores permanentes o
  intentos agotados → `failed` (sin reintento en bucle). El worker re-valida cuenta conectada +
  número default + tipo text (nunca confía en estado previo).
- **Nunca confiar en el frontend**: los mensajes inbound provienen del webhook firmado; el
  outbound se crea por servicio (sin campos del request en FASE 9). El `tenant_id`/`conversation_id`
  jamás se aceptan del frontend (FASE 10).
- **DoS / entrega**: el request del webhook no hace trabajo pesado; eventos desconocidos/
  malformados → 200. El outbox (`whatsapp:reprocess-webhook-events`, cada minuto) re-encola
  eventos `received` viejos para no perder mensajes si el proceso cae entre insert y encolado.
- **Auditoría FASE 9**: `message.received`, `message.status_updated`, `message.sent`,
  `message.failed`, `message.duplicate` (no-op).

### Inbox API + Realtime (FASE 10)
- **Autorización por endpoint y por recurso**: `GET .../messages` exige `conversations.view` y
  `POST .../messages` exige `messages.send` (owner/admin/agent). Ambos resuelven la conversación
  con `withoutTenantScope()->where(tenant_id, ...)` del tenant de la URL: usuario de otro tenant o
  no-miembro → **404** (sin oráculo); conversación inexistente → 404; tenant suspendido → 409;
  falta de permiso → 403 `PERMISSION_DENIED`. Tests CRITICOS MSG-API-4/5/8/11 (aislamiento A/B e
  IDOR sobre el recurso).
- **Canales Reverb aislados**: los eventos (`MessageCreated`, `MessageStatusUpdated`,
  `ConversationUpdated`) se emiten en el canal **privado por conversación**
  `tenant.{tenantId}.conversations.{conversationId}`; `routes/channels.php` autoriza con
  `belongsToTenantById` y el frontend se suscribe solo a la conversación abierta (sin canales
  globales). Un usuario del tenant A no puede autenticarse al canal de B (ReverbChannelAuthTest).
  El polling de la lista (30 s) respeta los mismos permisos que el índice.
- **Envío seguro**: el `body` se valida en el backend (required, string, max 4096) y el mensaje
  queda `pending` (nunca `sent`) hasta que el job CAS lo envía. Los eventos broadcast no exponen
  datos sensibles (payload vía `*Resource`, sin tokens ni metadata interna).

### Inyección SQL
- Eloquent/Query Builder con bindings. Sin concatenación de SQL.
- `phpstan` + revisión en code review.

### XSS / Output encoding
- Vue escapa por defecto. Respuestas JSON (nunca HTML server-rendered con datos de usuario).
- Validación estricta de tipos en `FormRequest`.

### CSRF
- API con Bearer token (sin cookies de sesión → no aplica CSRF clásico).
- Si se usan rutas web (Inertia), `VerifyCsrfToken` aplica.

### Webhooks de WhatsApp (crítico)
- **Verificación GET**: `hub.verify_token` comparado (hash_equals) contra `WHATSAPP_VERIFY_TOKEN`.
- **Firma POST**: `X-Hub-Signature-256 = HMAC-SHA256(APP_SECRET, raw_body)` comparada con
  `hash_equals`, calculada sobre el **cuerpo crudo exacto** (`$request->getContent()`). NUNCA
  re-serializar el JSON para verificar (rompería la firma). Rechazo con 401 si falla.
- **Idempotencia**: `webhook_events` (plataforma) con UNIQUE `provider_event_id`; insert con
  `ON CONFLICT DO NOTHING`. Duplicados (secuenciales o concurrentes) no se reprocesan.
- Payload validado contra esquema antes de tocar DB.

### SSRF (salida)
- El nodo `webhook` de los flujos permite POST externos con URLs configuradas por el tenant.
  Validación anti-SSRF: solo esquemas http/https, bloqueo de IPs privadas/loopback/metadata
  cloud, resolución de DNS y verificación de IP del host antes del request, allowlist por tenant.
- FASE 13 (ADR-045): el esquema se valida también en `WebhookUrlGuard`; el host debe ser literal
  (una variable jamás bypasea SSRF), sin credenciales en el URL. Los logs y la auditoría usan
  `WebhookUrlGuard::sanitizeForLog()` (sin userinfo/query/fragment), de modo que `flow_execution_logs`
  y `flow.webhook_called` nunca contienen `Authorization`, `api_key` ni query con secretos.

### Aislamiento de infraestructura compartida
- Redis y S3 son compartidos entre tenants: claves de cache/locks/rate-limit con prefijo
  `tenant:{id}:`, objetos S3 bajo `tenant/{tenant_id}/...`. Un tenant no puede leer claves/objetos
  de otro. Ver `multi-tenancy.md` §6.

### Headers de seguridad
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`,
  CSP para el SPA. `APP_DEBUG=false` en producción.

### Rate limiting
- `throttle` global en API (p. ej. 300/min) y específicos: envío de mensajes, IA, login.
- Las claves de throttle incluyen `tenant_id`/`user_id` (Redis compartido → nunca claves globales).

### Límites de uso (backend)
- `UsageGuard` valida cuota del plan ANTES de: enviar mensaje, ejecutar IA, crear contacto,
  publicar flow, procesar documento KB. Respuesta `TENANT_QUOTA_EXCEEDED`.

### Secreto de tokens de WhatsApp
- El `access_token` de cada WABA se guarda **cifrado** en `whatsapp_accounts.access_token`
  (atributo `encrypted`, cifrado con la `APP_KEY`). Nunca se devuelve en respuestas API (hidden
  + resource). Cada tenant usa su propio token para envío; el `App Secret` y el `verify token` de
  los webhooks son globales de la app y viven solo en `.env`. Rotación de tokens de números.
- El webhook solo existe en `api/webhooks/whatsapp` (público pero firmado); ningún otro endpoint
  expone datos de WhatsApp sin auth + permiso + tenant activo.

### Seguridad de archivos
- Uploads a S3 con ACLs privadas, URLs firmadas, validación MIME real + tamaño.
- Detección de tipo por contenido (no confiar en extension).

### Logs y auditoría
- `AuditLog` para acciones sensibles (ver `database.md`). Logs sin datos personales
  innecesarios, con `tenant_id`/`user_id`/correlation id.

### Motor de flujos (FASE 11, ADR-034..039)
- **Validación backend-first**: `FlowValidator` valida el grafo ANTES de publicar (un solo
  `is_start`, grafo conexo, `end` alcanzable, config de nodo por tipo). El frontend es
  read-only; no existe editor en FASE 11. `tenant_id` NUNCA se acepta del frontend (no existe
  como campo en los `FormRequest`; `BelongsToTenant` lo fuerza desde el contexto).
- **Anti-SSRF en nodos webhook**: `WebhookUrlGuard` bloquea IPs privadas/reservadas (localhost,
  loopback, rangos RFC1918, link-local, etc.) y exige esquema http/https. Las llamadas externas
  llevan `execution_id` (idempotencia del lado del destino). Los fallos del webhook NO exponen
  detalles internos; reintento con backoff (máx 3) y fallo permanente → ejecución `failed`.
- **Sin secretos por API**: los `*Resource` de FASE 11 no incluyen `tenant_id`; el nodo
  `webhook` solo expone `method` + `url` (headers/body nunca salen del config); el frontend
  renderiza un resumen del config (`nodeConfigSummary`), no el config crudo.
- **Triggers (FASE 14, UNIDAD 1, ADR-047)**:
  - **Validación backend-first**: `TriggerValidator` valida la config por tipo al crear,
    actualizar y publicar (422 `errors.config`); el frontend no decide nada. `tenant_id` nunca
    se acepta del cliente.
  - **Referencias seguras**: el trigger `schedule` referencia una conversación por UUID
    verificado dentro del tenant (404 genérico si no existe o es cross-tenant — no filtra
    existencia, patrón ADR-010/023). La resolución de conversación de `webhook` (U3) seguirá el
    mismo principio: nunca se confía en `tenant_id` del payload.
  - **Token de webhook**: generación CSPRNG (`bin2hex(random_bytes(32))`); en BD solo
    `config.token_hash` (sha256). El token en claro se devuelve una única vez en la respuesta de
    creación. `TriggerResource` redacta `token_hash`; jamás aparece en auditoría, logs o
    recursos. El cliente no puede enviar `token`/`token_hash` (422); al actualizar se preserva
    el hash existente (se regenera solo al pasar a `webhook`).
  - **Regla de publicación**: validar la config de los triggers al publicar y bloquear un
    segundo flujo con el mismo trigger genérico activo del mismo tipo (409
    `FLOW_ALREADY_PUBLISHED`) previene comportamientos ambiguos e intersecciones no deseadas.
- **Idempotencia y concurrencia**: dedupe de webhook de plataforma + `last_inbound_message_id`
  como barrera del motor (un inbound reprocesado jamás avanza dos veces) + UNIQUE parcial de
  ejecución activa por conversación + lock Redis por conversación. `pause/resume/cancel` solo
  sobre ejecuciones activas (409 sobre terminales); `handed_off` pausa el bot hasta que un
  agente reanuda.
- **Auditoría**: cada mutación (chatbot/flow/trigger/execution) se registra en `audit_logs`.

### Editor visual de flujos (FASE 12, ADR-040..044)
- **Autorización del lado servidor**: el editor es solo la capa de UX. Todo se valida en backend
  (`FlowValidator`, `FormRequest`); el frontend envía únicamente `{nodes[], connections[],
  base_updated_at?}`. `tenant_id` jamás viaja en el body (se fuerza desde el contexto);
  aislamiento A/B probado en FLOW-39. El agente (`flows.view`) abre el editor en read-only
  (FLOW-41); el composable ignora toda mutación y la barra oculta Guardar/Publicar.
- **Lock optimista (concurrencia)**: `base_updated_at` = `flows.updated_at` de la versión
  cargada. Si otro editor guardó antes → 409 `FLOW_CONFLICT` y NADA se escribe. La resolución
  (recargar / seguir / **sobrescribir explícito**) es una acción del usuario, jamás automática.
  Los secrets del nodo webhook solo se editan como `method`/`url`; headers/payload permanecen en
  el backend (FLOW-29).
- **Validación local solo como UX**: `flowValidation.ts` es espejo del validador del backend para
  feedback inmediato (badges, panel). El publish re-valida en servidor (`FLOW_INVALID`); los
  errores del servidor se mapean a nodos por nombre. El backend nunca confía en la validación del
  frontend.
- **Sin datos en localStorage**: el estado del editor y el lock viven en memoria; al recargar se
  obtiene la versión del servidor (nada de versiones fantasma locales).

## 3. Comprobaciones automatizadas

- PHPStan nivel alto.
- Tests de seguridad por fase (acceso no autorizado a cada recurso).
- GitHub Actions: lint, tests, `composer audit`, `npm audit`.
- Revisión de dependencias (Dependabot).
- Sentry captura excepciones; sin excepciones en entornos no dev.

## 4. Encriptación

- Datos en repositorio: atributos `encrypted` (tokens WhatsApp, secrets).
- En tránsito: HTTPS obligatorio (nginx/TLS).
- En reposo: cifrado a nivel de disco en infraestructura.

## 5. Checklist antes de cada release

- [ ] Tests de aislamiento tenant verdes.
- [ ] Tests de autorización (403/401) verdes.
- [ ] `composer audit` sin vulnerabilidades conocidas.
- [ ] Webhook signature test verde.
- [ ] Sin secretos en el repo (gitleaks en CI).
- [ ] Rate limits aplicados a rutas sensibles.
- [ ] (FASE 11) Aislamiento A/B de flujos verdes (FLOW-24), matriz de permisos `flows.*`
        verdes, validación de grafo en publicación y anti-SSRF del nodo webhook verdes.
