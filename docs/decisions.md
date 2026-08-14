# Decisiones de arquitectura (ADRs)

Formato: problema → decisión → consecuencia. Fechadas y en orden cronológico.

## ADR-001 · Arquitectura modular DDD-inspired (no monolito tradicional)

- **Estado**: Aceptado · FASE 0
- **Contexto**: Un SaaS comercial necesita límites claros, testabilidad y sustituibilidad de
  proveedores externos (WhatsApp, IA, Stripe). Un monolito Laravel tradicional concentra lógica
  en Controllers/Models y acopla infraestructura con dominio.
- **Decisión**: Capas `Domain` (lógica pura) → `Application` (casos de uso) →
  `Infrastructure` (proveedores) → `Http` (orquestación) → `Jobs` (async). Eloquent models viven
  en Domain/Models como puerta de datos; la lógica de negocio no usa framework cuando es pura.
- **Consecuencias**: algo más de ceremonia; requiere disciplina en imports (Domain no depende de
  Laravel en lógica pura). Beneficios: tests unitarios sin framework, swap de proveedores trivial,
  auditoría fácil de dónde vive cada regla.

## ADR-002 · Estrategia local sin Docker/PostgreSQL/Redis en la máquina

- **Estado**: ACEPTADO · FASE 0 (decisión del usuario: instalar Docker Desktop)
- **Contexto**: La máquina tiene PHP 8.3, Composer y Node, pero NO Docker, NO WSL con distros,
  NO PostgreSQL ni Redis locales. `pdo_pgsql.dll` existe en `C:\php\ext` pero no está habilitado.
- **Opciones**:
  - A) Instalar Docker Desktop → Compose completo (app, nginx, postgres, redis, worker,
    minio, mailpit). Entorno fiel a producción.
  - B) Instalar PostgreSQL 16 + Redis nativos Windows y usar `php artisan serve`.
- **Decisión**: A — instalar Docker Desktop y usar el Compose completo. Pendiente en FASE 1:
  instalar Docker Desktop, habilitar `pdo_pgsql` en `php.ini`, arrancar los servicios.
  El `.env` y las migraciones son idénticos; el CI usará Docker de todos modos.

## ADR-003 · Shared database + tenant_id

- **Estado**: Aceptado · FASE 0
- **Contexto**: 1K–10K tenants. DB-per-tenant multiplica conexiones, migraciones y backups;
  schema-per-tenant complica pgvector (extensiones por schema). Shared DB con `tenant_id` + scope
  global es la opción estándar de la industria para este rango.
- **Decisión**: Shared database. `tenant_id` en todas las tablas de dominio. PKs UUID.
  `TenantContext` + scope global + policies + validación en escrituras. Índices compuestos que
  inician por `tenant_id`.
- **Consecuencias**: riesgo de "fuga entre tenants" mitigado por scope + policies + tests de
  aislamiento en cada fase. Migración a shards viable por UUID si un tenant crece fuera de rango.

## ADR-004 · Inertia.js + Vue SPA (no SPA puro)

- **Estado**: Aceptado · FASE 0
- **Contexto**: El frontend necesita velocidad de desarrollo, SSR no es crítico, y la seguridad
  (validación en backend) es prioridad.
- **Decisión**: Vue 3 + TypeScript + Tailwind + Inertia.js (páginas server-driven con datos en
  las props), más los endpoints REST `api/v1` para la API pública y el webhook. Reverb + Echo
  para tiempo real.
- **Consecuencias**: rutas web protegidas por middleware web + CSRF; API separada con Sanctum.
  El Flow Builder usa Vue Flow (módulo independiente dentro del SPA).

## ADR-005 · pgvector en lugar de un buscador vectorial separado

- **Estado**: Aceptado · FASE 0
- **Contexto**: Base de conocimiento por tenant con búsqueda semántica (RAG).
- **Decisión**: PostgreSQL + extensión `vector` (HNSW). Un solo motor de datos, menos servicios,
  aislamiento tenant trivial (`WHERE tenant_id = ?`).
- **Consecuencias**: suficiente para el volumen esperado. Si el corpus crece mucho, se podría
  migrar a un índice externo sin tocar el dominio (el buscador está detrás de una interfaz).

## ADR-006 · El webhook nunca procesa lógica en el request

- **Estado**: Aceptado · FASE 0
- **Contexto**: Meta exige responder rápido (200) y reenviar eventos si tarda. Procesar IA/DB
  dentro del webhook es frágil y lento.
- **Decisión**: Webhook = validación de firma + idempotencia + encolado (`ProcessIncomingWhatsAppMessage`,
  `ProcessWhatsAppStatusUpdate`). Todo el trabajo pesado en workers.
- **Consecuencias**: latencia de respuesta del webhook mínima; la cola debe ser monitorizada.
  Los jobs re-establecen `TenantContext` (ADR-008).

## ADR-007 · OpenAI detrás de AIProviderInterface

- **Estado**: Aceptado · FASE 0
- **Contexto**: El costo de IA es alto y el mercado cambia rápido.
- **Decisión**: `AIProviderInterface` (`classifyIntent`, `generateResponse`,
  `summarizeConversation`, `extractLeadData`, `createEmbedding`). `OpenAIProvider` como
  implementación inicial. Control de costos por tenant (usage_records) y fallback.
- **Consecuencias**: añadir otro proveedor = nueva implementación + binding. La lógica de límites
  y RAG es independiente del proveedor.

## ADR-008 · Jobs con TenantContext propio

- **Estado**: Aceptado · FASE 0
- **Contexto**: Los jobs pueden ejecutarse con el usuario original ausente o con contexto
  mezclado entre tenants.
- **Decisión**: Todo job de dominio tenant inicia estableciendo `TenantContext` desde su payload
  (`tenant_id`), y lo limpia al terminar. Nunca se asume el tenant de quien encoló.
- **Consecuencias**: un bug de contexto no mezcla datos entre tenants; test específico de fugas.

## ADR-009 · Límites de uso validados en backend (UsageGuard)

- **Estado**: Aceptado · FASE 0
- **Contexto**: El frontend no puede ser la única barrera contra abuso de cuota.
- **Decisión**: Antes de enviar mensaje, llamar IA, crear contacto o publicar flow, un
  `UsageGuard` valida la cuota del plan (lectura de `usage_records` + `plans.limits`).
  Error `TENANT_QUOTA_EXCEEDED`.
- **Consecuencias**: coste predecible por tenant; tests de límites en cada módulo de consumo.

## ADR-010 · Errores de acceso a datos ajenos = 404 (no 401/403)

- **Estado**: Aceptado · FASE 0
- **Contexto**: Revelar la existencia de un recurso de otro tenant es una fuga de información.
- **Decisión**: El scope global hace que las entidades de otros tenants no existan desde la
  perspectiva del tenant → `ModelNotFoundException` → 404. Policies dan 403 solo cuando es
  seguro (p. ej. acciones de administración del propio tenant).
- **Consecuencias**: UX neutra (recurso inexistente), sin enumeración de recursos.

## ADR-011 · Autenticación: Sanctum stateful (interno) + Bearer (externo)

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: El SPA Inertia necesita sesión con CSRF; la API pública necesita tokens.
  Usar solo Bearer dentro del navegador obliga a gestionar tokens en JS; usar solo cookies
  imposibilita clientes externos.
- **Decisión**: Sanctum en modo stateful (cookies + CSRF) para la app interna (mismo origen);
  tokens Bearer (`personal_access_tokens`) solo para integraciones externas. Ambos flujos pasan
  por el middleware `tenant`.
- **Consecuencias**: una sola estrategia de auth para el frontend (sin tokens en JS),
  API pública para partners. No aplica CSRF en rutas Bearer; `EnsureFrontendRequestsAreStateful`
  solo para el origen interno.

## ADR-012 · Roles por tenant con spatie/laravel-permission en modo teams

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: Un usuario puede ser `owner` en Tenant A y `agent` en Tenant B. Los roles
  globales de spatie (sin teams) no permiten roles distintos por tenant.
- **Decisión**: Activar `teams` de spatie con `team_id = tenant_id`. Roles `owner/admin/agent`
  asignados por team; `super_admin` es rol global de plataforma (sin team). La membresía se
  registra además en `tenant_users` (fuente de verdad para membership y switch).
- **Consecuencias**: permisos evaluados con el tenant activo; las queries de roles deben
  especificar `setTeamsId(TenantContext::id())`. Test de que el rol de un tenant no aplica en otro.

## ADR-013 · Webhook único por App + resolución de tenant por phone_number_id + outbox

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: En Meta, el webhook y el App Secret son de la **app** (uno para todos los
  tenants). La dedupe debe ser global (los ids de Meta son únicos) y la resolución del tenant
  no puede venir del env sino del payload.
- **Decisión**: `webhook_events` a nivel plataforma con UNIQUE `provider_event_id`
  (`ON CONFLICT DO NOTHING`); resolución de tenant por `metadata.phone_number_id` →
  `whatsapp_phone_numbers.phone_id`; `status` del evento (received/enqueued/processed/failed)
  + sweeper que re-encola eventos huérfanos (outbox).
- **Consecuencias**: sin pérdida de eventos ante crash entre insert y encolado; la resolución
  de tenant es una consulta indexada dentro del webhook (ligera). Webhook desconocido → 200 + log.

## ADR-014 · Aislamiento de Redis y S3 por namespace

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: Redis y S3 son compartidos entre tenants. Claves de cache, locks, rate-limit y
  objetos de storage son puntos clásicos de fuga cross-tenant si no se namespacan.
- **Decisión**: Prefijo `tenant:{id}:` en TODAS las claves de cache/lock/throttle; objetos S3
  bajo `tenant/{tenant_id}/...` con URLs firmadas del propio tenant.
- **Consecuencias**: costo mínimo, riesgo de fuga eliminado. Tests de aislamiento específicos.

## ADR-015 · Concurrencia en flow engine: lock por conversación + unique activo

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: Dos mensajes casi simultáneos del mismo cliente pueden ser procesados por dos
  workers → doble ejecución de flow, respuestas duplicadas o carrera en `variables`.
- **Decisión**: Lock de Redis (`lock:tenant:{id}:flow:{conversation_id}`) alrededor de
  `FlowEngine::handleMessage` + UNIQUE parcial en `flow_executions`
  `(tenant_id, conversation_id) WHERE status IN ('running','waiting')` + envíos con `ShouldBeUnique`
  y CAS sobre `message.status='pending'`.
- **Consecuencias**: ejecución y envío idempotentes/concurrentes seguros. El lock debe tener
  timeout corto y no bloquear la cola (wait con release si no se adquiere).

## ADR-016 · Usuario multi-tenant con tenant activo (`users.current_tenant_id`)

- **Estado**: Aceptado · FASE 0 (revisión)
- **Contexto**: El requisito de un usuario en varios tenants con roles distintos y switch exige
  un modelo explícito (el middleware anterior asumía un único tenant por usuario).
- **Decisión**: `users.current_tenant_id` nullable = tenant activo; `tenant_users` = membresía +
  rol + status; `POST /api/v1/auth/switch-tenant` valida membresía y actualiza el activo.
  El middleware `tenant` lee el activo; nunca del path.
- **Consecuencias**: soporte real multi-tenant por usuario; el frontend recibe la lista de
  tenants en `/auth/me` y permite switchear (re-suscribiendo canales Reverb).

## ADR-017 · Modelo de datos auth FASE 2: `tenant_users` sin FK y `current_tenant_id` sin FK

- **Estado**: Aceptado · FASE 2
- **Contexto**: La tabla `tenants` se crea en FASE 3, pero la autenticación necesita persistir
  membresías y rol por tenant desde ya.
- **Decisión**: `tenant_users(tenant_id, user_id, role)` y `users.current_tenant_id` se crean en
  FASE 2 SIN foreign key (solo índice + UNIQUE `(user_id, tenant_id)`). Las FK a `tenants.id` se
  añaden en FASE 3 vía migración cuando exista la tabla. `role` es uno de `owner/admin/agent`
  (enum `UserRole`); `super_admin` es rol global de plataforma (sin tenant).
- **Consecuencias**: migraciones de FASE 2 aplican sin tabla `tenants`; riesgo nulo de datos
  huérfanos porque la escritura de membresías llega en FASE 3. Los roles se materializan con
  spatie en modo teams (`team_id = tenant_id`).

## ADR-018 · Roles iniciales: spatie en modo teams con `team_foreign_key = tenant_id`

- **Estado**: Aceptado · FASE 2
- **Contexto**: spatie/laravel-permission soporta multi-tenancy nativamente mediante "teams".
- **Decisión**: `config/permission.php` → `teams => true`, `team_foreign_key => 'tenant_id'`.
  Roles base (seeder `RolesAndPermissionsSeeder`): `super_admin` (global) y `owner`, `admin`,
  `agent` (por tenant). La asignación `assignRole` requiere `setPermissionsTeamId(tenant_id)`.
- **Consecuencias**: permisos/roles aislados por tenant de serie (la columna `tenant_id` viaja en
  `roles`, `model_has_roles` y `model_has_permissions`). Tests que verifican que un rol asignado
  al tenant A no aparece para el tenant B.

## ADR-019 · Doble canal de auth: sesión (Inertia) + Bearer (API) con error estándar

- **Estado**: Aceptado · FASE 2
- **Contexto**: El SPA interno usa Inertia (sesión + CSRF); los clientes externos consumen la API
  con tokens. Ambos comparten la misma lógica de negocio.
- **Decisión**: Web = Inertia (`auth:web`, `guest`, `verified`, CSRF). API = `auth:sanctum`
  Bearer con `EnsureFrontendRequestsAreStateful` prepended (permite cookies si el cliente es el
  mismo origen). La lógica de autenticación vive en `app/Application/Users/Services`
  (`AuthenticateUser`, `RegisterUser`, `SendPasswordResetLink`, `ResetUserPassword`,
  `VerifyUserEmail`) compartida por ambos canales.
- **Consecuencias**: un solo set de reglas de negocio; dos contratos de transporte. Errores API
  con formato estándar `{message, code, errors}` (`VALIDATION_ERROR`, `UNAUTHENTICATED`,
  `RATE_LIMITED`) vía renderers en `bootstrap/app.php`.

## ADR-020 · TenantContext fail-safe: lecturas vacías y escrituras con excepción sin contexto

- **Estado**: Aceptado · FASE 3
- **Contexto**: Un bug que consultara modelos tenant sin `TenantContext` activo (p. ej. en un
  worker tras un `finally` mal puesto) podría devolver datos de un tenant equivocado o todos.
- **Decisión**: `TenantScope::apply()` sin contexto añade `whereRaw('1 = 0')` (devuelve vacío,
  jamás expone datos) y `BelongsToTenant::creating` sin contexto lanza
  `TenantContextMissingException` (toda escritura requiere contexto). El middleware `tenant`
  y `TenantAwareJob` garantizan el contexto en HTTP/cola; las lecturas cross-tenant de soporte
  pasan SOLO por `scopeWithoutTenantScope()` dentro de servicios de aplicación autorizados.
- **Consecuencias**: dos capas de fallo seguro sin filtraciones; los tests verifican que sin
  contexto no hay fuga y que las escrituras fallan con error claro.

## ADR-021 · Jobs tenant-aware: `tenant_id` explícito en el payload (`TenantAwareJob`)

- **Estado**: Aceptado · FASE 3
- **Contexto**: Los workers son procesos de larga duración: el contexto de un job podía fugar al
  siguiente, y confiar en el tenant de quien encola es incorrecto si el usuario cambió de tenant.
- **Decisión**: Trait `TenantAwareJob` en `app/Jobs/Concerns`. El job transporta `tenantId`
  (setter encadenable `forTenant()`), `handle()` (final) establece `TenantContext::setId()` y lo
  libera en `finally`; la lógica vive en `executeInTenantContext()`. El job usa SIEMPRE su propio
  tenant, nunca el contexto existente al encolarse.
- **Consecuencias**: aislamiento por job garantizado y testeado (jobs de A y B en el mismo
  proceso, contexto limpio tras ejecución y ante excepción). Regla: TODO job de dominio tenant
  debe usar el trait.

## ADR-022 · Canales Reverb: sin comodín `*`, patrón explícito `tenant.{tenantId}.<recurso>.{recursoId}`

- **Estado**: Aceptado · FASE 3 (corrige diseño previo)
- **Contexto**: El diseño inicial usaba `private-tenant.{tenant_id}.conversations`. Al
  implementar `channels.php` se descubrió que Laravel
  (`Broadcaster::channelNameMatchesPattern`) escapa los puntos ANTES de evaluar, por lo que
  `tenant.{tenantId}.*` se convierte en `tenant\.([^\.]+)\.*` = "cero o más puntos" y NUNCA casa
  con `tenant.UUID.conversations.1`. No existe wildcard `*`.
- **Decisión**: Un patrón explícito por recurso: `tenant.{tenantId}.conversations.{conversationId}`.
  El callback valida siempre la pertenencia del usuario al tenant (`belongsToTenantById`); estar
  autenticado no basta. Todo nuevo canal del tenant sigue este patrón.
- **Consecuencias**: suscripciones correctas con auth real; test que verifica que un usuario del
  tenant A recibe `false` en canales del tenant B (con `TestAuthBroadcaster`).

## ADR-023 · Switch de tenant como caso de uso con semántica 404/409 + auditoría

- **Estado**: Aceptado · FASE 3
- **Contexto**: Cambiar el tenant activo debe validar membresía, no filtrar existencia, registrar
  la acción y notificar al resto de servicios.
- **Decisión**: `SwitchTenant` (Application service) valida `tenant_users` (si no es miembro →
  `TenantMembershipException` → el controller devuelve 404), valida `status === Active` (inactivo
  → 409 `TENANT_NOT_ACTIVE`), persiste `current_tenant_id`, registra en `audit_logs`
  (`tenant.switched`) y dispara `TenantSwitched`. El controller libera `TenantContext` en
  `finally` para no dejar estado colgando (el endpoint no vive bajo el middleware `tenant`).
- **Consecuencias**: sin enumeración de tenants ajenos (404), tenant suspendido no usable (409),
  trazabilidad de switches y aviso a la capa de tiempo real.

## ADR-024 · Tests deterministas con `<server>` en phpunit.xml (precedencia de env en Laravel 12)

- **Estado**: Aceptado · FASE 3
- **Contexto**: En Docker, la suite fallaba con 419 (CSRF) y jobs encolados sin procesar. Laravel
  12 resuelve `env()` con la inmutabilidad de Dotenv en este orden: `$_SERVER` → `$_ENV` →
  `getenv()`. Las variables de docker-compose (DB_CONNECTION=pgsql, QUEUE_CONNECTION=redis,
  SESSION_DRIVER=redis, CACHE_STORE=redis...) viven en `$_SERVER` y ganaban a los `<env>` de
  phpunit (que solo pueblan `$_ENV` y `putenv`). Consecuencia: los tests corrían contra postgres/
  redis/smtp reales y el app env quedaba en `local` (sin bypass de CSRF).
- **Decisión**: phpunit.xml declara la plataforma de tests con `<server>` (que escribe
  `$_SERVER` de forma incondicional): APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
  CACHE_STORE=array, QUEUE_CONNECTION=sync, SESSION_DRIVER=array, MAIL_MAILER=array,
  BROADCAST_CONNECTION=null, etc. Además se eliminó el `APP_ENV: local` redundante de
  docker-compose (la fuente única es `.env`).
- **Consecuencias**: la misma suite es verde local y en el contenedor (93 tests). Regla: la
  configuración crítica de tests se declara con `<server>`, no `<env>`.

## ADR-025 · Migración de spatie teams de `unsignedBigInteger` a UUID para `tenant_id`

- **Estado**: Aceptado · FASE 4
- **Contexto**: Los roles/permisos spatie en modo teams se crearon en FASE 2 con
  `team_foreign_key = tenant_id`, pero las columnas `tenant_id` de `roles`,
  `model_has_roles` y `model_has_permissions` eran `unsignedBigInteger`, incompatibles con
  `tenants.id` (UUID). No hay FK reales entre spatie y `tenants`.
- **Decisión**: Migración `2026_08_13_210000` convierte esas tres columnas a UUID: se dropean
  PK (`model_has_roles_role_model_type_primary`, `model_has_permissions_permission_model_type_primary`)
  e índices (`roles_team_foreign_key_index`, `model_has_roles_team_foreign_key_index`,
  `model_has_permissions_team_foreign_key_index`) y se recrean tras el cambio. `roles.tenant_id`
  queda nullable (los roles globales viven con NULL); las de `model_has_*` NOT NULL. Las tablas
  spatie están vacías en el estado documentado, por lo que es seguro.
- **Consecuencias**: PostgreSQL no castea bigint→uuid automáticamente: la conversión usa
  `USING tenant_id::text::uuid` (NULL → NULL); SQLite no lo necesita y usa `->change()`. La
  migración es driver-aware (pgsql vs. resto). Al no existir FK real, la coherencia la
  garantizan `TenantRoleManager`/el resolver, no la base de datos.

## ADR-026 · Autorización por tenant: matriz de código como fuente de verdad, spatie como espejo

- **Estado**: Aceptado · FASE 4
- **Contexto**: La autorización debe ser por rol DENTRO del tenant activo, no por el usuario
  global. Los permisos spatie se mantienen materializados por tenant (asignación real en
  `model_has_permissions`), pero una matriz en código es más legible, testeable y centralizada
  que depender de filas sincronizadas.
- **Decisión**: El enum `TenantPermission` (11 permisos) expone `permissionsForRole()` con la
  matriz owner/admin/agent (owner = todos; admin = gestión operativa sin `roles.assign`; agent =
  solo lectura). `AuthorizationService` exige SIEMPRE: tenant activo (`isCurrentTenant`),
  membresía activa (`tenant_users.status = active`) y permiso en la matriz; sin membresía/no
  activo → `TenantMembershipException` → 404; sin permiso → `PermissionDeniedException` → 403
  `PERMISSION_DENIED`. `super_admin` es rol global (spatie sin team) y se autoriza aparte; la
  matriz le devuelve `[]`. Los roles spatie se mantienen como espejo de `tenant_users.role`
  mediante `TenantRoleManager` (que usa `syncRoles`, no `assignRole`, para reemplazar). El
  resolver `TenantTeamResolver` (override → `TenantContext` → `current_tenant_id` → null) fija el
  team en cada operación.
- **Consecuencias**: una sola fuente de verdad (el código), policies finas como wrappers
  (`TenantUserPolicy`, `TenantInvitationPolicy`), y los registros spatie solo como espejo. Los
  permisos de dominios futuros (whatsapp, contacts, chatbots, billing) se añaden en sus fases.

## ADR-027 · Invitaciones a tenant: token de un solo uso, solo se persiste el hash

- **Estado**: Aceptado · FASE 4
- **Contexto**: Agregar miembros requiere un flujo de invitación por email con expiración y
  no-reutilización, sin exponer datos sensibles en la base de datos.
- **Decisión**: Tabla `tenant_invitations` (UUID PK, `tenant_id`, `email`, `role`,
  `token_hash` sha256 único, `invited_by`, `status` enum pending/accepted/revoked/expired,
  `expires_at` a 7 días, `accepted_at`). Solo se persiste el hash; el token plano viaja solo en
  el enlace `/invitations/{token}` del email. El status es máquina de estados: pending es la
  única transición válida hacia accepted/revoked/expired (409 `INVITATION_ALREADY_ACCEPTED`,
  410 `INVITATION_REVOKED`/`INVITATION_EXPIRED`, 403 `INVITATION_EMAIL_MISMATCH`, 404 no
  encontrada). `accept` crea o reactiva la membresía (`tenant_users` activa) y materializa el rol
  en spatie. El web (`Invitations/Accept.vue`) consulta la invitación sin autenticación (el
  enlace ES la credencial) y solo acepta con sesión cuyo email coincide.
- **Consecuencias**: sin lista de tokens en claro en BD; no-reutilización garantizada por
  transición de status; invitaciones a email sin cuenta requieren registro previo (no se crean
  usuarios en el acto).

## ADR-028 · Business profile: invariante 1:1 con lazy-create y permisos granulares

- **Estado**: Aceptado · FASE 5
- **Contexto**: El chatbot necesita datos públicos del negocio (variables `{{business.*}}`), y
  el ERD define `business_profiles` 1:1 con `tenants`. Los docs no fijan campos ni ruta; la ruta
  documentada (`/tenants/current/business-profile`) contradecía la convención REST de FASE 4
  (`/tenants/{tenant}/...` con enforcement del activo), por lo que se unificó con §3.2.
- **Decisión**:
  - Ruta `GET/PUT /api/v1/tenants/{tenant}/business-profile` (mismas reglas que usuarios:
    `{tenant}` = activo, otro → 404). Se corrigió `api.md`.
  - Tabla `business_profiles` (UUID PK, `tenant_id` UNIQUE FK `cascadeOnDelete`). Campos:
    `name`, `description`, `category`, `address`, `website`, `email`, `phone`,
    `working_hours` (JSON). **Sin `logo`**: requiere upload/media, se añade con la fase de
    storage (evitar campos muertos).
  - **Lazy-create**: `BusinessProfileService::getOrCreateFor()` crea el perfil bajo demanda en la
    primera lectura para sostener el invariante 1:1 sin depender de la creación de tenants (que
    llega en fase posterior). La creación se audita (`business_profile.created`).
  - Permisos granulares en la matriz ADR-026: `business_profile.view` (todos los roles) y
    `business_profile.update` (owner/admin). El agente lee pero no escribe.
  - `tenant_id` no es fillable y no hay regla de validación para él (TenantContext + trait
    `BelongsToTenant` deciden la pertenencia; test BP-8).
  - Validación 100% backend en `UpdateBusinessProfileRequest` (email, URL, longitudes, formato de
    `working_hours`); actualización parcial por `fill()`.
- **Consecuencias**: un GET que crea es una escritura (auditada); la actualización con body vacío
  no audita `business_profile.updated` (no hay cambio); el frontend oculta el formulario a roles
  sin `business_profile.update`.

## ADR-029 · WhatsApp FASE 6: provider, webhook, conexión y envío (Meta Cloud API)

- **Estado**: Aceptado · FASE 6
- **Contexto**: La fase 6 del roadmap pedía la capa de integración con Meta WhatsApp Cloud API.
  Los docs previos definían la interfaz del provider sin el `accessToken` por llamada y con
  Graph v21.0; la conexión no estaba especificada como endpoint REST. El entorno local no tiene
  phpredis (la regresión se ejecuta en el contenedor Docker `whatsapp-saas-app-1`).
- **Decisión**:
  - **Token por llamada**: la interfaz `WhatsAppProviderInterface` recibe `$accessToken` en CADA
    método (sendText/sendTemplate/sendImage/sendDocument/sendInteractiveMessage/markAsRead/
    getPhoneNumberInfo/subscribeToWebhooks/unsubscribeFromWebhooks/validateWebhookSignature/
    verifyWebhook). Es el token del WABA del tenant, cifrado en `whatsapp_accounts.access_token`
    (atributo `encrypted`, `$hidden`, nunca expuesto por `WhatsAppAccountResource`). No existe
    token global de `.env` para operaciones de tenant. Graph **v26.0** (`WHATSAPP_GRAPH_VERSION`).
  - **Conexión REST** (una cuenta por tenant): `GET /api/v1/tenants/{tenant}/whatsapp`
    (`whatsapp.view`, todos los roles), `POST .../connect` (`whatsapp.manage`, owner/admin) que
    **valida SIEMPRE el token contra Meta** (`getPhoneNumberInfo`) antes de persistir y suscribe
    el WABA al webhook (best-effort), y `POST .../disconnect` que cancela la suscripción, anula el
    token y marca `disconnected` conservando el historial. Errores: 401 `WHATSAPP_AUTH_FAILED`,
    404 `WHATSAPP_PHONE_NOT_FOUND`, 409 `WHATSAPP_NOT_CONNECTED`.
  - **Envío**: `WhatsAppMessagingService` registra cada llamada al provider en
    `message_send_attempts` (provider_message_id, status, attempt/max_attempts) y audita
    `whatsapp.message_sent`/`whatsapp.message_failed`. El worker encolado `SendWhatsAppMessage`
    con backoff/CAS llega en FASE 9.
  - **Webhook**: público en `/api/webhooks/whatsapp`; GET de verificación con `hash_equals` del
    `hub.verify_token` (403 `WHATSAPP_WEBHOOK_INVALID` si no) y fallback a claves
    `hub.mode`/`hub.verify_token`/`hub.challenge` por el underscore de PHP; POST valida
    `X-Hub-Signature-256 = sha256=HMAC-SHA256(app_secret, body_crudo)` con `hash_equals` (401
    `WHATSAPP_SIGNATURE_INVALID`). Dedupe por `webhook_events.provider_event_id` UNIQUE con
    `ON CONFLICT DO NOTHING` (evento duplicado/concurrente → `duplicate=true`, 200, no reprocesa).
    El tenant se resuelve por `metadata.phone_number_id` (consulta sin scope, indexada) y los jobs
    `ProcessIncomingWhatsAppMessage` / `ProcessWhatsAppStatusUpdate` son `TenantAwareJob`. Payload
    malformado o `phone_number_id` desconocido → `webhook_events.failed` + **200** (Meta no
    reintenta en bucle). Los jobs de FASE 6 solo marcan `processed` (guard de estado+event_type+
    tenant_id); la persistencia de contactos/conversaciones/mensajes llega en FASE 7-9 (TODO).
  - **Testing**: `Http::fake` de Laravel matchea contra la URL **con query string** (p. ej.
    `?fields=...` de `getPhoneNumberInfo`), por lo que los patrones deben absorber el query
    (`graph.facebook.com/*/phone-1*`); los `Http::fake` se registran en UNA sola llamada (los
    callbacks se acumulan, no se reemplazan) y los no matcheados con `preventStrayRequests=false`
    van a la red real.
- **Consecuencias**: 15 permisos en la matriz ADR-026 (FASE 6 añade `whatsapp.view` y
  `whatsapp.manage`); 4 migraciones nuevas (whatsapp_accounts, whatsapp_phone_numbers,
  webhook_events, message_send_attempts); 42 tests WHATSAPP-1..40 (+37b/39b) → suite 177 tests /
  597 assertions; PHPStan necesita `--memory-limit=1G` (128M por defecto no basta); el trait
  `Illuminate\Foundation\Bus\Queueable`/`Illuminate\Queue\Queueable` no existe en esta versión de
  Laravel (se usa solo Dispatchable/InteractsWithQueue/SerializesModels + TenantAwareJob).

## ADR-030 · Contactos FASE 7: CRM básico con teléfono E.164 y soft delete

- **Estado**: Aceptado · FASE 7
- **Contexto**: El roadmap pide un CRM mínimo (contactos) antes del inbox (FASE 9). El usuario
  solicitó `GET /api/v1/contacts`, pero la convención establecida desde FASE 4 es
  `/api/v1/tenants/{tenant}/contacts` (enforcement del tenant activo), por lo que se adoptó esa
  forma y se flagueó en el reporte de fase.
- **Decisión**:
  - **Tablas**: `contacts` (UUID PK, `tenant_id` FK `cascadeOnDelete`, `phone` E.164 máx 40,
    `name`, `email`, `avatar_url` máx 2048, `metadata` JSON, `provider_contact_id`,
    `last_interaction_at`, soft deletes), `tags` (`tenant_id` + `name` UNIQUE) y pivot
    `contact_tag` (PK compuesta, FKs cascade) para FASE 20. `Tenant::contacts()` (hasMany).
  - **Unicidad por tenant con soft delete**: índice UNIQUE **parcial**
    `(tenant_id, phone) WHERE deleted_at IS NULL` (soportado en postgres y sqlite). Un contacto
    borrado libera el teléfono; los duplicados activos se rechazan con 409 `CONTACT_DUPLICATE`
    (guard `assertPhoneUnique` + backstop `QueryException` en `findOrCreateForPhone` ante carreras).
  - **Normalización E.164**: `ContactService::normalizePhone` (dígitos + `+`). Validación backend:
    regex `/^\+?[0-9\s().\-]+$/` + 7–15 dígitos. Espejo TS `normalizePhone` en frontend. Un
    teléfono vacío se guarda como `'Desconocido'` como nombre por defecto.
  - **Sin route-model binding implícito**: `SubstituteBindings` corre antes que el middleware
    `tenant`, así que los controllers reciben `string $contact` y el servicio resuelve con
    `withoutTenantScope()->where('tenant_id', $tenant->id)->whereKey($id)`. Contacto ajeno o
    inexistente → 404 (ADR-010/023, test CRITICO CONTACT-12). El `tenant_id` del body se ignora
    (no fillable, sin regla de validación; CONTACT-13).
  - **Permisos** en la matriz ADR-026: `contacts.view` (todos los roles, incl. agent) y
    `contacts.manage` (owner/admin) → **17 permisos**; `TenantPermission::all()` alimenta el
    seeder automáticamente.
  - **Paginación**: el index devuelve `{contacts, meta:{current_page,last_page,per_page,total}}`
    (envolver `AnonymousResourceCollection` en un array pierde el meta).
  - **`findOrCreateForPhone(Tenant, string)`**: preparado para los jobs del webhook (FASE 9).
    Consulta sin scope pero SIEMPRE filtrando `tenant_id`; setea `TenantContext` solo alrededor
    del create y lo libera en `finally` (sin contaminar el contexto del job).
  - **Auditoría**: `contact.created`, `contact.updated` (con `changed`), `contact.deleted` (con
    `phone`).
- **Consecuencias**: 3 migraciones; 19 tests backend CONTACT-1..19 + 13 tests Vitest
  (`resources/js/features/contacts/contactUtils.test.ts`, vitest@^3) → suite 196 tests / 693
  assertions; el frontend (`Settings/Contacts.vue`) usa `can('contacts.view'/'contacts.manage')`
  y `usePage().props.auth.can` (la matriz es la fuente de verdad). Tests en SQLite in-memory: el
  filtro de email usa `like` (no `ilike`, solo postgres).

## ADR-031 · Conversaciones FASE 8: inbox con estados, asignación y post de alta

- **Estado**: Aceptado · FASE 8
- **Contexto**: El roadmap pide el inbox (conversaciones) antes de la mensajería (FASE 9). El
  usuario especificó las tablas `conversations`, `conversation_participants`,
  `conversation_assignments`, un conjunto de endpoints (list/show/update + acciones
  assign/transfer/close/reopen/pause-bot/resume-bot), permisos `conversations.view` (todos) /
  `conversations.manage` (owner/admin) / `conversations.assign` (owner/admin) y auditoría de cada
  transición. La especificación original NO listaba `POST /conversations`, pero CONV-1 y el
  webhook de FASE 9 necesitan un punto de alta: se añadió y se flaguea en el reporte.
- **Decisión**:
  - **Tablas**:
    - `conversations`: UUID PK, `tenant_id` FK `cascadeOnDelete`, `contact_id` FK→`contacts`
      `cascadeOnDelete`, `status` (open/pending/resolved/archived, default open), `last_message_at`,
      `last_interaction_at`, `agent_id` FK→`users.id` (**BIGINT**, no uuid) `nullOnDelete`
      = asignación vigente, `auto_assigned`, `bot_paused`, `context` JSONB (variables
      `{{custom.x}}`), `flow_execution_id` nullable **SIN FK** (la tabla de ejecuciones llega en
      FASE 11), soft deletes. Índices `(tenant_id, status, last_message_at)`,
      `(tenant_id, contact_id)`, `(tenant_id, agent_id)`, `(tenant_id, last_interaction_at)`.
    - `conversation_participants`: `(conversation_id, user_id)` UNIQUE, `role` espejo del rol del
      tenant, `joined_at`/`left_at` (activo = `left_at IS NULL`). Participante se re-activa si
      vuelve a participar.
    - `conversation_assignments`: historial acumulativo: `agent_id`, `assigned_by`, `assigned_at`,
      `unassigned_at` (se rellena al transferir/reasignar), `reason` (manual/transfer).
      El `agent_id` de `conversations` y la fila abierta de assignments son la fuente del estado
      actual; la tabla entera es el historial auditable.
  - **FK `agent_id`/`user_id` → `users.id` (BIGINT)**: igual que `tenant_users.user_id`/`assigned_by`;
    `tenant_id`/`contact_id` siguen siendo UUID. Los participantes referencian usuarios globales
    (un agente puede pasar de tenant).
  - **Máquina de estados** en el enum `ConversationStatus::canTransitionTo`: open↔pending,
    open/pending→resolved, resolved→archived, ≠open→open (reabrir). Mismo estado = no-op (200);
    transición inválida → 409 `CONVERSATION_INVALID_STATE`. El `status` solo cambia por la máquina
    (jamás se escribe libremente vía PATCH).
  - **`context`**: merge por claves en PATCH (`array_replace`), `null` lo limpia. Lo gestionará el
    motor de flujos en FASE 10+.
  - **Sin route-model binding implícito**: igual que contactos (ADR-030): el controller recibe
    `string $conversation` y el servicio resuelve con `withoutTenantScope()->where('tenant_id',
    $tenant->id)->whereKey($id)`. Ajeno/inexistente → 404. Crear sobre un contacto de otro tenant →
    404 (`ConversationContactNotFoundException`, oculta existencia, ADR-010/023).
  - **Asignación**: `assign`/`transfer` validan que el agente destino tenga membresía ACTIVA en
    `tenant_users` del tenant (nunca se confía en el frontend). Usuario fuera del tenant → 422
    `AGENT_NOT_IN_TENANT`. `transfer` cierra la fila de assignment previa + `left_at` del
    participante anterior y crea la nueva con reason `transfer`. `auto_assigned` se reserva para
    el sistema (FASE 9+).
  - **Permisos** en la matriz ADR-026: `conversations.view` (todos), `conversations.manage`
    (owner/admin: crear, editar, close/reopen, pause/resume bot) y `conversations.assign`
    (owner/admin) → **20 permisos**; el seeder se alimenta de `TenantPermission::all()`.
  - **`findOrCreateActiveForContact(Tenant, string)`**: para los jobs del webhook (FASE 9).
    Reutiliza la conversación activa del contacto (no resucitada si el contacto está soft-deleted)
    o crea una; consulta sin scope filtrando SIEMPRE `tenant_id`; setea/libera `TenantContext` en
    `finally`.
  - **Auditoría**: `conversation.created/updated/assigned/transferred/closed/reopened/
    bot_paused/bot_resumed`.
- **Consecuencias**: 3 migraciones; 24 tests backend CONV-1..24 (aislamiento CRITICO CONV-18/19
  A/B, tampering CONV-20, matriz CONV-21, agent solo lectura CONV-22) + 7 tests Vitest
  (`resources/js/features/conversations/conversationUtils.test.ts`) → suite **220 tests / 821
  assertions**; frontend `Conversations/Index.vue` (página de bandeja sin chat UI, el chat llega
  en FASE 10) con `can('conversations.view'/'manage'/'assign')`; el `ConversationResource` expone
  `agent` siempre (null si no cargado) y `participants`/`assignments` como arrays
  (`->values()->all()`) por covarianza de PHPStan nivel 6.

## ADR-032 · Mensajes FASE 9: persistencia inbound/outbound, status y outbox

- **Estado**: Aceptado · FASE 9
- **Contexto**: El webhook (FASE 6) recibía y encolaba eventos pero los jobs solo los marcaban
  `processed` (TODO marcado). FASE 9 debía persistir los mensajes: inbound (webhook →
  contact/conversation/message con idempotencia), status updates de Meta (sent/delivered/read/
  failed) y envío saliente asíncrono con reintentos reales. Se exige garantía de entrega (no perder
  eventos si el proceso cae entre el insert y el encolado).
- **Decisión**:
  - **Tabla `messages`**: UUID PK, `tenant_id` FK `cascadeOnDelete`, `conversation_id` FK
    `cascadeOnDelete` (**el contacto se resuelve por la conversación; no se duplica en la tabla**),
    `provider_message_id` (id de Meta), `direction`/`type`/`status`, `body`, columnas propias
    `media_url`/`media_mime`/`media_size` y `metadata` JSONB (`from`, `provider_timestamp`, payload
    del tipo), una **columna temporal por estado** `sent_at`/`delivered_at`/`read_at`/`failed_at`
    (ADR-032; `MessageStatus::columnFor()`), timestamps. Sin soft delete (los mensajes son
    inmutables; el borrado UI llega, si acaso, en fases posteriores). Índices
    `(tenant_id, conversation_id, created_at)` y `(conversation_id)`.
  - **Idempotencia inbound**: UNIQUE `(tenant_id, provider_message_id)` (composite, sin cláusula
    parcial: los NULL no colisionan → los outbound sin id de Meta son válidos) + backstop
    `QueryException` → re-consulta (carrera entre workers). Tipos de Meta no soportados →
    `UnsupportedMessageTypeException` → evento `failed` (permanente), webhook sigue 200.
  - **Status nunca crea**: un `statuses[]` update localiza por `provider_message_id` y rellena
    `status` + `columnFor()`. `failed` además pasa la conversación a `pending`
    (`markConversationPending`). El detalle de error del envío vive en `message_send_attempts`
    (FASE 6), no en `messages`.
  - **Dedupe de status en la plataforma**: Meta reusa `statuses[].id` (= id del mensaje) en
    `delivered`/`read` del mismo mensaje → un UNIQUE simple en `provider_event_id` colisionaba.
    Clave compuesta `id|status|timestamp` (formato `%s|%s|%s`).
  - **Outbound asíncrono**: `SendWhatsAppMessage` (ShouldBeUnique por `message_id`, `uniqueFor`
    300, timeout 60, `tries()` = `WHATSAPP_MAX_ATTEMPTS`, backoff `[10,30,60]`). **CAS**
    `pending → sending` con update atómico (job duplicado/concurrente = no-op; impide doble
    envío). Re-valida en el worker cuenta conectada + número default conectado + tipo text.
    Éxito → `sent` + `provider_message_id` + audita `message.sent`. Error retryable y quedan
    intentos → rethrow (reintento con backoff); si no → `failed` + `failed_at` + audita
    `message.failed`. El `to` sale del contacto de la conversación (E.164).
  - **Outbox sweeper**: `whatsapp:reprocess-webhook-events` (every 1 min, `withoutOverlapping`)
    re-encola `webhook_events` con `status='received'` y `created_at` < ahora − 5 min (limit 100)
    vía `WhatsAppWebhookService::reprocessEvent()` → `enqueued` + dispatch (o `failed` si el evento
    es desconocido). `created_at`/`updated_at` no son `$fillable` de `WebhookEvent` → los tests
    usan `DB::table()->insert()`.
  - **`MessageService`**: un service de aplicación por flujo (inbound/status/outbound) con
    `TenantContext` seteado SOLO alrededor de los creates y liberado en `finally`; toda resolución
    filtra SIEMPRE `tenant_id` (`withoutTenantScope()->where(...)`). El audit de FASE 9 pasa
    `tenantId:` explícito (el contexto se limpia antes de auditar).
  - **Jobs**: `ProcessIncomingWhatsAppMessage`/`ProcessWhatsAppStatusUpdate` delegan en
    `MessageService` (nuevo `tries=3`, backoff `[5,15,60]`) y hacen `markProcessed()` real.
- **Consecuencias**: 1 migración (creada, revertida y re-aplicada en postgres); 28 tests backend
  MSG-1..9 / STAT-1..8 / OUT-1..7 / OUTBOX-1..4 → suite **248 tests / 934 assertions**; helpers
  compartidos movidos a `tests/Support/helpers.php`; PHPStan exige `@param array<string, mixed>`
  en los extractores y `public int` en `tries`/`timeout` de jobs. Los REST
  `conversations/{id}/messages` quedan para FASE 10 (bandeja de entrada).

## Pendientes de decisión

- Proveedor de email en producción (mailpit en dev; SES/Resend/SMTP en prod) → FASE 22.
- Pasarela de pagos (Stripe propuesto) → FASE 24.
- Estructura de OpenAPI (spec manual vs. paquetes) → FASE 35.
