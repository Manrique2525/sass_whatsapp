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
- Policies donde existen (`TenantPolicy`, `TenantUserPolicy`, `TenantInvitationPolicy`) y
  enforcement de dominio en Application Services que delegan a `AuthorizationService`.
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
- **24 permisos granulares** (`tenants.view/update`, `users.view/invite/update/remove`,
  `roles.view/assign`, `agents.view/manage`, `audit.view`, `business_profile.view/update`,
  `whatsapp.view/manage`, `contacts.view/manage`, `conversations.view/manage/assign/claim`,
  `flows.view/manage`). Matriz:
  owner = todos; admin = operativo sin `roles.assign`; agent incluye lectura, `messages.send` y
  claim propio mediante `conversations.claim`, pero nunca `conversations.assign`;
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
  (owner/admin: asignar/transferir). FASE 15 U2 añade `conversations.claim` a owner/admin/agent sin
  ampliar `conversations.assign`: agent solo puede reclamarse a sí mismo una conversación handoff.
- **Aislamiento**: `conversations`/`conversation_participants`/`conversation_assignments` usan
  `BelongsToTenant` (scope global + forzado de `tenant_id` por `TenantContext`). El `tenant_id` no
  es fillable: assign/create/update ignoran un `tenant_id` del body (CONV-20/HMT-05), mientras
  claim lo prohíbe explícitamente. El `{conversation}` del path se resuelve SIN route-model binding implícito: el
  servicio filtra SIEMPRE por `tenant_id` del tenant autorizado; conversación ajena o inexistente
  → **404** (no revela existencia, ADR-010/023). Crear sobre un contacto de otro tenant → 404
  (tests CRITICOS CONV-18/19 A/B: crear sobre contacto de B y leer/modificar/asignar con usuario
  de B).
- **Asignación segura**: `assign`/`transfer` validan que el agente destino sea miembro del
  tenant con `status = active` en `tenant_users` (sin confiar en el frontend). Usuario fuera del
  tenant → 422 `AGENT_NOT_IN_TENANT`; sin permiso → 403. La transferencia cierra la asignación y
  participación previas (`unassigned_at`/`left_at`) y registra historial acumulativo.
- **Atomicidad U2 (ADR-052)**: assign/transfer/claim reutilizan el mismo `conversationLock` del
  motor y, dentro de una transacción, bloquean conversation con `FOR UPDATE`, revalidan actor,
  permiso y target membership bajo lock, mutan assignment/participant/`agent_id` y escriben audit.
  El orden fijo Redis → conversation row → memberships ordenadas evita carreras y deadlocks.
- **Claim anti-tampering**: `agent_id` y `tenant_id` están prohibidos; el target se deriva de
  `$request->user()->id`. Solo opera sobre `bot_paused=true`, `handoff_requested_at != null`, sin
  agente y status open/pending. Dos claimants producen un ganador y 409 para el otro.
- **Inconsistencias/races**: una proyección previa corrupta falla 409 controlado, no se repara
  silenciosamente. La UNIQUE parcial sigue como backstop y su violación nunca expone SQL. Un fallo
  tardío de audit revierte las tres proyecciones y no emite realtime.
- **Invariantes de handoff (FASE 15 U1, ADR-051/052)**: assignments/participants tienen
  `tenant_id` NOT NULL, FK a tenants, scope global y forzado desde `TenantContext`; nunca es
  mass-assignable. Una FK compuesta `(tenant_id, conversation_id)` impide referencias cruzadas
  entre tenants y un UNIQUE parcial impide más de una assignment abierta por conversación. El
  backfill deriva tenant exclusivamente de la conversación y aborta si no puede hacerlo.
- **Máquina de estados**: transiciones inválidas → 409 `CONVERSATION_INVALID_STATE` (nunca se
  muta `status` libremente vía PATCH); mismo estado = no-op. `status` validado contra el enum.
- **Runtime handoff U3**: Human opera solo en `open|pending`; nunca reabre `resolved|archived`.
  Bajo el lock del motor, una transacción con row lock fija `bot_paused`/`handoff_requested_at`,
  crea el aviso opcional y audita. No altera agente ni proyecciones. Resume usa el mismo orden,
  conserva timestamp/asignación y solo habilita futuros inbound; una pausa manual nueva limpia el
  marcador histórico para no habilitar claim por error.
- **Validación (backend)**: `ConversationIndexRequest` acota `per_page` a 100 y valida
  `status`/`agent_id`; `StoreConversationRequest` exige `contact_id` uuid; `AssignConversationRequest`
  exige `agent_id`. `ConversationResource` jamás expone `tenant_id`.
- **Uso interno sin auth**: `findOrCreateActiveForContact` (FASE 9) busca fuera del scope pero
  SIEMPRE filtrando por `tenant_id`; setea y libera `TenantContext` en `finally`. Reutiliza la
  conversación activa del contacto o crea una nueva; un contacto soft-deleted jamás se resucita.
- **Auditoría FASE 8/15**: `conversation.created`, `conversation.updated`, `conversation.assigned`,
  `conversation.transferred`, `conversation.claimed`, `conversation.closed`, `conversation.reopened`,
  `conversation.bot_paused`, `conversation.bot_resumed`.

### Mensajes (FASE 9)
- **Aislamiento**: `messages` usa `BelongsToTenant` (scope global + forzado de `tenant_id` por
  `TenantContext`). `MessageService` y los jobs (`ProcessIncomingWhatsAppMessage`,
  `ProcessWhatsAppStatusUpdate`, `SendWhatsAppMessage`) resuelven SIEMPRE con
  `withoutTenantScope()->where('tenant_id', ...)` del tenant resuelto/encolado → un webhook de un
  número del tenant B jamás persiste en datos del A (tests CRITICOS MSG-6 y STAT-8 A/B).
  `TenantContext` se setea solo alrededor de los creates y se libera en `finally` (sin
  contaminación entre jobs; el audit pasa `tenantId:` explícito porque el contexto ya se limpió).
- **Actor humano U3**: `messages.sent_by_user_id` es nullable y usa FK `nullOnDelete` con índice
  propio; no está en `$fillable` ni se acepta del cliente. Solo `origin=human` recibe el ID del
  usuario autenticado después de revalidar membership. Inbound, automation y handoff quedan null.
  Agents solo responden su `conversation.agent_id`; owner/admin conservan override administrativo.
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
- **Barrera handoff U3**: creación y worker etiquetan origen interno en metadata. El worker toma el
  mismo `conversationLock` con TTL mayor que su timeout y lo conserva durante el provider. Si
  observa `bot_paused` + `handoff_requested_at`, automation (incluido legacy sin actor) termina
  `failed/BOT_PAUSED_HANDOFF`, sin `message_send_attempts`, llamada Meta ni retry; human/handoff no
  se autocancelan. La carrera PostgreSQL/Redis usa procesos independientes.
- **Nunca confiar en el frontend**: los mensajes inbound provienen del webhook firmado; el
  outbound se crea por servicio. `tenant_id`, `sent_by_user_id`, `metadata`, `origin`, `direction`
  y `status` están prohibidos en el request; conversación, actor y origen se resuelven en backend.
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

### Canal tenant-wide Inbox (FASE 15 U4, ADR-053)
- **Autorización reforzada**: el canal `tenant.{tenantId}.inbox` usa `belongsToTenantWithPermission`
  que verifica membresía activa + `conversations.view` vía la matriz de código
  (`TenantPermission::permissionsForRole`), no registros spatie. Fail-closed: permiso
  inexistente → false.
- **Aislamiento**: usuario de otro tenant → 403 (fallo en `belongsToTenantById`). Membresía
  inactiva → 403. Todos los agentes del mismo tenant reciben el evento (no es por conversación).
- **Payload seguro**: `ConversationResource` serializa campos seleccionados (sin `tenant_id`
  directo, sin `context`/`flow_execution_id`). `event_id` UUID para dedupe idempotente.
- **Tests**: RT-01..RT-04 (channel auth), RT-05..RT-15 (event emission), RT-15 (tenant switch
  isolation), FRT-01..FRT-10 (frontend subscription/dedupe).

### Inbox operativo y UX de handoff (FASE 15 U5)
- **Scope filters no filtran cross-tenant**: scope `mine`/`all`/`unassigned` se ejecutan
  SIEMPRE bajo el `TenantContext` del middleware; el filtro se aplica DESPUÉS del scope global
  `TenantScope` → imposible ver conversaciones de otro tenant (aislamiento garantizado por
  diseño, ver `multi-tenancy.md`).
- **Counts tenant-scoped**: `inboxCounts()` calcula los 3 contadores (all/mine/unassigned)
  dentro del mismo TenantContext; no se filtran por el scope activo → siempre reflejan el
  estado real del tenant.
- **Claim button visibility**: el botón de claim solo aparece para conversaciones
  `unassigned` (`agent_id IS NULL AND handoff_requested_at IS NOT NULL`); conversaciones
  asignadas o sin handoff no muestran el botón.
- **Self-exclusion from dropdown**: agentes se excluyen de la lista de asignación/transfer
  (dropdown) para evitar assign/transfer a sí mismos; la exclusión es puramente de UI, no de
  backend (repetir el mismo agente es idempotente sin error).
- **Draft preserve on error**: `MessageComposer` conserva el draft del usuario si el envío
  falla (clear only when `sending` goes false); previene pérdida de mensaje ante errores de
  red o backend.
- **sent_by_user_id prohibited**: `StoreMessageRequest` prohíbe `sent_by_user_id` en el
  payload; el backend lo resuelve exclusivamente del usuario autenticado tras revalidar
  membership activa y assignment propia para agent (override owner/admin).
- **Inactive membership denied**: claim/assign/transfer revalidan `tenant_users.status = active`
  dentro de la operación atómica; membresía desactivada mientras el lock está activo produce
  409 controlado, no reescritura silenciosa.
- **Security matrix (12/12)**: scope isolation (3), claim visibility (1), self-exclusion (1),
  draft preserve (1), sent_by_user_id (1), inactive membership (1), cross-tenant FK (1),
  duplicate HumanNode (1), inbound during handoff (1), resume-then-inbound (1).

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
- **Trigger tag diferido (ADR-050)**: FASE 14 valida `config.tags` pero no ejecuta triggers tag.
  No se introdujeron eventos/listeners ni endpoints temporales que pudieran aceptar
  `tenant_id`, `flow_id` o `conversation_id` del cliente. FASE 20 deberá resolver tenant desde
  el writer centralizado y aplicar lock por conversación, idempotencia y anti-recursión.

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

### Disparo de triggers schedule (FASE 14, UNIDAD 2, ADR-048)
- **Command fuera de contexto**: `flow:fire-schedule-triggers` corre sin TenantContext (CLI
  global); usa `whereIn` con subqueries `withoutTenantScope()` para evitar el filtro global.
  Nunca ejecuta flujos directamente — solo despacha jobs.
- **Job revalida todo**: `StartFlowFromSchedule` (TenantAwareJob) revalida todas las condiciones
  en su propio TenantContext (trigger activo, tipo schedule, flow publicado, chatbot, cron,
  conversación del tenant, bot no pausado, sin ejecución activa). Nunca confía en el command.
- **6 capas anti-duplicación**: command `withoutOverlapping` + `ShouldBeUnique` por trigger +
  `Cache::lock` por trigger + `conversationLock` + `findActive` + UNIQUE parcial en BD.
- **TenantAwareJob save/restore**: `handle()` guarda el contexto previo y lo restaura en
  `finally` (o limpia si no había). Esto previene la destrucción del contexto del padre cuando
  jobs hijos se ejecutan sincrónicamente — es un fix de producción, no un workaround de tests.
- **Aislamiento tenant**: conversación de otro tenant → no ejecuta (SCHED-11); aislamiento
  completo A/B probado (SCHED-12); conversación inexistente → no-op (SCHED-10).

### Webhook público de flujos (FASE 14, UNIDAD 3, ADR-049)
- **Endpoint público sin auth Bearer**: `POST /api/webhooks/flows/{trigger}`. Autenticación por
  `Authorization: Bearer {token}` comparado con `config.token_hash` vía `hash_equals` (SHA-256).
  Error siempre 401 genérico (no revela existencia del trigger).
- **Tenant desde trigger**: el tenant se resuelve EXCLUSIVAMENTE del trigger (nunca del payload).
  `TenantContext::setId($trigger->tenant_id)` después de encontrar el trigger con
  `withoutTenantScope()`.
- **Resolución de conversación**: `config.conversation_by` (`conversation_id` | `contact_id` |
  `phone`). Cada resolución valida `tenant_id` del resultado contra el tenant del trigger.
  Conversación de otro tenant → 400 genérico (no filtra existencia).
- **Idempotencia**: `Idempotency-Key` header → `Cache::lock` (60s TTL). Duplicado → 409
  `WEBHOOK_DUPLICATE`. Sin header → genera uno automático único.
- **Rate limiting**: `throttle:flow-webhook` — 60 req/min por IP (definido en AppServiceProvider).
- **Payload seguro**: máximo 64KB; solo campos permitidos (`conversation_id`, `contact_id`,
  `phone`, `payload`). `tenant_id` del body se ignora. JSON inválido → 400.
- **Job defensa en profundidad**: `StartFlowFromWebhook` revalida todas las condiciones en su
  propio TenantContext (tenant activo, trigger activo/tipo webhook, flow publicado, chatbot,
  bot no pausado, sin ejecución activa). 5 capas de protección: controller idempotency +
  ShouldBeUnique + revalidación en job + conversationLock + findActive + UNIQUE parcial.
- **Seguridad**: sin eval/exec; sin SSRF; token/hash nunca en logs/auditoría/responses;
  `TriggerResource` redacta `token_hash`.

### Tags y asignación a contactos (FASE 20 U3)
- **Autorización**: `tags.view` (todos los roles) y `tags.manage` (owner/admin). Asignar/remover exige `tags.manage`; agent → 403.
- **Aislamiento fail-closed**: la asignación batch valida TODOS los `tag_ids` contra el tenant ANTES de mutar; un solo id ajeno/inexistente → **403** sin escribir ninguna fila. En remove, tag/contacto de otro tenant → **404** (no revela existencia).
- **Nunca confiar en el frontend**: el body solo acepta `tag_ids` (array 1..20, uuid, distinct); `tenant_id` viene SIEMPRE de `TenantContext`/middleware.
- **Eventos seguros**: `TagAssigned`/`TagRemoved` transportan solo IDs estables (sin modelos Eloquent ni PII), llevan `tenant_id` explícito y son `afterCommit = true`.
- **Resolución Contact→Conversation**: filtra SIEMPRE por `tenant_id` (jamás resuelve conversaciones de otro tenant).

### Trigger automático por tag (FASE 20 U4, ADR-050)
- **Primer listener del codebase**: `DispatchTagTriggerJob` escucha `TagAssigned` y despacha `StartFlowFromTag` por cada trigger activo del tenant con `config.tags` matching.
- **Anti-recursión**: `origin=Flow` → skip completo (el listener descarta el evento antes de buscar triggers). Esto previene cadenas tag→flow→tag.
- **Defensa en profundidad (job)**: revalida tenant, trigger activo, type=Tag, flow Published, config re-match exacto (case-sensitive), contacto existente, conversación no nula.
- **conversationLock**: `FlowEngine::handleScheduleTrigger()` adquiere lock interno; el job tiene Cache::lock por trigger.
- **bot_paused / ejecución activa**: verificados dentro de `handleScheduleTriggerLocked()` (pipeline existente).
- **Auditoría**: `flow.tag_triggered` con `{trigger_id, flow_id, conversation_id, tag_name}`.
- **Semántica EVENT**: cada `TagAssigned` dispara matching triggers independientemente.
- **Sin ejecución por eventos origin=Flow**: solo `origin=Manual` (API) activa triggers. Un tag asignado por un flujo NUNCA dispara otro flujo.
- **Auditoría total**: `tag.created`, `tag.updated`, `tag.deleted` (U2), `tag.assigned`, `tag.removed` (U3), `flow.tag_triggered` (U4).

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
- [ ] (FASE 14 U2) Aislamiento de schedule triggers verdes (SCHED-11/12), TenantAwareJob
        save/restore verdes (TenantContextJobTest), command no duplica (SCHED-07), lock
        liberado (SCHED-08/09).
- [ ] (FASE 14 U3) Aislamiento webhook A/B verdes (WEBHOOK-19), token auth verdes
        (WEBHOOK-01..05), idempotencia verdes (WEBHOOK-10/11), secretos nunca en
        logs/audit (WEBHOOK-15/18), rate limit verdes (WEBHOOK-16).
- [x] (FASE 14 cierre / FASE 20 U3–U4) Trigger tag: ejecución automática IMPLEMENTADA en FASE 20 U4.
        `DispatchTagTriggerJob` (primer listener del codebase) escucha `TagAssigned` y despacha
        `StartFlowFromTag` por trigger matching. Anti-recursión: origin=Flow → skip. Semántica
        EVENT (cada asignación dispara independientemente). Defensa en profundidad completa.
        Auditoría `flow.tag_triggered`.
- [ ] (FASE 16 U1) API key OpenAI nunca en response, logs, auditoría, exceptions ni frontend.
        Provider stateless re: tenant. Tests con Http::fake (sin llamadas reales).
        Binding AIProviderInterface → OpenAIProvider en AppServiceProvider (singleton lazy).
- [ ] (FASE 16 U2) AI node output tratado como texto plano (sin eval). Prompt/response
        nunca completos en logs. bot_paused verificado antes de provider call. VariableGuard
        en output_variable. Aislamiento cross-tenant AI verificado (AI-S01..S10, AI-MT-01..06).
- [ ] (FASE 16 U4) Telemetría AI usa safe schema (TelemetryPayload VO). PII (prompt,
        response, contact, business, custom.secret) nunca en payload. Latencia con monotonic
        clock. Tokens validados >= 0. Tests AI-U01..U25.
- [ ] (FASE 16 U5) Security matrix AI-SEC-F01..F12 formaliza 12 propiedades de seguridad:
        API key no logs/frontend/audit, prompt/response no telemetry, PII no telemetry,
        tenant isolation, output plain text, bot_paused blocks, provider DI, config injection
        ignored, exceptions sanitized. Bug fix: RuntimeException → AIProviderException.
        RAG/FAQ/Billing boundaries verificados ausentes. DDL boundary verificado (0 migrations).
        FASE 16 cerrada. Suite total: 763 tests / 3055 assertions.
- [x] (FASE 22 U1) Notification data model: tenant_id auto-asignado (BelongsToTenant),
        FK CASCADE en tenant, FK SET NULL en user (preserva historial), user_id nullable
        (soporta tenant-wide), data JSON sin PII (solo metadata segura), soft deletes
        (audit trail), sin unique constraint (múltiples notificaciones legítimas).
        Tests NOTIF-DB-01..15 (model) + NOTIF-ENUM-01..04 (enums) + NOTIF-PG-01..12 (PG).
- [x] (FASE 22 U2) Notification dispatch: listener CreateNotificationFromInboxChange
        solo escucha HandoffRequested/Assigned/Transferred (Claimed/BotResumed/ConversationUpdated
        ignorados). NotificationService valida membresía activa antes de crear. Fan-out
        per-user (read_at es por fila). TenantContext save/restore en listener (ADR-083)
        preserva contexto del caller. AuditLogger registra notification.created con payload
        seguro (notification_id, type, priority, target_user_id, conversation_id — sin PII).
        Tests NOTIF-SVC-01..10 + NOTIF-HO-01..10 + NOTIF-ASG-01..10 + NOTIF-MT-U2-01..06
        + NOTIF-SEC-01..08 + NOTIF-PG-U2-01..06.
- [x] (FASE 22 U3) Notification API + permissions + read state:
        `notifications.view` permission (Owner/Admin/Agent). CAS-style mark-read
        (`WHERE read_at IS NULL`) — idempotent, concurrent-safe without locks.
        Ownership enforcement: every query includes `user_id` + `tenant_id`.
        Cross-user/cross-tenant → 404 (hides existence). `NotificationResource` does NOT
        expose `tenant_id` or `user_id`. Input validation (`read_status` enum, `per_page`
        1..100). Bulk mark-all-read returns affected count. No audit for mark-read (UI state).
        Tests NOTIF-API-01..15 + NOTIF-PERM-01..06 + NOTIF-MT-U3-01..10 +
        NOTIF-SEC-U3-01..10 + NOTIF-CON-01..03.
