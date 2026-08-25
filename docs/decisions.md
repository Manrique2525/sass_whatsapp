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

## ADR-033 · Inbox FASE 10: mensajes REST, permiso `messages.send` y tiempo real Reverb por conversación

- **Estado**: Aceptado → FASE 10
- **Contexto**: La bandeja (FASE 1) era una tabla con CRUD; el historial de mensajes persistía
  (FASE 9) pero no había lectura/escritura desde el frontend ni notificación en vivo. Se necesita
  un inbox tipo chat con mensajes por conversación, envío desde el agente y actualizaciones en
  tiempo real aisladas por tenant.
- **Decisión**:
  - **REST**: `GET|POST /api/v1/tenants/{tenant}/conversations/{conversation}/messages` bajo el
    grupo `middleware('tenant')`. `index` página DESC (default 30, máx 100) con `MessageResource`
    completo y `{messages, meta}`; `store` valida `body` (required, string, max 4096) y responde
    201 `{message, created_message}`. Permisos: `conversations.view` (index) y **nuevo**
    `messages.send` (store), concedido a owner/admin/agent (todo rol de tenant que atiende inbox).
    Errores estándar: 404 no-miembro/tenant ajeno, 403 PERMISSION_DENIED, 409 tenant inactivo.
  - **Realtime**: eventos `MessageCreated`, `MessageStatusUpdated` (con `previous_status`) y
    `ConversationUpdated`, todos `ShouldBroadcast` con `broadcastAs()`/`broadcastWith()` usando los
    `*Resource` como payload. Se despachan vía `Illuminate\Contracts\Events\Dispatcher` inyectado
    (nada de facades en services). Canal **privado por conversación**
    `tenant.{tenantId}.conversations.{conversationId}` (sin canales globales; ReverbChannelAuthTest
    ya validaba el patrón). Almacenado para status `sent`/`failed` (SendWhatsAppMessage) y para
    `touchConversation`/`markConversationPending` (inbound con conversación resuelta).
  - **Frontend**: Laravel Echo + Reverb (lazy `initEcho()`, guard `VITE_REVERB_APP_KEY`), suscripción
    dinámica por conversación abierta vía composable `useConversationChannel`; polling de la lista
    cada 30 s como complemento (los eventos solo llegan de la conversación abierta). El scroll es
    "inteligente": auto-bottom solo si el usuario está al final; si llega un mensaje con el scroll
    en histórico se muestra el pill "Nuevos mensajes". `ConversationResource` incluye `last_message`
    (HasOne `latestOfMany`) para el preview del listado.
  - **Pruebas**: `MessageApiTest` (MSG-API-1..16) cubre paginación, aislamiento A/B (404), IDOR,
    matriz de permisos, eventos vía `Event::fake`, `last_message` y envío + timestamps. Laravel
    12.66 no expone `Broadcast::fake()`: los tests de broadcasts usan `Event::fake([...])` +
    `assertDispatched` con callback sobre la instancia.
- **Consecuencias**: 1 permiso nuevo, 3 eventos, 1 controller + 2 requests + 1 resource, rutas
  nuevas y `last_message` eager-load en el índice de conversaciones. Suite backend **264 tests /
  996 assertions**; frontend 48 tests Vitest (jsdom + @vue/test-utils). El inbox reemplaza la tabla
  de FASE 1 en `Pages/Conversations/Index.vue` (mantiene crear conversación y acciones de FASE 1).

## ADR-034 · Motor de flujos FASE 11: modelo de datos (chatbots → flows → nodos → ejecuciones)

- **Estado**: Aceptado → FASE 11
- **Contexto**: Automatizar atención en WhatsApp exige un motor genérico interpretado desde
  datos (no código por negocio). La FASE 0 (docs/chatbot-engine.md) definía el diseño; FASE 11
  lo concreta con 7 tablas nuevas y una barrera de concurrencia a nivel de DB.
- **Decisión**:
  - **`chatbots`**: negocio/número dentro de un tenant (soft delete). Un tenant puede tener N
    chatbots; cada chatbot agrupa flujos.
  - **`flows`**: la fila ES la versión (ADR-036): `status` (draft/published/inactive), `config`
    JSON. **No existe `flow_versions`**: el flujo publicado se ejecuta, los demás no disparan.
  - **`flow_nodes`**: `type` + `config` JSON + `position_x/y` (editor) + `is_start`. Máximo un
    nodo de inicio por flujo (validado por `FlowValidator`). El nodo de inicio es un nodo REAL
    (p. ej. `message`) con `is_start=true`; no existe un tipo `start`.
  - **`flow_connections`**: arista dirigida `source_node_id → target_node_id` con `label`
    (resultado de rama para `condition`). FKs con cascade desde `flow_nodes`.
  - **`triggers`**: `type` (keyword/new_message/start en FASE 11; tag/schedule/webhook
    registrados para FASE 14), `keyword`, `config`, `priority`, `active`.
  - **`flow_executions`**: una ejecución activa por conversación. `current_node_id` (null al
    terminar), `variables` JSON (respuestas de question y `{{custom.*}}`), `attempts`,
    `last_inbound_message_id` (barrera de idempotencia). **UNIQUE parcial**
    `(tenant_id, conversation_id) WHERE status IN ('running','waiting')` vía SQL nativo
    (Laravel 12 no soporta `where()` fluido en índices; precedente FASE 7).
  - **`flow_execution_logs`**: traza por paso (`event`, `payload`, `sequence`); auditoría y debug.
  - Todas las tablas tienen `tenant_id` + trait `BelongsToTenant` (multi-tenancy desde el inicio,
    ADR-003).
- **Consecuencias**: 7 migraciones (chatbots, flows, flow_nodes, flow_connections, triggers,
  flow_executions, flow_execution_logs) + FK `conversations.flow_execution_id` (nullable, sin FK
  hasta FASE 11 — ahora sí se añade la FK real en `000700`). El UNIQUE parcial es la barrera de
  concurrency a nivel de base de datos; el motor además usa lock Redis + CAS de avance (ADR-037).

## ADR-035 · Motor de flujos FASE 11: nodos como ejecutores + validación previa a publicación

- **Estado**: Aceptado → FASE 11
- **Contexto**: El motor debe ejecutar 10 tipos de nodo con comportamientos distintos (enviar,
  ramificar, esperar, pausar, terminar). Mezclar todo en un switch gigante rompe la testabilidad.
- **Decisión**:
  - Un **`NodeExecutorInterface` por tipo de nodo**, implementado en
    `app/Application/Flows/Services/Executors/`: `Message`, `Buttons`, `Question`, `Condition`,
    `Delay`, `Tag`, `Webhook`, `Human`, `End`. El `NodeExecutorRegistry` los resuelve por
    `FlowNodeType`. **No existe ejecutor para `ai`** (FASE 16): `FlowValidator` lo rechaza con
    mensaje explícito; prohibido un ejecutor vacío/falso (regla AGENTS §3).
  - Cada ejecutor recibe un `NodeExecutionContext` (tenant, nodo, ejecución, conexión, variables)
    y devuelve un `NodeExecutionResult` (estado siguiente, variables mutadas, mensajes a enviar,
    rama elegida, espera/continuación). Los mensajes outbound se despachan vía el patrón existente
    (`MessageService::createOutbound` + job), bajo el mismo lock.
  - **`FlowValidator`** valida el grafo completo ANTES de publicar: un solo `is_start`, grafo
    conexo alcanzable, al menos un terminal `end` o `human` alcanzable, config de cada nodo según
    su tipo, sin auto-lazos, límites de tamaño. Publicar un flujo inválido →
    `FlowInvalidException` (422 `FLOW_INVALID` con la lista de errores). La alternativa terminal
    `human` fue formalizada por ADR-051, que reemplaza el requisito original de `end`.
  - Nodos que quedan en espera (`waiting`) tras ejecutarse: `question`, `buttons` (y `ai` cuando
    exista). `delay` no queda en waiting: programa `ContinueFlowExecution`. ADR-051 reemplaza la
    clasificación original de `human`: finaliza en estado terminal `handed_off`.
  - Variables resueltas por `VariableResolver` (`{{contact.*}}`, `{{custom.*}}`,
    `{{conversation.*}}`, `{{node.*}}`); `ConditionEvaluator` evalúa las reglas de `condition`;
    `WebhookUrlGuard` bloquea SSRF (IPs privadas/reservadas, precedente FASE 9).
- **Consecuencias**: 9 ejecutores + registry + validator + resolver/evaluator/guard. Cada nodo es
  testeable en aislamiento (unit) y el motor en integración. El coste es un mapeo explícito
  type→ejecutor; beneficios: añadir nodos nuevos = clase + registro + validación.

## ADR-036 · Motor de flujos FASE 11: borrador, publicación y estados (sin versionado)

- **Estado**: Aceptado → FASE 11
- **Contexto**: El flujo pasa por edición (borrador) y entra en producción (publicado). La
  especificación FASE 0 contemplaba `flow_versions`; al no existir el editor visual todavía
  (FASE 12), un versionado de 1-N complica sin aportar valor real en esta fase.
- **Decisión**:
  - **No hay `flow_versions`**: la fila de `flows` es la única versión. Solo el flujo con
    `status = published` se ejecuta (draft/inactive no disparan). Los triggers apuntan al flujo,
    no a una versión.
  - **Borrador atómico**: `PUT /flows/{flow}/draft` reemplaza en una transacción `flow_nodes` +
    `flow_connections` (`ReplaceDraftRequest` valida la forma; `FlowValidator` valida el grafo).
    Un reemplazo fallido no deja estado a medias.
  - **Máquina de estados** (`FlowStatus`): `draft → published` (publicar), `draft → inactive`
    (descartar), `published → inactive` (desactivar), `published → draft` (volver a editar),
    `inactive → draft|published` (repúblicar). PATCH con el mismo estado = no-op (200).
    Transiciones inválidas → `FlowInvalidStateException` (409 `FLOW_INVALID_STATE`).
  - **Protecciones**: publicar valida el grafo (ADR-035); un flujo publicado NO se edita ni se
    elimina (409 `FLOW_PUBLISHED`); un chatbot con flujos publicados no se elimina
    (409 `CHATBOT_HAS_PUBLISHED_FLOWS`); no se puede publicar dos flujos con el mismo trigger
    genérico (409 `FLOW_ALREADY_PUBLISHED`). Cada mutación se audita (AuditLog).
  - `GET /flows/{flow}/validate` expone `{valid, errors}` sin mutar nada.
- **Consecuencias**: modelo simple y auditable; el versionado queda documentado como deuda
  si el editor (FASE 12) necesita historial. Publicar es transacción: `validate → persist
  estado → audit` bajo lock del flujo.

## ADR-037 · Motor de flujos FASE 11: ejecución (lock Redis, idempotencia, reanudación)

- **Estado**: Aceptado → FASE 11
- **Contexto**: Cada conversación es un autómata con una sola ejecución activa. Los webhooks de
  Meta reenvían eventos duplicados y los delays encolan trabajo diferido: el motor debe ser
  determinista, reanudable e idempotente, sin ejecuciones paralelas sobre la misma conversación.
- **Decisión**:
  - **Punto de entrada único**: `FlowEngine::handleMessage` (mensaje entrante) y
    `FlowEngine::continueExecution` (continuación programada), ambos bajo el lock Redis
    `lock:tenant:{id}:flow:{conversation_id}` (`FlowExecutionService::conversationLock`, patrón
    de bloqueo con heartbeat de FASE 9). Quien crea el lock lo limpia en `finally`.
  - **Idempotencia**: `last_inbound_message_id` en la ejecución es la barrera del motor — un
    mismo inbound reprocesado (dedupe de plataforma + re-entrega) jamás avanza la ejecución dos
    veces. El UNIQUE parcial (ADR-034) garantiza una sola ejecución activa; `start()` crea la
    ejecución bajo el lock y reintenta el `QueryException` del UNIQUE (precedente ADR-032).
  - **Ciclo**: avanza nodo a nodo con guard `MAX_STEPS` (anti-loop) y timeout total; cada paso se
    persiste y se traza en `flow_execution_logs`. Nodos waiting (`question`/`buttons`) dejan la
    ejecución en `waiting` y guardan el `current_node_id`; el siguiente inbound del cliente
    reanuda ese nodo (valida la opción, asigna variable) y continúa.
  - **Delay**: el ejecutor `DelayNodeExecutor` programa `ContinueFlowExecution` con
    `->forTenant()->mode('delay')->delay(seconds)`; al ejecutarse re-adquiere el lock y avanza.
  - **Webhook**: fallos transitorios reintentan con backoff (máx 3, backoff [5,15,30] s);
    fallos permanentes marcan la ejecución `failed`.
  - **Pause/resume/cancel** (`FlowExecutionService`): solo sobre ejecuciones activas; terminales
    → 409 `EXECUTION_INVALID_STATE`. `cancel` marca `failed` y desenlaza. `handed_off` (nodo
    human) pausa el bot (`conversations.bot_paused=true`) hasta que un agente reanude.
  - El motor **no** crea ni limpia `TenantContext` (lo provee el job `TenantAwareJob`); los
    servicios internos que necesitan contexto usan `TenantContext::withId()` (fix de contexto
    anidado, no limpian un contexto ya activo).
- **Consecuencias**: una sola ejecución activa por conversación garantizada por UNIQUE parcial +
  lock Redis + barrera de idempotencia. Traza completa por paso en logs. `continueExecution`
  comparte el mismo pipeline que `handleMessage` (misma lógica de reanudación).

## ADR-038 · Motor de flujos FASE 11: triggers de mensaje (keyword / new_message / start)

- **Estado**: Aceptado → FASE 11
- **Contexto**: Un inbound sin ejecución activa debe decidir si dispara un flujo y cuál. Los
  tipos `tag`/`schedule`/`webhook` requieren componentes de fases posteriores (etiquetas FASE 13,
  scheduler/webhooks FASE 14).
- **Decisión**:
  - **FASE 11 implementa solo disparos por mensaje entrante**: `keyword` (texto que contiene la
    palabra clave, normalizada a minúsculas y sin espacios extra), `new_message` (cualquier
    mensaje) y `start` (solo el primer mensaje de la conversación). `tag`/`schedule`/`webhook`
    quedan registrados en el enum sin matcher (no se rompen datos, no se mienten: un trigger de
    esos tipos no dispara).
  - **Precedencia** (`TriggerMatcher`): keyword específico antes que genérico; entre
    `new_message` y `start`, `start` solo dispara en el primer inbound de la conversación.
    Prioridad `priority` desempata entre triggers del mismo tipo.
  - **Condiciones**: el trigger debe estar `active`, el flujo debe estar `published`, y el
    chatbot debe estar operativo. El matcher opera dentro del lock de conversación y del
    `TenantContext` del job.
  - Se guarda el `conversation_id` en la ejecución para que la reanudación sea directa; el
    matcher no se re-evalúa mientras exista ejecución activa.
- **Consecuencias**: `TriggerMatcher` + `FlowTriggerType::isMessageTrigger()` (antes
  `isImplementedInPhaseEleven()`; renombrado en FASE 14 UNIDAD 1). El matcher es puro y testeable
  unit. FASE 14 añadirá matchers nuevos sin tocar el pipeline de ejecución (solo el punto de
  entrada correspondiente: tag assignment, scheduler, webhook).

## ADR-039 · Motor de flujos FASE 11: permisos Flows.*, API REST y frontend read-only

- **Estado**: Aceptado → FASE 11
- **Contexto**: La plataforma expone los flujos por API REST y por páginas Inertia. Toda
  autorización debe vivir en el backend (AGENTS §5), con la matriz de roles de la FASE 4 y el
  aislamiento A/B de la FASE 3.
- **Decisión**:
  - **Permisos nuevos** en `TenantPermission`: `flows.view` (todos los roles de tenant:
    owner/admin/agent) y `flows.manage` (solo owner/admin). El seeder FASE 4 sincroniza los
    permisos; la matriz se aplica por policy-style checks dentro de los services (404 no-miembro,
    403 `PERMISSION_DENIED`, 409 tenant inactivo, patrón de FASE 7-10).
  - **API REST** (`routes/api.php`, grupo `middleware('tenant')`, params de ruta como `string`,
    sin route-model binding implícito — patrón ADR-030/033):
    - `chatbots`: GET index (búsqueda + paginación) / POST store (flows.manage) / GET show /
      PATCH update / DELETE (409 si tiene flujos publicados).
    - `chatbots/{chatbot}/flows`: GET index (filtro status) / POST store.
    - `flows/{flow}`: GET show (nodos+conexiones+triggers eager) / PATCH update / DELETE.
    - `flows/{flow}/draft`: PUT replaceDraft (transacción, validación de forma + grafo).
    - `flows/{flow}/validate`: GET → `{valid, errors}`.
    - `flows/{flow}/publish|deactivate`: POST.
    - `flows/{flow}/triggers`: GET index / POST store; `triggers/{trigger}` PATCH/DELETE (solo en
      flujos no publicados).
    - `flow-executions`: GET index (filtros status/flow/chatbot + paginación) / GET show /
      POST `{execution}/pause|resume|cancel` (flows.manage; 409 `EXECUTION_INVALID_STATE` sobre
      terminales).
  - **Errores estándar**: `{message, code, errors}`; códigos: 404 `NOT_FOUND` (recurso o
    tenant ajeno), 403 `PERMISSION_DENIED`, 409 `TENANT_NOT_ACTIVE` / `FLOW_PUBLISHED` /
    `FLOW_ALREADY_PUBLISHED` / `FLOW_INVALID_STATE` / `EXECUTION_INVALID_STATE` /
    `CHATBOT_HAS_PUBLISHED_FLOWS`, 422 `VALIDATION_ERROR` / `FLOW_INVALID`.
  - **Frontend read-only**: `Pages/Settings/Flows.vue` (link "Flujos" en AppLayout) con
    chatbots → flujos → detalle (nodos, conexiones, triggers) y estado de cada flujo; carga vía
    API donde la autorización es real. Types + utils en `features/flows/` (`flowTypes.ts`,
    `flowUtils.ts` con labels espejo del backend y builders de query) + Vitest.
  - El endpoint `webhook` de Meta (FASE 6) encola el inbound; el job (FASE 9) llama a
    `FlowEngine::handleMessage` con el tenant ya en contexto.
- **Consecuencias**: 4 controllers + 11 form requests + 6 resources + 2 permisos nuevos + 1
  página Inertia + 1 ruta web. Suite de aislamiento A/B (tenant B jamás ve/edita recursos de A,
  404) y matriz de permisos por API en FlowApiTest. No existe frontend de edición (FASE 12).

## ADR-040 · Flow Builder FASE 12: arquitectura del editor visual (Vue Flow)

- **Estado**: Aceptado → FASE 12
- **Contexto**: La FASE 11 dejó la API completa de borrado/persistencia de grafos pero sin
  frontend de edición. El editor debe ser visual (canvas), en tiempo real y seguro en
  multi-tenancy, sin código falso (AGENTS §3).
- **Decisión**:
  - **Librería**: `@vue-flow/core` v1.48 + `@vue-flow/background`, `@vue-flow/minimap`,
    `@vue-flow/controls`, `@vue-flow/vue-flow` marker arrows. Node types propios (10 SFCs:
    message, buttons, question, condition, delay, tag, webhook, human, end, ai) registrados en
    `nodes/index.ts`; edge propio `FlowEdge.vue`.
  - **Estado en un composable único** `useFlowEditor` (`features/flows/useFlowEditor.ts`) que
    expone `FlowEditorController = ReturnType<typeof useFlowEditor>`: refs `nodes`, `edges`,
    `selected`, `dirty`, `saveState`, `publishState`, `flowStatus`, `canUndo`, `canRedo`,
    `empty`, `validationIssues`, `centerRequest`, `connectError`, `error`, `loadState`, `flow`,
    `flowName`, `flowDescription`; booleans planos `canManage` y `readOnly`. El canvas
    (`FlowEditor.vue`) es *one-way* (recibe `vfNodes`/`vfEdges` computados) y las mutaciones
    pasan por los métodos del composable → cada cambio se empuja al historial.
  - **Historial** `useEditorHistory` (máx 50 snapshots clonados, rama redo descartada al push).
    **Atajos** `useKeyboardShortcuts` (Ctrl+S guardar, Ctrl+Z/Ctrl+Shift+Z deshacer/rehacer) vía
    `MaybeRefOrGetter` para respetar el estado read-only.
  - **Contrato de grafo** `flowAdapter.ts`: traduce API ↔ editor, genera edge ids deterministas
    `e-{source}-{target}-{label}`, redondea posiciones a enteros, y `graphSignature` (huella
    normalizada por orden) para detectar cambios y alimentar el lock optimista.
- **Consecuencias**: El editor no muta el DOM/vue-flow state por fuera; `readOnly` (agent) y
  estado `published` congela todas las mutaciones en el composable, no en el UI.

## ADR-041 · Flow Builder FASE 12: lock optimista del borrador (`base_updated_at`)

- **Estado**: Aceptado → FASE 12
- **Contexto**: Dos agentes pueden editar el mismo borrador. El backend de FASE 11 persistía el
  grafo por último-que-escribe. Para un editor visual esto causa pérdida de trabajo.
- **Decisión**:
  - `PUT /flows/{flow}/draft` acepta `base_updated_at` (opcional, ISO) = `flows.updated_at` de la
    versión que el cliente cargó/guardó por última vez. Si difiere de la versión actual → **409**
    `FLOW_CONFLICT` sin escribir. El cliente guarda `base_updated_at` con su estado y no usa
    `localStorage`: el conflicto se detecta siempre contra el servidor.
  - Si no se envía `base_updated_at` (creación, sobrescritura explícita) no hay chek del lock.
  - **Resolución en el editor**: al recibir 409 el composable expone `conflict` y la página
    muestra `ConflictDialog` con tres opciones — (1) recargar la versión del servidor,
    (2) seguir editando, (3) **sobrescribir explícitamente** (reenvía sin `base_updated_at`).
    "Sobrescribir" es una acción explícita del usuario, nunca automática.
  - Migración `2026_08_18_000000_increase_flows_timestamps_precision` → `timestamp(6)` en
    `flows.created_at/updated_at` para que `updated_at` cambie en escrituras concurrentes rápidas
    (Postgres por defecto era `timestamp(0)`).
- **Consecuencias**: Concurrencia real entre editores cubierta por FLOW-38/43 (backend) y por
  `useFlowEditor.test.ts` (frontend). Sin `ETag`; `updated_at` del tenant row ya está disponible
  en el recurso.

## ADR-042 · Flow Builder FASE 12: contrato del draft y del grafo del editor

- **Estado**: Aceptado → FASE 12
- **Contexto**: El frontend envía grafos completos. Hay que fijar reglas de ids, posiciones,
  ramas de condición y qué campos viajan, sin confiar en el frontend (AGENTS §5).
- **Decisión**:
  - **Ids**: el editor genera `crypto.randomUUID()` por nodo (nunca trusts ids del cliente en
    backend; `ReplaceDraftRequest` los valida como uuid). Edge ids `e-{source}-{target}-{label}`
    deterministas → dedupe natural al dibujar.
  - **Posiciones**: enteras (`Math.round`) en `position_x/y` para evitar saltos de re-render y
    firmas inestables.
  - **Ramas de condición**: arista con `sourceHandle` = `true`/`false` y `label` = `true`/`false`.
    Constantes `CONDITION_TRUE`/`CONDITION_FALSE` compartidas. `canCreateConnection` exige la
    rama y prohibe una segunda conexión en la misma rama.
  - **Payload**: `graphToDraft` envía `{nodes: FlowNode[], connections: FlowConnection[]}` (sin
    `tenant_id` — lo fuerza `BelongsToTenant`, FLOW-40) y `base_updated_at` solo si hay versión
    conocida (ADR-041). Los secrets del nodo `webhook` viven solo en backend (FLOW-29): el
    editor edita únicamente `method`/`url`.
- **Consecuencias**: Roundtrip probado en `flowAdapter.test.ts` (traducción sin pérdida,
  posiciones redondeadas, `base_updated_at` opcional, ramas conservadas).

## ADR-043 · Flow Builder FASE 12: validación local (espejo del `FlowValidator`)

- **Estado**: Aceptado → FASE 12
- **Contexto**: El backend valida en publish (FLOW_INVALID) pero el editor necesita feedback
  inmediato y no duplicar lógica de negocio desconectada.
- **Decisión**:
  - `flowValidation.ts` implementa **dos capas**:
    1. `configIssuesForNode(type, config)` — por tipo (texto de message, 1-3 botones con ids
       únicos, prompt+field de question, reglas/operadores de condition usando
       `CONDITION_OPERATORS` con `needsValue`, delay 1..3600 entero, 1-10 tags no vacías, URL
       http(s)+método de webhook).
    2. `localGraphIssues(nodes, edges)` — espejo del `FlowValidator` backend: exactamente un
       `is_start`, sin self-loops ni entradas al inicio, terminales (`end`/`human`) sin salida,
       `condition` con ramas true y false, nodos no-terminales con salida, `end` alcanzable
       (END_MISSING como warning).
  - La **fuente de verdad** sigue siendo el backend (publish re-valida). `mapBackendErrors`
    traduce los mensajes `FLOW_INVALID` a issues con `nodeId` por nombre de nodo para el
    `ValidationPanel` ("Ver nodo" → `focusNode`).
- **Consecuencias**: El panel de validación marca nodos con badge (flow-local) y el publish
  conflictivo deja los issues del servidor. Reglas duplicadas solo en esta capa de UX; el
  servidor nunca confía en ellas (ADR-042).

## ADR-044 · Flow Builder FASE 12: selección en Vue Flow v1.48, modo lectura y página Inertia

- **Estado**: Aceptado → FASE 12
- **Contexto**: `@vue-flow/core` v1.48 no emite el evento `selection-change` (eliminado de la
  API pública) y sus tipos `Node<Data>`/`Edge<Data>` difieren del grafo interno del editor.
- **Decisión**:
  - **Selección**: la selección llega como cambios `select` dentro de `nodes-change`/
    `edges-change`. El composable implementa `syncSelection()` que lee los flags `selected` de
    cada nodo/arista y los proyecta a `selected` (nullable) para los paneles.
  - **Tipos desacoplados**: `FlowEditorNode`/`FlowEditorEdge` son interfaces propias del editor
    (requieren `data`; opcionales `selected`/`sourceHandle`/`markerEnd`). No se heredan de
    `GraphNode`/`GraphEdge`; los cambios de vue-flow se aplican con casts controlados en
    `applyNodeChanges`/`applyEdgeChanges`. `markerEnd` se fija a `MarkerType.ArrowClosed` en
    `flowAdapter` y `onConnect`.
  - **Página**: `Pages/Flows/Editor.vue` (wrapper Inertia fino, props `chatbotId` + `flowId`)
    monta `FlowEditor.vue` + `FlowToolbar` + `NodePalette` + paneles + `ConflictDialog`.
    Ruta web `settings/flows/{chatbot}/{flow}` (name `settings.flows.editor`, middleware
    `['verified','tenant']`) servida por `FlowEditorSettingsController`. Enlace "Abrir editor"
    desde `Pages/Settings/Flows.vue`. Guard de navegación (`router.on('before')`) + `beforeunload`
    si hay cambios sin guardar.
  - **Solo lectura**: `readOnly` deriva de `!canManage` (permiso `flows.view` de agent) o de
    estado `published`; `FlowToolbar` oculta Guardar/Publicar y muestra Desactivar cuando aplica.
    El composable ignora toda mutación en read-only (probado en `useFlowEditor.test.ts`).
- **Consecuencias**: `vue-tsc` sin errores con el contrato de tipos propio; la selección,
  undo/redo y el modo lectura quedan cubiertos por Vitest.

## ADR-045 · FASE 13 UNIDAD 5: endurecimiento de validación, variables y webhooks

- **Estado**: Aceptado → FASE 13
- **Contexto**: UNIDAD 5 endurecía el catálogo de variables y el Flow Builder. Dos ambigüedades
  reales: (1) qué significa `{{contact.metadata.*}}` (¿tokens de ruta o campos planos del
  metadata?) y (2) cuánta seguridad exige la config del nodo webhook (SSRF, secrets en logs).
- **Decisión**:
  - **`contact.metadata.*` = alias plano**: `{{contact.<campo>}}` resuelve a
    `contact.metadata[<campo>]` (las claves del metadata se exponen como campos top-level de
    `contact`). La **traversión** `{{contact.metadata.<clave>}}` sigue bloqueada (UNIDAD 2). Es la
    interpretación conservadora: no cambia la semántica existente y no añade capacidad nueva de
    acceso a datos. Documentado con test en `VariableResolverTest`.
  - **`question` tipo + default**: `config.type` debe ser un `VariableType` y `config.default`
    (no-null) debe poder convertirse al tipo declarado (o `string` si no se declara). El editor
    ahora conserva y edita `type`/`default` al guardar. Backend es la autoridad (nunca confiar en
    el frontend).
  - **Webhook**: `WebhookUrlGuard` valida esquema `http(s)` y, además, `sanitizeForLog()` limpia
    userinfo/query/fragment para **logs y auditoría** (nunca aparecen `Authorization`, `api_key`
    ni query con secretos). El validador rechaza `{{` dentro del host (variables jamás bypasean
    SSRF) y hosts con credenciales. Los `headers`/`payload` nunca salen por API (solo
    `method`/`url`, ver ADR-042/FLOW-29).
  - **Referencias y condition**: escaneo de referencias con error duro solo para segmentos
    peligrosos (`__proto__`, `constructor`, `prototype`, segmentos vacíos); namespaces
    desconocidos/`node.*`/multi-segmento siguen siendo warnings (contrato previo intacto). Campo
    de condition limitado a namespaces `contact/business/conversation/custom` con segmentos
    seguros.
  - **Catálogo frontend**: `useVariableCatalog` agrupa con `Map`/arrays (nunca objetos planos
    derivados de claves de usuario) → sin prototype pollution.
  - Límites: textos ≤ 4096, campo de condition ≤ 128, URL de webhook ≤ 2048.
- **Consecuencias**: `flow_execution_logs`/audit nunca contienen secrets de webhook; el editor
  mantiene `type`/`default`; VAR-24/25/26 (concurrencia) y VAR-29/30 (aislamiento tenant) se
  prueban explícitamente. Suite backend 425 tests / 2001 assertions; frontend 147 tests.

## ADR-046 · FASE 13: catálogo de variables y contrato runtime de defaults (UNIDAD 3/4 y UNIDAD 6)

- **Estado**: Aceptado → FASE 13
- **Contexto**: (1) La UNIDAD 3/4 introdujo el catálogo derivado de variables
  (`VariableCatalogService`), al que `docs/roadmap.md` y `docs/api.md` ya referenciaban como
  "ADR-046" pero el ADR nunca se escribió (referencia colgante). (2) La UNIDAD 6 debía completar
  el **contrato runtime** de variables: `question.config.default` se validaba al publicar
  (ADR-045), se exponía en el catálogo y lo conservaba el editor, pero el motor **nunca lo
  aplicaba** en runtime: ante una respuesta vacía se persistía `''`/raw en vez del default.
- **Decisión**:
  - **Catálogo (UNIDAD 3/4)**: `GET /api/v1/tenants/{tenant}/flows/{flow}/variables` expone
    definiciones derivadas (nunca valores de ejecución) construidas íntegramente server-side;
    `custom.*` se deriva de los nodos `question` (field/type/default), `business.*` SOLO vía
    `BusinessProfile::PUBLIC_FIELDS`; el Resource expone únicamente `VariableDefinition` (nunca
    `tenant_id`, config de nodos, headers/body de webhook ni secretos).
  - **Default en runtime (UNIDAD 6)**: en `FlowEngine::resumeAfterAnswer` (rama `question`), si
    la respuesta recortada es vacía (`''` → "sin respuesta") y el nodo declara un
    `question.config.default` usable (string no vacío tras trim), el motor persiste el default
    **coerceado al tipo declarado** (misma coerción determinista de `VariableType`, con fallback a
    la cadena en bruto si los datos publicados estuvieran corruptos). Sin default (ausente, `null`
    o `''`) el comportamiento previo queda intacto. Una respuesta **no vacía siempre gana** al
    default, incluida la conservación de la cadena en bruto cuando falla la coerción (contrato
    VAR-2 intacto).
  - **Frontera del alcance**: la UNIDAD 6 no toca el DSL inline `{{variable|default:'valor'}}`
    (ya existente, UNIDAD 2, se verifica end-to-end), ni los tipos, ni las condiciones
    (AND/all/any/not/starts_with/ends_with), ni el webhook (el URL sigue literal, sin SSRF por
    variables). Sin `eval`, sin tabla ni DDL nuevos, sin cambios de API.
- **Consecuencias**: una respuesta vacía a una pregunta tipada con default produce la variable
  con el tipo real (p. ej. `integer` `'42'` → `42` int) y fluye a interpolación y condiciones;
  la suite pasa a 434 tests backend / 2013 assertions y se documenta el contrato runtime en
  `chatbot-engine.md` §5/§10.2 y `api.md` §3.8.

## ADR-047 · FASE 14 UNIDAD 1: validación y endurecimiento de triggers

- **Estado**: Aceptado — FASE 14 UNIDAD 1 completada
- **Contexto**: `docs/roadmap.md` especifica la FASE 14 (Triggers): disparo de `tag`/`schedule`/
  `webhook`. La auditoría técnica detectó que `tag`/`schedule`/`webhook` se aceptaban en el CRUD
  sin validar config, que `TriggerResource` exponía la config cruda, que el matcher no distinguía
  los nuevos tipos y que la regla `FLOW_ALREADY_PUBLISHED` (un flujo publicado por tenant con
  trigger genérico activo del mismo tipo) estaba documentada pero nunca implementada.
- **Decisión** (UNIDAD 1 — solo contrato y validación; SIN puntos de entrada de ejecución):
  - **`TriggerValidator` (dominio puro, backend autoritativo)**: validación por tipo —
    `keyword`/`new_message`/`start` sin config (keyword no vacío, ≤ 255); `tag` con
    `config.tags` (1..10 etiquetas únicas, ≤ 100 chars, sin ejecución — ejecución en FASE 20);
    `schedule` con `config.cron` (cron determinista de 5 campos, sin eval) +
    `config.conversation_id` (UUID); `webhook` con `config.conversation_by`
    (`conversation_id`|`contact_id`|`phone`). Límite de config 4096 chars. Errores 422 con el
    patrón de API existente (`errors.config`).
  - **Webhook token**: generación CSPRNG (`bin2hex(random_bytes(32))`), persistido SOLO como
    `config.token_hash = sha256(token)`, devuelto en claro una única vez en la respuesta de
    creación. `TriggerResource` redacta `token_hash`; auditoría/logs jamás lo contienen. El
    cliente no puede enviar `token`/`token_hash` (422). Al actualizar se preserva el hash
    existente; se regenera solo si el trigger pasa a webhook. El endpoint público de webhook es
    UNIDAD 3 (fuera de alcance).
  - **C4 (referencias seguras)**: sin migración — `schedule` referencia una conversación por
    UUID verificada dentro del tenant al crear/actualizar/publicar (404 genérico si no existe o
    es de otro tenant); `webhook` resolverá la conversación desde el payload identificador en
    UNIDAD 3. Nunca se confía en `tenant_id` del cliente.
  - **C1 (regla de publicación)**: al publicar se valida la config de todos los triggers del
    flujo (`422 FLOW_INVALID` si alguno es inválido) y se aplica la regla documentada: si el
    flujo tiene un trigger genérico (`new_message`/`start`) activo y otro flujo publicado del
    mismo tenant tiene un trigger activo del mismo tipo → `409 FLOW_ALREADY_PUBLISHED`. Los
    triggers específicos (`keyword`/`tag`/`schedule`/`webhook`) pueden coexistir entre flujos
    publicados (la regla NO bloquea `keyword`, ni siquiera con la misma palabra).
  - **C3 (tags)**: NO se implementa el disparo por etiqueta en UNIDAD 1 (las etiquetas son a
    nivel contacto; `TagNodeExecutor` no se toca). El matcher de mensaje excluye
    `tag`/`schedule`/`webhook`; `isImplementedInPhaseEleven()` se renombra a
    `isMessageTrigger()` y `TriggerMatcher::typeOrder` registra los tres tipos (que jamás
    matchean un mensaje).
- **Consecuencias**: suite backend 476 tests / 2184 assertions; U1 sin push (commit local
  `feat(flows): harden trigger validation`). UNIDADES 2-6 (scheduler, webhook público,
  ejecución por etiqueta, rotación de token, cleanup) quedan pendientes y se documentan al
  implementarse.

## ADR-048 · FASE 14 UNIDAD 2: disparo de triggers schedule

- **Estado**: Aceptado → FASE 14 (UNIDAD 2)
- **Contexto**: ADR-047 implementó la validación y el contrato de `schedule` (cron
  determinista de 5 campos + `conversation_id` UUID verificado en tenant), pero sin punto de
  entrada de ejecución. UNIDAD 2 cierra ese hueco: cada minuto, un sweeper evalúa qué
  triggers schedule coinciden con el minuto actual y despacha jobs que ejecutan el flujo en
  la conversación configurada.
- **Decisión**:
  - **Command `flow:fire-schedule-triggers`**: se ejecuta cada minuto via
    `routes/console.php` (`withoutOverlapping()`). Corre **fuera** de TenantContext (CLI
    global). La query busca triggers activos de tipo `schedule` cuyo flujo esté publicado y
    tenga chatbot, usando subqueries `withoutTenantScope()` para evitar el filtro global
    `WHERE 1=0`. Evalúa `TriggerValidator::matchesCron()` contra `now()` y despacha un
    `StartFlowFromSchedule` por cada match.
  - **Job `StartFlowFromSchedule`**: `TenantAwareJob` + `ShouldBeUnique` (por
    `triggerId`, `uniqueFor: 30s`). Establece su propio TenantContext, revalida todas las
    condiciones (defensa en profundidad: tenant, trigger activo, tipo schedule, flow
    publicado, chatbot, cron, conversación, bot no pausado, sin ejecución activa), adquiere
    lock Redis por trigger (30s TTL) para evitar doble disparo entre ticks, y delega al
    `FlowEngine::handleScheduleTrigger()`.
  - **`FlowEngine::handleScheduleTrigger()`**: wrapper público que crea `FlowExecution`,
    loguea `schedule_triggered` y ejecuta `handleScheduleTriggerLocked()` → start() + run()
    (mismo pipeline que los mensajes entrantes). El lock de conversación (`conversationLock`)
    previene ejecuciones concurrentes en la misma conversación.
  - **TenantAwareJob save/restore** (bug fix): el `handle()` anterior llamaba
    `TenantContext::clear()` incondicionalmente en `finally`, destruyendo el contexto del job
    padre cuando jobs hijos se ejecutaban sincrónicamente (cola sync). Ahora guarda el
    `tenant_id` previo y lo restaura (o limpia si no había contexto previo). Esto es un
    **cambio de producción**, no un workaround de tests.
  - **Capas de protección contra duplicación** (6 capas): (1) command `withoutOverlapping`,
    (2) `ShouldBeUnique` por trigger, (3) `Cache::lock` por trigger, (4)
    `FlowEngine::conversationLock`, (5) `FlowExecutionService::findActive`, (6) UNIQUE parcial
    en `flow_executions`.
  - **TenantContext en CLI global**: `FireScheduleTriggers` usa `whereIn` con subqueries
    `withoutTenantScope()` en lugar de `whereHas` anidados, porque PHPStan no reconoce
    `withoutTenantScope()` como método válido en el callback de `whereHas` (el builder no
    tiene el scope del modelo anidado).
- **Consecuencias**: suite backend 508 tests / 2250 assertions; U2 sin push. El sweeper es
  idempotente (re-ejecutar no duplica). El job revalida todo (nunca confía en el command).
  UNIDADES 3-5 (webhook público, ejecución por etiqueta, rotación de token) quedan
  pendientes.

---

## ADR-049: Webhook público de flujos (FASE 14, UNIDAD 3)

- **Fecha**: 2026-08-17
- **Estado**: Aceptado
- **Contexto**: Se necesita un endpoint público que permita disparar flujos vía HTTP sin
  autenticación Bearer de Sanctum, usando un token de webhook generado en U1. El endpoint
  debe ser seguro, idempotente, rate-limited y multi-tenant aislado. El tenant se resuelve
  EXCLUSIVAMENTE desde el trigger (nunca del payload).

- **Decisión**:

  1. **Ruta**: `POST /api/webhooks/flows/{trigger}` (fuera del prefijo `v1`, público,
     rate-limited por `throttle:flow-webhook` — 60 req/min por IP).

  2. **Autenticación por token**: el cliente envía `Authorization: Bearer {token}`. El token
     se compara con `config.token_hash` usando `hash_equals(hash('sha256', $token), $storedHash)`.
     Nunca se almacena/envía el token en claro. Error siempre 401 genérico (sin revelar si el
     trigger existe o es activo).

  3. **Resolución de conversación**: `config.conversation_by` define cómo resolver la
     conversación destino (`conversation_id` | `contact_id` | `phone`). Cada método valida que
     la resolución pertenece al tenant del trigger. Si falla → 400 genérico.

  4. **Idempotencia**: `Idempotency-Key` header → `Cache::lock` (60s TTL). Si ya procesado →
     409 `WEBHOOK_DUPLICATE`. Sin header → se genera uno automático único.

  5. **Despacho**: `FlowWebhookController` despacha `StartFlowFromWebhook` job (TenantAwareJob
     + ShouldBeUnique por idempotencyKey). El controller retorna 202 inmediatamente.

  6. **Job defensa en profundidad**: `StartFlowFromWebhook` revalida TODO en su propio
     TenantContext (tenant activo, trigger activo/tipo webhook, flow publicado, chatbot, bot no
     pausado, sin ejecución activa). Delega a `FlowEngine::handleScheduleTrigger()`.

  7. **Payload seguro**: máximo 64KB; solo se extraen campos permitidos (`conversation_id`,
     `contact_id`, `phone`, `payload`). `tenant_id` del body se ignora completamente.

  8. **Seguridad**: sin eval/exec; sin SSRF basado en payload; token/hash nunca en logs/auditoría/
     responses; `TriggerResource` redacta `token_hash`; `validatePayload` sin `JSON_THROW_ON_ERROR`
     (captura JSON inválido → 400).

- **Consecuencias**:
  - Suite: 545 tests / 2325 assertions (37 tests WEBHOOK-01..20 + 17 extensiones).
  - `FlowWebhookController` (app/Http/Controllers/Api/Webhooks/).
  - `StartFlowFromWebhook` (app/Jobs/).
  - Rate limiter `flow-webhook` (AppServiceProvider).
  - Ruta en `routes/api.php` fuera del grupo `v1`.
  - Documentación: `api.md`, `chatbot-engine.md` (§13), `security.md`, `testing.md`.
  - Commit local: `feat(flows): public webhook trigger endpoint`.
  - UNIDAD 3 completada. UNIDAD 4 (tag execution, FASE 20) pendiente.

---

## ADR-050: Ejecución del trigger tag diferida a FASE 20

- **Fecha**: 2026-08-17
- **Estado**: Aceptado — decisión de cierre de FASE 14
- **Contexto**: FASE 14 registra `FlowTriggerType::Tag` y valida `config.tags`, pero el modelo
  actual asigna etiquetas exclusivamente a `Contact` mediante `contact_tag`; `Conversation` no
  tiene tags. El único writer es `TagNodeExecutor`, que opera dentro de una ejecución activa y
  no existe `TagService`, API/UI de asignación, evento `TagAssigned` ni listener equivalente.
  El motor necesita una conversación para crear un `FlowExecution`, pero no está definida la
  política Contact→Conversation ni la semántica EVENT/ANY/ALL de `config.tags`. Disparar desde
  `TagNodeExecutor` además introduciría riesgo de recursión y contención del lock de conversación.
- **Decisión**:
  - FASE 14 queda completada con `keyword`, `new_message`, `start`, `schedule` y `webhook`
    funcionales. Para `tag`, únicamente quedan disponibles el tipo, su CRUD y la validación del
    contrato `config.tags`; `TriggerMatcher` no lo ejecuta.
  - La ejecución automática de triggers `tag` se difiere explícitamente a FASE 20. FASE 14 no
    adelanta `TagService`, eventos/listeners/observers, API/UI de tags, `StartFlowFromTag` ni
    modificaciones a `TagNodeExecutor`.
  - La postergación evita infraestructura temporal, lógica duplicada y una integración que FASE
    20 tendría que reemplazar.
- **Contrato requerido a FASE 20**:
  - Un servicio centralizado como única puerta de asignación/desasignación de tags.
  - Un evento estable equivalente a `TagAssigned` con tenant explícito e identidad de contacto
    y tag; nunca acepta `tenant_id`, `flow_id` o `conversation_id` arbitrarios del cliente.
  - Una política documentada para resolver Contact→Conversation, incluidos cero, una o varias
    conversaciones.
  - Una decisión explícita sobre semántica EVENT/ANY/ALL de `config.tags`.
  - Idempotencia, lock por conversación, `bot_paused`, ejecución activa y barreras
    anti-recursión antes de invocar el pipeline existente.
- **Consecuencias**: U4 se considera diferida por dependencia arquitectónica, no incompleta por
  defecto del código. No hay implementación parcial ni mecanismo temporal. FASE 20 consumirá el
  contrato anterior y reutilizará `FlowExecutionService` → `FlowEngine` sin crear un motor
  paralelo.

## ADR-076: Ejecución automática de flujos por tag (FASE 20 U4)

- **Fecha**: 2026-08-18
- **Estado**: Aceptado — implementación de FASE 20 U4
- **Contexto**: ADR-050 difiere la ejecución del trigger `tag` a FASE 20. U1–U3 establecen
  el contrato: `TagService` centralizado, eventos `TagAssigned`/`TagRemoved`, API de
  asignación batch y `ContactConversationResolver`. U4 debe consumir estos contratos para
  activar flujos automáticamente cuando un contacto recibe un tag que coincide con
  `config.tags` de un trigger.
- **Decisión**:
  - **Semántica EVENT**: cada `TagAssigned` se evalúa independientemente. Un trigger con
    `config.tags: ['vip', 'premium']` dispara si el tag asignado es `vip` O `premium` (no
    requiere ambos). Esto es consistente con los triggers schedule y webhook.
  - **Anti-recursión**: `origin=Flow` → skip completo en el listener. Un tag asignado por un
    flujo NUNCA dispara otro flujo. Esto previene cadenas tag→flow→tag. El mecanismo es
    simple y seguro: el enum `TagAssignmentOrigin` ya transporta el origen.
  - **Listener architecture**: primer listener del codebase. `DispatchTagTriggerJob` registrado
    vía `Event::listen()` en `AppServiceProvider::boot()`. Busca triggers activos del tenant
    con config.tags matching y despacha un `StartFlowFromTag` por cada uno.
  - **Job pattern**: `StartFlowFromTag` sigue exactamente el patrón de `StartFlowFromSchedule`
    y `StartFlowFromWebhook`: ShouldBeUnique, TenantAwareJob, defensa en profundidad,
    Cache::lock por trigger, delega a `FlowEngine::handleScheduleTrigger()`.
  - **Resolución Contact→Conversation**: reutiliza `ContactConversationResolver` (U3). Null →
    skip (sin conversación no se dispara). No se crea conversación implícita.
  - **Matching**: case-sensitive, sin folding de acentos. `'VIP'` ≠ `'vip'`.
  - **Auditoría**: `flow.tag_triggered` con `{trigger_id, flow_id, conversation_id, tag_name}`.
- **Consecuencias**:
  - `FlowEngine` NO se modifica; se reutiliza `handleScheduleTrigger()` directamente.
  - El listener corre afterCommit del evento (transaction safety).
  - `TagAssigned` con `origin=Flow` nunca llega al job (filtrado en listener).
  - Tests: 16 nuevos (TAG-U4-01..16) cubriendo happy path, barrieras, anti-recursión,
    matching, auditoría, multi-tenancy y listener.

---

## ADR-051: Semántica terminal de Human Handoff

- **Fecha**: 2026-08-17
- **Estado**: Aceptado — FASE 15 UNIDADES 1 y 3
- **Contexto**: el handoff básico de FASE 11 pausa el bot y finaliza la ejecución, pero la
  documentación también describía `human` como waiting y sugería reanudar la ejecución previa.
  El motor ya trata `FlowExecutionStatus::HandedOff` como terminal y limpia
  `conversation.flow_execution_id` al finalizar.
- **Decisión**:
  - `handed_off` es terminal: nunca vuelve a `running`/`waiting`, no revive ni continúa desde
    `current_node_id`.
  - `resume-bot` solo habilita automatización para futuros inbound; no procesa retroactivamente
    mensajes recibidos durante handoff ni revive la ejecución anterior.
  - El modelo de atención es cola manual: handoff sin asignar → claim del usuario autenticado en
    U2. No hay round robin, least-loaded, presencia, teams, capacity ni auto-routing.
  - `resume-bot` no libera automáticamente `agent_id` ni la assignment abierta.
  - `handoff_message` es opcional; ausente, null o vacío es válido. Su envío se implementará en
    U3, antes de finalizar el handoff cuando tenga texto.
  - `conversations.handoff_requested_at` distingue una solicitud humana de una pausa manual. El
    HumanNode lo escribe en U3; claim/resume no lo limpian por sí solos. Una nueva pausa manual,
    después de haber reanudado, limpia el marcador histórico para no convertir la pausa en un
    handoff reclamable.
  - Un outbound automático aún no enviado debe bloquearse después del handoff; la comprobación
    operativa pertenece a U3.
  - Human conserva `status=open|pending`. Si la conversación está `resolved|archived`, el handoff
    falla con `CONVERSATION_INVALID_STATE`; nunca reabre implícitamente. Reopen sigue siendo una
    acción explícita del lifecycle.
  - `HumanHandoffService` corre bajo el `conversationLock` que ya mantiene `FlowEngine` y no lo
    readquiere. En una transacción con conversation `FOR UPDATE` pausa el bot, fija el timestamp,
    crea una única notificación opcional y audita `flow.handoff`; solo después del commit el motor
    finaliza y audita `flow.execution_handed_off`. La consulta de audit por execution hace el paso
    idempotente ante reintento.
  - `messages.metadata.origin` distingue `automation`, `human` y `handoff`, sin DDL. Un mensaje
    legacy sin actor se trata fail-closed como automation. `SendWhatsAppMessage` comparte el
    `conversationLock` durante la llamada al provider: si observa el handoff antes de enviar,
    termina el mensaje como `failed`, guarda `BOT_PAUSED_HANDOFF`/`internal` en metadata, audita y
    no crea `message_send_attempts` ni reintenta como error Meta. Human y handoff siguen permitidos.
    En cola sync, un contexto process-local reconoce el lock que ya posee el caller y evita una
    readquisición; entre procesos la exclusión continúa dependiendo exclusivamente de Redis.
  - Una respuesta manual toma `sent_by_user_id` exclusivamente del usuario autenticado. Agents
    solo responden la conversación asignada; owner/admin conservan el override de gestión. Solo se
    responde en `open|pending`; actor/origin/tenant/direction/status públicos están prohibidos.
  - El notification center y emails automáticos se difieren a FASE 22.
- **Consecuencias**: `human` deja de clasificarse como waiting y es un terminal válido alternativo
  a `end`. U3 implementa el runtime, resume atómico, actor y barrera outbound sin migraciones ni
  ampliar el realtime tenant-wide reservado para U4.

## ADR-052: Consistencia de asignación de conversaciones

- **Fecha**: 2026-08-17
- **Estado**: Aceptado — FASE 15 UNIDADES 1-2
- **Contexto**: `conversations.agent_id` representa el agente vigente, mientras
  `conversation_assignments` conserva historial y `conversation_participants` participación.
  Las tablas hijas no tenían `tenant_id` ni una barrera DB contra assignments abiertas duplicadas.
- **Decisión**:
  - `conversations.agent_id` es la fuente operativa; assignments son historial y participants
    participación. Desde U2 las tres proyecciones mutan en una transacción bajo el lock de
    conversación existente.
  - Assignments y participants incorporan `tenant_id` UUID NOT NULL, FK a tenants, FK compuesta
    `(tenant_id, conversation_id)` a conversations, índices tenant-first y `BelongsToTenant`. La
    barrera compuesta impide asociar una fila al tenant correcto pero a una conversación ajena.
    El backfill se deriva exclusivamente de `conversation_id → conversations.tenant_id` y aborta
    ante datos no derivables.
  - Solo puede existir una assignment con `unassigned_at IS NULL` por conversación; PostgreSQL y
    SQLite lo protegen con un índice UNIQUE parcial sobre `conversation_id`.
  - Claim es manual y atómico. El cliente no aporta el agente destino: siempre es el usuario
    autenticado. `conversations.claim` se concede a owner/admin/agent; `conversations.assign`
    continúa reservado para assign/transfer administrativos.
  - Orden único: conversation lock Redis → transacción → conversation `FOR UPDATE` → memberships
    bloqueadas por `users.id`. La membership y el permiso se revalidan dentro de la operación.
  - Assign solo toma conversaciones libres; repetir el mismo target es idempotente si assignment y
    participant coinciden. Transfer exige agente, assignment y participant vigentes; A→A es 409.
    Inconsistencias previas fallan controladamente y no reescriben historial silenciosamente.
  - `conversation_participants` conserva su UNIQUE `(conversation_id,user_id)`: una fila representa
    participación acumulativa. Una reactivación conserva `joined_at`, actualiza rol y limpia
    `left_at`; períodos múltiples requerirían DDL futuro y no forman parte de U2.
  - Audit forma parte de la transacción y `ConversationUpdated` se despacha tras ella con broadcast
    after-commit. `InboxConversationChanged` sigue reservado para U4.
  - `messages.sent_by_user_id` nullable atribuye mensajes humanos. Inbound, automation y handoff
    permanecen null; U3 aplica la policy tenant-aware y no confía en payload público.
- **Consecuencias**: U2 implementa claim/assign/transfer, no release. U3 implementa
  HumanNodeExecutor, resume y atribución desde MessageService. `auto_assigned` permanece false. La UNIQUE parcial es backstop,
  no el mecanismo primario de concurrencia. Como esquema y código empiezan a exigir `tenant_id`
  juntos, el cambio U1 se despliega coordinadamente, no con workers de versiones mezcladas.

## ADR-053: Frontera realtime del Inbox para handoff

- **Fecha**: 2026-08-17
- **Estado**: Implementado (FASE 15 UNIDAD 4)
- **Contexto**: `ConversationUpdated` sirve al detalle de una conversación, pero los agentes no
  conocen una conversación en cola que todavía no tienen abierta. FASE 22 mantiene la
  responsabilidad del notification center y email automático.
- **Decisión**:
  - `ConversationUpdated` continúa como evento de detalle.
  - U4 implementará un único evento tenant-wide `InboxConversationChanged`, emitido after-commit,
    con canal privado, aislamiento tenant y payload versionado/idempotente.
  - FASE 15 no crea notification center, tabla de notifications ni email automático.
- **Consecuencias**: U1 solo registra el contrato. No se añaden eventos, listeners, canales ni UX
  realtime en esta unidad.

## ADR-054: AI Provider Infrastructure (FASE 16 U1)

- **Fecha**: 2026-08-17
- **Estado**: Implementado (FASE 16 UNIDAD 1)
- **Contexto**: El motor de flujos tiene un nodo AI (`FlowNodeType::AI`) pero la capa de IA no existe:
  `AIProviderInterface` solo está documentada, `OpenAIProvider` no existe, no hay config, ni VOs, ni
  excepciones. U1 establece la infraestructura base para que U2 integre el nodo AI en el motor.
- **Decisión**:
  - **Interfaz mínima**: `AIProviderInterface` expone un único método `generateResponse(AIRequest): AIResponse`.
    Se rechaza la interfaz de 5 métodos (classifyIntent, summarizeConversation, etc.) por YAGNI.
    Se implementará cuando sea necesario.
  - **Value Objects inmutables**: `AIRequest` (prompt, systemPrompt, model, temperature, maxTokens)
    y `AIResponse` (content, provider, model, inputTokens, outputTokens, totalTokens).
    `AIResponse` lleva telemetría de tokens acoplada para desacoplar del formato del proveedor.
  - **Excepciones de dominio tipadas**: `AIException` (abstracta), `AIAuthFailedException` (401),
    `AIRateLimitException` (429, retryable), `AIInvalidRequestException` (400),
    `AIProviderException` (5xx, retryable configurable). Cada una lleva `AIErrorCode` enum.
  - **HTTP Client de Laravel** (no SDK): consistente con `MetaWhatsAppProvider`. Endpoint
    `POST /v1/chat/completions`. Retry solo en `ConnectionException`.
  - **API key global**: plataforma en `config/ai.php` → `.env`. Nunca en DB por tenant,
    nunca en frontend, response, logs ni auditoría.
  - **Provider stateless re: tenant**: sin `TenantContext`, `Contact`, `Conversation` ni
    `BusinessProfile` queries dentro del provider. El contexto se prepara externamente (U2).
  - **Config minimalista**: model, timeout, max_retries, max_tokens. Sin temperature default
    en config (se maneja en el request).
- **Consecuencias**:
  - U2 integrará el nodo AI en FlowEngine usando el binding de `AIProviderInterface`.
  - La interfaz se extenderá cuando se necesite (RAG, embeddings, etc.).
   - Tests unitarios cubren VO inmutabilidad, Http::fake, manejo de errores y telemetría.

## ADR-055: AI Node Runtime (FASE 16 U2)

- **Fecha**: 2026-08-18
- **Estado**: Implementado (FASE 16 UNIDAD 2)
- **Contexto**: U1 estableció la infraestructura de IA (contract, provider, VOs, exceptions, config).
  El motor de flujos tiene un nodo `AI` pero `FlowValidator` lo rechaza como "no disponible".
  Se necesita integrar el nodo AI en el motor para que los flujos puedan generar contenido con
  IA, guardarlo en `custom.*` y continuar el flow.
- **Decisión**:
  - **Ejecución síncrona**: AI NO es waiting type. El nodo se ejecuta inline dentro del worker,
    guarda el output en `execution.variables.custom[output_variable]` y devuelve `continue`.
    No hay pausa ni reintento diferido.
  - **Genera contenido, no envía mensajes**: `AiNodeExecutor` llama al provider, sanitiza la
    respuesta y la persiste. Un nodo `message` posterior interpola `{{custom.output_variable}}`.
  - **Prompt builder separado**: `AiPromptBuilder` construye SYSTEM (instrucciones de plataforma),
    CONTEXT (contacto, negocio, custom vars) y USER (prompt del nodo resuelto). Separación clara
    para evitar inyección.
  - **Fallback**: si el provider falla o devuelve vacío, se aplica `config.fallback_message` del
    nodo o el global. El flow siempre continúa.
  - **Idempotencia**: `isAlreadyCompleted()` verifica output existe + log `ai_completed` registrado.
    Si ambos cierran, reutiliza sin nueva llamada al provider.
  - **bot_paused**: verificado primero como defense-in-depth. Si el conversation tiene
    `bot_paused = true`, el nodo se salta sin llamar al provider.
  - **Validación**: `FlowValidator::validateAiNode()` valida prompt (required, non-empty, max length),
    output_variable (VariableGuard), system_prompt y fallback_message opcionales.
  - **AI no puede ser start node**: validado en `FlowValidator`.
- **Consecuencias**:
  - Suite AI: 41 tests / 86 assertions (unit 15 + feature 10 + security 10 + tenant 6).
  - Aislamiento tenant: el output de Tenant A jamás aparece en Tenant B (AI-MT-01..06).
  - Seguridad: API key nunca en logs/audit, prompt/response nunca completos en logs,
    output tratado como texto plano, inyección bloqueada (AI-S01..S10).
   - `isWaitingType()` ahora solo incluye `[Question, Buttons]`. AI y Delay excluidos.

## ADR-057: AI Usage Telemetry (FASE 16 U4)

- **Fecha**: 2026-08-18
- **Estado**: Implementado (FASE 16 UNIDAD 4)
- **Contexto**: U1-U3 establecieron la infraestructura AI, el runtime del nodo y el editor visual.
  Cada llamada al provider genera tokens facturables pero no hay telemetría estructurada en
  `flow_execution_logs` más allá de los campos básicos de U2 (`provider`, `model`, tokens).
  Se necesita: latencia, success/error, error_code, fallback_used — todo sin PII.
- **Decisión**:
  - **TelemetryPayload VO inmutable**: `app/Domain/AI/ValueObjects/TelemetryPayload.php`.
    Dos fábricas estáticas: `fromResponse()` (éxito) y `fromError()` (fallo).
    Safe schema estricto: `{operation, provider, model, input_tokens, output_tokens,
    total_tokens, latency_ms, success, error_code, fallback_used}`.
  - **Latencia con monotonic clock**: `hrtime(true)` mide nanosegundos alrededor de
    `AIProviderInterface::generateResponse()`. Se convierte a milisegundos enteros (>= 0).
  - **Tokens validados**: `max(0, $tokens)` en `fromResponse()`; `null` en `fromError()`.
  - **Zero PII guarantee**: TelemetryPayload solo acepta campos numéricos/enum/string seguro.
    Nunca contiene: prompt, system_prompt, response content, contact data, business data,
    custom.secret. Verificado por tests AI-U07, AI-U08, AI-U21.
  - **Extensión de logs existentes**: `ai_completed` y `ai_failed` se enriquecen con los
    campos de TelemetryPayload sin crear eventos nuevos ni tablas nuevas.
  - **Idempotencia preservada**: si `isAlreadyCompleted()` retorna true (output + log ai_completed
    presentes), se reutiliza sin nueva llamada al provider y sin duplicar telemetría.
  - **ai_failed enriquecido**: ahora incluye `error_code` (AIErrorCode enum value cuando es
    AIException), `fallback_used` (reemplaza `fallback_applied`), `latency_ms`, `success: false`.
  - **Sin nuevos endpoints**: telemetría es solo WRITE en flow_execution_logs. No hay lectura
    agregada, dashboards, o tablas de billing en U4.
- **Consecuencias**:
  - TelemetryPayload: 8 tests VO (AI-U01..U08).
  - Executor telemetry: 17 tests (AI-U09..U25) — latencia, success, error_code, fallback_used,
    idempotencia, PII, schema keys.
  - Suite FASE 16 U4: 25 tests / 120 assertions.
  - Suite total: 751 tests / 3014 assertions.
  - `docs/ai.md` §11 documenta el safe schema y la arquitectura de telemetry.

## ADR-056: AI Prompt/Data Security Boundaries (FASE 16 U5)

- **Fecha**: 2026-08-18
- **Estado**: Formalizado (FASE 16 UNIDAD 5)
- **Contexto**: FASE 16 U1-U4 implementaron AI provider, runtime, UI y telemetría.
  Las propiedades de seguridad estaban distribuidas en tests individuales (AI-S01..S10,
  AI-MT-01..06, AI-U07..U08, AI-U21) pero no existía una matriz formal que verificara
  todas las propiedades de seguridad de forma compacta y auditable.
- **Decisión**:
  - **Security Matrix AI-SEC-F01..F12**: 12 tests formales que verifican cada propiedad
    de seguridad en un solo archivo `AiSecurityMatrixTest.php`:
    - F01: API key nunca en flow_execution_logs
    - F02: API key/provider/model nunca en frontend config
    - F03: API key nunca en audit_logs
    - F04: Prompt completo nunca en telemetría
    - F05: Response completo nunca en telemetría
    - F06: PII (contacto) nunca en telemetría
    - F07: Tenant A telemetry sin datos de Tenant B
    - F08: Output malicioso almacenado como texto plano
    - F09: bot_paused bloquea provider completamente
    - F10: Dependencia solo AIProviderInterface (no OpenAIProvider)
    - F11: tenant_id injection en config no altera contexto
    - F12: Excepciones AI sanitizadas (sin stack traces)
  - **Bug fix**: `OpenAIProvider::parseResponse()` lanzaba `RuntimeException` para
    respuestas malformadas en lugar de `AIProviderException`. Esto violaba el contrato
    de `AIProviderInterface` y permitía que errores escapearan del `catch (AIException)`.
    Corregido a `AIProviderException` (1 línea). Test AI-P14 actualizado.
  - **Boundary formalization**: RAG (FASE 17), FAQ (FASE 18), Billing/UsageGuard
    (FASE 23-25) verificados como ausentes — solo documentación, cero código.
  - **DDL verification**: cero migraciones AI/usage en el codebase.
- **Consecuencias**:
  - Security matrix: 12 tests / 41 assertions (AI-SEC-F01..F12).
  - Suite FASE 16 U5: 13 tests / 44 assertions (12 matrix + 1 Pint fix).
   - Suite total: 763 tests / 3055 assertions.
   - FASE 16 cerrada formalmente.

## ADR-058: Knowledge Base Data Model and Vector Storage (FASE 17 U1)

- **Fecha**: 2026-08-18
- **Estado**: ACEPTADO (FASE 17 UNIDAD 1)
- **Contexto**: El motor de flujos FASE 16 genera respuestas de IA basadas exclusivamente en
  el prompt del nodo AI. Para habilitar RAG (Retrieval-Augmented Generation), se necesita
  almacenar documentos de conocimiento por tenant con embeddings vectoriales para búsqueda
  semántica.
- **Decisión**:
  - **3 tablas**: `knowledge_bases`, `knowledge_documents`, `knowledge_chunks`.
    - `knowledge_bases`: PK uuid, `tenant_id` FK cascadeOnDelete, `name`, `description`
      text nullable, soft deletes. UNIQUE parcial `(tenant_id, name) WHERE deleted_at IS NULL`.
    - `knowledge_documents`: PK uuid, `tenant_id` FK cascadeOnDelete, `knowledge_base_id` FK
      cascadeOnDelete, `filename`, `storage_path`, `file_size`, `mime_type`, `file_hash`
      varchar(64), `status` enum(uploaded/processing/ready/failed), `chunk_count` default 0,
      `total_tokens` default 0, `processed_at` nullable, `error_message` text nullable,
      timestamps, soft deletes. UNIQUE parcial `(tenant_id, knowledge_base_id, file_hash)
      WHERE deleted_at IS NULL`.
    - `knowledge_chunks`: PK uuid, `tenant_id` FK cascadeOnDelete, `document_id` FK
      cascadeOnDelete, `content` text, `embedding` vector(1536) (PostgreSQL only,
      conditional migration), `token_count`, `chunk_index`, `metadata` JSONB nullable,
      timestamps. UNIQUE `(document_id, chunk_index)` (PostgreSQL only). HNSW index
      on `embedding` with `vector_cosine_ops`, m=16, ef_construction=64.
  - **Soft deletes**: KB y documents sí (conservar historial). Chunks no (datos derivados
    regenerables — CASCADE elimina al eliminar documento padre).
  - **FK compuesta**: `(tenant_id, knowledge_base_id)` en documents garantiza aislamiento
    cross-tenant a nivel DB (un documento nunca puede apuntar a KB de otro tenant).
  - **Vector dimension**: `vector(1536)` hardcodeado en migración (NO configurable). Contrato
    con `text-embedding-3-small` de OpenAI. Las migraciones deben ser deterministas.
  - **HNSW**: m=16, ef_construction=64. Mejor latencia que IVFFlat para tamaños medianos.
    No requiere reconstrucción como IVFFlat.
  - **EmbeddingProviderInterface**: interfaz separada de AIProviderInterface (SRP/YAGNI). El
    proveedor de embeddings puede ser distinto del de chat. No se implementa en U1 — solo
    la abstracción documental.
  - **Condiciones**: chunk_index独一 por documento; content no puede ser vacío; file_hash
    se calcula sobre el contenido binario (SHA-256).
- **Consecuencias**:
  - Multi-tenancy reforzado: FK compuesta + scope global + unique parcial = 3 capas de
    aislamiento.
  - Migración condicional (PostgreSQL only para vector/HNSW) permite tests unitarios SQLite
    sin pgvector.
  - Chunks sin soft delete simplifican el ciclo de vida: regeneración limpia al re-procesar.
  - HNSW m=16, ef_construction=64 es un equilibrio entre calidad y memoria para <100K chunks
    por tenant (escalable a millones con ajuste posterior).

## ADR-059: Embedding Abstraction (FASE 17 U1 - Diseño)

- **Fecha**: 2026-08-18
- **Estado**: DISEÑADO (no implementado — pendiente U3)
- **Contexto**: RAG requiere generar embeddings vectoriales para los chunks de texto. El
  provider de IA (OpenAI chat) es conceptualmente distinto del provider de embeddings.
- **Decisión**:
  - Crear `EmbeddingProviderInterface` con `embedText(string $text): array` y
    `embedBatch(array $texts): array`.
  - `OpenAIEmbeddingProvider` implementa la interfaz usando `text-embedding-3-small` (1536 dims).
  - Binding en `AppServiceProvider`: `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider`.
  - Dimensión 1536 hardcoded en la migración (consistente con ADR-058).
- **Consecuencias**:
  - Separación clara de responsabilidades (SRP): chat ≠ embedding.
  - Testable con FakeEmbeddingProvider sin llamadas a OpenAI.
  - Swap a otros providers (Cohere, open-source) trivial.

## ADR-060: Knowledge Base API Contract + Permissions (FASE 17 U2.1)

- **Fecha**: 2026-08-18
- **Estado**: ACEPTADO
- **Contexto**: U1 definió el data model (knowledge_bases, knowledge_documents, knowledge_chunks).
  U2.1 necesita exponer CRUD de KB y documentos vía REST, con permisos por rol y aislamiento
  multi-tenant. POST upload se difiere a U2.2 (requiere Storage/MinIO).
- **Decisión**:
  - **Permisos**: `knowledge.view` (owner/admin/agent) + `knowledge.manage` (owner/admin) en
    TenantPermission enum. Matriz y seeder sincronizados.
  - **Services**: `KnowledgeBaseService` (CRUD completo) + `DocumentService` (index/show/delete
    only; store diferido a U2.2). Authorization en service, no en FormRequest.
  - **Controllers**: `KnowledgeBaseController` (5 acciones) + `DocumentController` (3 acciones,
    sin store). Patrón ContactController: try/catch con helpers `forbidden()`, `tenantNotActive()`,
    `duplicate()`.
  - **Routes**: `/knowledge-bases` + `/knowledge-bases/{kb}/documents` bajo middleware `tenant`.
    Sin route-model binding — {kb} y {doc} son strings resueltos por service.
  - **Resources**: `KnowledgeBaseResource` (id, name, description, documents_count condicional,
    timestamps) + `DocumentResource` (safe fields: sin file_hash, storage_disk, storage_path).
  - **Errors**: `KnowledgeBaseNotFoundException` (404), `KnowledgeBaseDuplicateException` (409,
    code KB_DUPLICATE), `DocumentNotFoundException` (404). Mapeo por controller.
  - **Pagination**: search + per_page (1..100) en ambos controllers. Respuesta `{data, meta}`.
  - **Audit**: events `knowledge_base.created/updated/deleted` + `knowledge_document.deleted`.
  - **Concurrency**: `UniqueConstraintViolationException` catch en create/update (maneja tanto
    `Illuminate\Database\QueryException` como `PDOException` para SQLite).
- **Consecuencias**:
  - POST upload no crea documentos sin storage (sin código falso, sin records huérfanos).
  - Auth en FormRequest retorna true (patrón establecido en FASE 8+); authorization en service.
  - POST/PUT/DELETE de documents rechazados (405) hasta U2.2.
  - Partial unique index `(tenant_id, name) WHERE deleted_at IS NULL` validado en PG; skip en
    SQLite (test KB-U21-04).

## ADR-061: Private Knowledge Document Storage (FASE 17 U2.2)

- **Fecha**: 2026-08-18
- **Estado**: ACEPTADO
- **Contexto**: U2.1 completó el CRUD de metadata (index/show/delete) de knowledge documents pero
  POST upload no estaba implementado. U2.2 agrega el upload real: HTTP multipart → validación →
  storage privado → DB row. Requiere Server-side MIME detection, SHA-256 dedup, compensación
  storage/DB, path server-side para mitigar traversal, y validación de estructura DOCX/PDF/TXT.
- **Decisión**:
  - **Validación en capas**: Extension whitelist → finfo MIME (con bypass DOCX→application/zip) →
    magic bytes (%PDF-, PK) → tamaño → emptiness → DOCX ZIP structure → TXT null-byte/UTF-8.
    Todo en `DocumentUploadValidator` (Application layer), no en FormRequest.
  - **Config centralizada**: `config/knowledge.php` (upload.allowed_extensions, allowed_mime_types,
    max_file_size, storage_disk, storage_prefix). Env override para storage_disk.
  - **Domain Exceptions**: `DocumentStorageFailedException` (500), `DocumentInvalidFileException`
    (422), `DocumentTooLargeException` (413), `DocumentUnsupportedTypeException` (422),
    `DocumentDuplicateException` (409). Cada una con ERROR_CODE y HTTP_STATUS.
  - **Storage path**: `knowledge/tenant/{tenantId}/knowledge-bases/{kbId}/documents/{docId}/source.{ext}`
    100% server-side, UUID-based, deterministic, sin nombres de usuario.
  - **Dedup**: SHA-256 streaming → misma KB + mismo hash + active doc → 409 DOCUMENT_DUPLICATE.
    Soft-deleted docs permiten re-upload (partial unique index PG).
  - **Compensación**: Storage write primero → DB row en transaction → si DB falla, delete storage.
  - **Audit**: `knowledge_document.uploaded` con document_id, knowledge_base_id, mime_type, file_size,
    status. Sin storage_path ni file_hash en audit data.
  - **DOCX MIME bypass**: `finfo` detecta DOCX como `application/zip`, no OOXML MIME. Validator
    permite `application/zip` cuando extensión es `.docx` y structure validation confirma.
  - **NO extraction/chunking/embeddings**: status queda `uploaded`, chunk_count=0. Extracción
    diferida a U3+.
- **Consecuencias**:
  - 39 tests: KB-U22-01..06 (upload valid), V01..V07 (validación), D01..D04 (dedup),
    S01..S06 (storage), MT01..MT08 (tenancy), A01..A02 (audit), NO-01..03 (confirmations),
    SEC-01..03 (seguridad).
  - DocumentResource no expone storage_disk, storage_path, file_hash.
  - FormRequest solo valida `required|file|max:10MB`. Toda validación de seguridad en service.
  - Tests usan `Storage::fake()` (minio disk). No requieren MinIO real.
  - POST `/api/v1/tenants/{tenant}/knowledge-bases/{kb}/documents` agregado a routes.

## ADR-062: Embedding Nullability — P0 Corrective Fix (FASE 17 P0)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: La migración U1 (`2026_08_18_020200`) definió `knowledge_chunks.embedding` como
  `vector(1536)` NOT NULL. U2.3 crea chunks con `embedding=NULL` (extracción + chunking antes de
  embeddings). U3 materializará embeddings. En PostgreSQL real, INSERT con `embedding=NULL` falla
  con violación de NOT NULL constraint.
- **Decisión**:
  - Crear migración correctiva (`2026_08_19_161000_make_knowledge_chunks_embedding_nullable`)
    que ejecuta `ALTER TABLE knowledge_chunks ALTER COLUMN embedding DROP NOT NULL` en PostgreSQL.
  - SQLite: no-op (columna embedding no existe en SQLite).
  - DOWN: revierte a NOT NULL, pero FALLA si existen filas con `embedding=NULL` (protección de
    datos). Mensaje claro: "Populate all embeddings before running this rollback."
  - NULL significa: "chunk creado, embedding pendiente/no generado". NO vector placeholder `[0,0,...]`.
  - NO se modifica la migración histórica U1.
- **Consecuencias**:
  - U2.3 puede persistir chunks sin embeddings → pipeline extraction → chunking → persistence.
  - U3 (embeddings) actualizará chunks existentes con `embedding IS NULL`.
  - `ChunkPersistenceService` inserta `embedding=NULL` (pre-embedding stage).
  - HNSW index preservado. vector(1536) preservado. Solo cambia nullability.
   - 7 tests PG: EMB-NULL-PG-01..07 (nullable, insert NULL, insert valid, HNSW, cosine_ops,
     UP/DOWN/UP, DOWN safety).

## ADR-063: Text Extraction Architecture (FASE 17 U2.3)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U2.2 completó upload de documentos (PDF, DOCX, TXT) con validación de seguridad.
  U2.3 debe extraer texto plano de documentos subidos para chunking posterior y persistencia.
- **Decisión**:
  - **Factory pattern**: `DocumentTextExtractorFactory` resuelve extractor por MIME type.
    Extensible para futuros formatos (HTML, XLSX, etc.).
  - **PlainTextExtractor**: Validación UTF-8 via `preg_match` (no `mb_check_encoding` que es
    platform-dependent). BOM strip. Null byte reject. Binary reject. Sanitize inválidos
    en vez de fallar (mejor-output-than-no-output).
  - **DocxTextExtractor**: XML parsing directo de `word/document.xml`. Triple defensa:
    ZIP bomb (500 entries, 50MB uncompressed, 100:1 ratio), XML entity injection,
    Zip Slip paths.
  - **PdfTextExtractor**: `smalot/pdfparser v2.12.0` via temp file. No expone excepciones
    internas del parser. Pre-check de `max_extracted_text_size` en raw content para evitar
    crashes del parser con strings enormes (regex limit).
  - **TextNormalizer**: Pipeline CRLF→LF, null strip, control chars strip, Unicode NFC,
    whitespace collapse, trim. Validación de tamaño post-normalización.
  - **DocumentChunker**: Split párrafos → oraciones → caracteres. Overlap configurable.
    Merge de chunks pequeños. Max chunks limit.
  - **ChunkPersistenceService**: Replace atómico de chunks por documento. Tenant_id server-side.
  - **Value Objects**: `ExtractedText` (text + characterCount + metadata), `DocumentChunk`
    (content + chunkIndex + tokenCount).
- **Consecuencias**:
  - 95 tests U2.3 (84 unit + 11 feature). PHPStan 0 errors. Pint clean.
  - Factory extensible: agregar nuevo extractor = 1 clase + 1 línea en factory.
  - PDF parsing con dependencia externa (`smalot/pdfparser`). Actualizada en composer.json.
  - Parser type hint: `Document` (no `PDF`) — smalot v2 usa `Document` como retorno.

## ADR-064 · Document Processing State Machine + Idempotency

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U2.2 completa upload de documentos; U2.3 completa extracción + chunking.
  U2.4 orquesta el pipeline de procesamiento de forma asíncrona. El procesamiento debe
  ser idempotente (un documento solo se procesa una vez), seguro ante concurrencia
  (múltiples workers no duplican chunks), y tolerant a fallos (errores no contaminan
  datos de otros tenants ni exponen información interna).
- **Decisión**:
  - **State machine**: uploaded → processing → ready/failed. `ready` = ingestion/chunking
    complete (embedding NULL, U3 lo materializa). `failed` es terminal.
  - **CAS (Compare-And-Swap)**: transiciones atómicas con `WHERE status = <expected>`.
    Múltiples capas: DB-level CAS (uploaded→processing), Cache lock (runtime), 
    ShouldBeUnique (queue-level).
  - **Capas de protección contra duplicación**:
    1. `ShouldBeUnique` por tenant+document (queue): `uniqueId()` = `knowledge-document:{tenantId}:{documentId}`, `uniqueFor()` = 300s.
    2. `Cache::lock` por tenant+document (runtime): lock key `lock:tenant:{id}:knowledge-document:{docId}:processing`.
    3. CAS uploaded→processing (DB): solo el primer `beginProcessing` exitoso avanza.
    4. CAS processing→ready/failed (DB): solo el que procesa exitosamente marca ready.
  - **Pipeline**: `KnowledgeDocumentProcessingService` orquesta: validate → read source → extract → normalize → chunk → persist → mark ready/failed.
  - **Error sanitization**: `error_message` expone solo códigos genéricos (`missing_source`, `extraction_failed`, etc.). Nunca paths, stack traces, ni info de infraestructura.
  - **Delete guard**: `DocumentProcessingException` (409 `DOCUMENT_PROCESSING`) al intentar eliminar un documento en estado `processing`.
  - **Failed() safety net**: El método `failed()` del job marca el documento como `failed` si aún está en `processing` (recurso de último recurso).
  - **Queue config**: `knowledge.processing.tries` = 3, `knowledge.processing.backoff` = [30, 60] segundos.
- **Consecuencias**:
  - 40 tests U2.3 (PROC-01..10, PROC-FAIL-01..10, PROC-MT-01..06, PROC-CON-01..05, QUEUE-01..07, DELETE-01..02).
  - Invariante: un documento solo se procesa una vez (4 capas de protección).
  - Invariante: chunks de tenant A jamás se contaminan con datos de tenant B.
  - Invariante: errores sanitizados (sin paths/stack traces en `error_message`).
  - Invariante: `ready` = ingestion/chunking complete, embedding NULL.

## ADR-065 · Separate Embedding Provider Contract

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U3.1 necesita generar embeddings vectoriales para búsqueda semántica.
  La infraestructura AI de FASE 16 (`AIProviderInterface`) solo soporta chat completions.
  Embeddings son un dominio fundamentalmente distinto: input es batch de textos,
  output es batch de vectores float, el endpoint API es diferente, los costos son
  diferentes, y la dimensionalidad es un contrato crítico con la DB.
- **Decisión**:
  - **Interfaz separada**: `EmbeddingProviderInterface` con `embed(EmbeddingRequest): EmbeddingResponse`.
    No se agrega método a `AIProviderInterface` (SRP + ISP).
  - **Dimension contract**: `vector(1536)` hardcodeado en config. Fail closed ante
    `EmbeddingDimensionMismatchException`. No truncar, pad, ni convertir.
  - **Response cardinality**: N inputs → exactamente N embeddings. Validación de
    `index` field: sequential, no duplicates, no gaps. Sort by index.
  - **Float validation**: Cada elemento del vector debe ser numérico finito.
    NaN/INF/strings rechazados.
  - **Error taxonomy separada**: `EmbeddingErrorCode` enum con 7 casos
    (auth, rate, invalid_request, invalid_response, dimension_mismatch, provider, timeout).
  - **OpenAI provider**: `OpenAIEmbeddingProvider` con Http facade, consistente
    con `OpenAIProvider` existente. Endpoint `/v1/embeddings`.
  - **Batch guard**: `max_batch_size` configurable (default 50). Provider rechaza
    batches que excedan el límite.
  - **No real network**: Tests con `Http::fake()` y `FakeEmbeddingProvider`.
    API key nunca en exceptions, logs, ni response.
  - **Config**: Sección `embedding` en `config/ai.php`. Reutiliza `OPENAI_API_KEY`.
- **Consecuencias**:
  - 43 tests U3.1 (EMB-P01..P36, EMB-F01..F07). PHPStan 0 errors. Pint clean.
  - `AIProviderInterface` permanece intacta (no break change).
  - FakeEmbeddingProvider determinístico para tests de U3.2+.
  - Provider stateless respecto al tenant.

## ADR-066 · Embedding Materialization Pipeline

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U3.2 materializa embeddings vectoriales para chunks de documentos
  knowledge. Los chunks están en estado `ready` (ingestion/chunking complete)
  con `embedding IS NULL`. Se necesita orquestar batch processing, persistencia
  segura con pgvector, idempotencia, retries, y tenant isolation.
- **Decisión**:
  - **Separate job**: `MaterializeKnowledgeEmbeddings` despachado desde
    `ProcessKnowledgeDocument` después de transición exitosa a `ready` con
    chunks > 0. Job independiente de la pipeline de procesamiento.
  - **Ready semantics**: `ready` = ingestion/chunking complete. NO significa
    embeddings complete. El estado de embedding se infiere:
    `embedding IS NULL` → pending, todos NOT NULL → materialized.
    No se agrega status nuevo.
  - **VectorSerializer**: VO que serializa `list<mixed>` a formato pgvector
    text `[0.1,0.2,...]`. Validación defense-in-depth: finite, count = 1536.
    Separado del provider para desacoplar serialización de llamada API.
  - **Persistencia**: `DB::update()` con `?::vector` parameterized binding.
    Input estrictamente validado por VectorSerializer. Nunca `DB::raw()` con
    interpolación.
  - **CAS (Compare-And-Swap)**: `WHERE embedding IS NULL` previene
    sobrescritura. Si update afecta 0 filas → otro worker ya materializó →
    no error.
  - **Batch DB transaction**: Embeddings se persisten en transacción por batch.
    Si DB falla → rollback completo. Retry re-encuentra chunks con NULL.
  - **Crash window**: Si provider responde pero worker muere antes de DB
    commit → retry rellama provider. Aceptar pequeño doble costo. No
    intentar distributed transaction con OpenAI.
  - **Lock Redis**: `lock:tenant:{id}:embeddings:{docId}:processing`.
    Patrón consistente con U2.4. Release en `finally`.
  - **ShouldBeUnique**: `embeddings:{tenantId}:{documentId}`, uniqueFor 600s.
    Tres capas: unique + lock + CAS.
  - **Retries**: tries=3, backoff=[30,60,120]. Rate limit, timeout, 5xx →
    retryable. Auth, invalid request, dimension mismatch → non-retryable.
  - **failed()**: NO cambia document.status (embedding es etapa separada).
    Documento permanece `ready`. Registra audit `knowledge_embeddings.failed`
    con error_code seguro.
  - **Delete guard**: Revalida documento activo (no deleted) antes de cada
    batch. Si deleted → STOP.
  - **Zero chunks**: Ready document con 0 chunks → no provider call, no error.
  - **Audit seguro**: `knowledge_embeddings.materialized` / `.failed` con
    document_id, knowledge_base_id, provider, model, chunks_processed,
    batches, input_tokens, duration_ms, success, error_code. Nunca
    chunk content, vectors, API key.
- **Consecuencias**:
  - 16 tests SQLite (EMB-MAT-01..12, EMB-JOB-01..10, EMB-MT-01..06).
  - 10 tests PostgreSQL (EMB-PG-01..10) en suite separada.
  - DDL = NONE. Config `knowledge.materialization` añadida.
  - Procesamiento de chunks y embeddings son etapas completamente separadas.
  - No se toca el pipeline de extracción/chunking (U2.4 intacto).

## ADR-067 · Semantic Search Service (FASE 17 U3.3)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U3.2 materializa embeddings para chunks knowledge. Se necesita
  búsqueda semántica tenant-scoped: query del usuario → embedding → pgvector
  cosine search → top-K resultados con threshold. Consumido internamente por
  U3.4 (AI Node RAG context injection). No expone HTTP ni toca FlowEngine.
- **Decisión**:
  - **KnowledgeSearchService**: caso de uso único `search(tenantId, kbId, query, topK?, threshold?)`.
    Pipeline: validate → resolve KB → embed query → pgvector SQL → threshold filter
    → top-K → context limit → KnowledgeSearchResult.
  - **Value Objects inmutables**: `RetrievedChunk` (chunkId, documentId, content, score,
    metadata) y `KnowledgeSearchResult` (query, chunks, totalCount, topK, threshold,
    searchDurationMs). Ambos readonly, constructor named params.
  - **pgvector cosine SQL parametrizada**: `1 - (embedding <=> ?::vector)` con binding
    parameterized. Nunca `DB::raw()` con interpolación de query vector. ORDER BY
    cosine ASC LIMIT hardLimit+1 (extra para threshold comparison).
  - **Threshold**: filtro post-query. `null` = sin filtro. `0.0..1.0` inclusive.
  - **Context limit**: `max_context_chars` (default 15000). No corta chunks a mitad.
    Detiene inclusión cuando siguiente chunk excede max_chars.
  - **SQLite compatibility**: guard `config('database.default') !== 'pgsql'` retorna
    empty KnowledgeSearchResult sin llamar embedding provider ni tocar DB.
    Mismo patrón que EmbeddingMaterializationService (U3.2).
  - **Tenant isolation**: KB resolution lleva `tenant_id` explícito + `withoutTenantScope()`
    (bypass TenantScope global que agrega `WHERE 1 = 0` sin TenantContext).
  - **Config**: `knowledge.search` (default_top_k: 5, hard_max_top_k: 20,
    default_threshold: null, max_query_length: 2000, max_context_chars: 15000).
- **Consecuencias**:
  - 14 tests SQLite (validation, config, SQL injection safety, empty result, metadata).
  - 17 tests PostgreSQL (cosine ranking, threshold, tenant isolation, context limit)
    en suite separada.
  - DDL = NONE. Config `knowledge.search` añadida.
  - No expone HTTP. Consumido por U3.4+ internamente.

## ADR-068 · RAG Context Injection into AI Node (FASE 17 U3.4)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U3.3 provee búsqueda semántica (`KnowledgeSearchService`). U3.4 integra
  los resultados como contexto no confiable en el prompt del nodo AI. El contexto RAG
  debe tratarse como **datos de terceros**: nunca contaminar el system prompt, nunca
  exponer scores/vectors, nunca fallar la ejecución AI si la búsqueda falla.
- **Decisión**:
  - **KnowledgeSearchServiceInterface**: interfaz minimalista `search()` extraída del
    servicio concreto. Permite inyección de dependencias y testing con fakes sin
    depender de la clase `final`.
  - **KnowledgeContext VO**: inmutable, transporta chunks + totalCount + searchDurationMs.
    `KnowledgeContext::empty()` factory. Sin embeddings vectors, sin API keys.
  - **Execution flow**: bot_paused → idempotency check → **resolve knowledge context**
    → build prompt → call AI → persist → log. RAG search ocurre DESPUÉS de idempotency
    (evita searches innecesarios en replay).
  - **Search failure policy**: continue without RAG (fail-open). Si KnowledgeSearchService
    lanza excepción → log warning → null context → AI ejecuta sin contexto RAG.
    Razón: RAG es enriquecimiento opcional, no debe bloquear funcionalidad core.
  - **Search query**: prompt USER resuelto por VariableResolver, truncado a 2000 chars.
    Variables `{{custom.*}}` se resuelven antes de la búsqueda semántica.
  - **Untrusted delimiter**: `--- KNOWLEDGE CONTEXT (UNTRUSTED DATA) ---` /
    `--- END KNOWLEDGE CONTEXT ---`. Chunks son texto plano, nunca se inyecta en
    system_prompt. Separación clara entre datos confiables (system) y no confiables.
  - **Prompt truncation**: MAX_PROMPT_LENGTH = 8000 chars. Knowledge context se incluye
    en el conteo total. No corta chunks a mitad.
  - **Telemetry**: `logAiCompleted()` incluye `rag_used` (bool) y
    `retrieved_chunks_count` (int). Nunca incluye chunk content, scores, vectors,
    ni searchDurationMs.
  - **FlowValidator**: `knowledge_base_id` validado como nullable string, formato UUID.
    Validación structural, no de existencia.
  - **No DDL**: `knowledge_base_id` vive en `flow_nodes.config` JSONB. No FK.
  - **No frontend**: U3.5 agregará selector de KB en AiNodeConfig.
- **Consecuencias**:
  - 31 tests nuevos: 15 feature (RAG-AI-01..15) + 8 unit (RAG-PROMPT-01..08) + 8 security (RAG-SEC-01..08).
  - Tests existentes actualizados (AI-SEC-F10, AI-U25, make_executor helpers en 5 archivos).
  - FakeKnowledgeSearchService para testing controlado sin PostgreSQL.
  - KnowledgeSearchService implementa KnowledgeSearchServiceInterface (additive, no break).
  - DDL = NONE. Config = NONE (search config ya existe de U3.3).

## ADR-069 · FAQ Data Model + Deterministic Normalization (FASE 18 U1)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: FASE 18 necesita un dominio FAQ para preguntas frecuentes curadas por el
  tenant con respuestas textuales deterministas. FAQ es independiente de Knowledge Base
  (FASE 17): no usa embeddings, no fuzzy matching, no AI, no semantic search. FAQ resuelve
  con matching exacto normalizado de bajo costo y latencia <1ms.
- **Decisión**:
  - **Tabla única `faqs`**: question (texto curado), normalized_question (representación
    canónica para matching + unicidad), answer (respuesta textual), status (active/inactive),
    priority (entero, mayor = primero). Sin hit_count (YAGNI, métricas en telemetría).
  - **Tenant ownership**: `tenant_id` FK directa + `BelongsToTenant` trait + scope global.
    FAQ A jamás lee FAQ B.
  - **Unique constraint**: `UNIQUE(tenant_id, normalized_question) WHERE deleted_at IS NULL`
    (PostgreSQL partial index). SQLite: unique index estándar (sin predicate). Soft-deleted
    FAQ permite recrear la misma pregunta.
  - **Normalization contract** (FaqQuestionNormalizer): trim → Unicode NFC → lowercase Unicode
    → remove edge punctuation (¿?!¡.,:;) → collapse whitespace. Preserva INTENCIONALMENTE
    acentos (á ≠ a), ñ, emoji. NO accent folding, NO stopwords.
  - **FaqStatus**: enum `active`/`inactive`. Sin draft/published. FAQ se crea y se activa.
  - **Soft deletes**: conservar historial para ejecuciones de flujos que referencien FAQ.
  - **Sin embeddings**: FAQ MVP es determinista. Si en futuro se necesita fuzzy/semantic, se
    agrega incrementalmente.
- **Consecuencias**:
  - DDL: tabla `faqs` con FK, partial unique index, soft deletes.
  - FaqQuestionNormalizer reutilizable por matcher (U2) y API (U3).
  - Tests: 12 normalizer + 17 model (SQLite) + 10 PostgreSQL = 39 tests nuevos.
  - Boundary claro: FAQ ≠ Knowledge Base ≠ AI/RAG.

## ADR-070 · FAQ Matching Semantics (FASE 18 U2)

- **Fecha**: 2026-08-19
- **Estado**: ACEPTADO
- **Contexto**: U1 estableció la tabla `faqs` con `normalized_question` y el normalizador
  `FaqQuestionNormalizer`. U2 necesita el servicio de matching que resuelve una pregunta
  entrante contra las FAQs almacenadas para un tenant. El matching debe ser determinista,
  tenant-scoped y read-only.
- **Decisión**:
  - **Matching exacto normalizado**: se normaliza el input con `FaqQuestionNormalizer` (misma
    función que U1) y se busca `WHERE normalized_question = ?`. Sin LIKE, ILIKE, Levenshtein,
    trigram, embedding ni semantic search.
  - **Reutilización del normalizer**: `FaqMatcherService` inyecta `FaqQuestionNormalizer` de U1.
    Sin duplicación de lógica de normalización.
  - **FaqMatch VO inmutable**: `final readonly` con `faqId`, `answer`, `matchType`,
    `priority`. Sin tenant_id, raw question, normalized question, PII, Eloquent model,
    ni hit_count (U1 decidió explícitamente YAGNI).
  - **matchType = 'exact_normalized'**: único tipo en MVP. Extensible para fuzzy/semantic
    futuro sin break change.
  - **Tenant isolation defense-in-depth**: query filtra `tenant_id` explícito además del
    scope global. `FaqMatcherService` recibe `Tenant` como parámetro (no depende de
    TenantContext implícito).
  - **Status filter**: solo `FaqStatus::Active`. Inactive no matchea.
  - **Soft delete filter**: `deleted_at IS NULL` implícito (Eloquent default). Sin
    `withTrashed()`.
  - **Deterministic ordering**: `priority DESC, created_at ASC, id ASC`. Protege
    extensiones futuras sin depender de orden físico.
  - **Read-only guarantee**: sin DB writes, sin `hit_count` increment, sin timestamps
    update, sin audit, sin telemetry.
  - **Interface**: `FaqMatcherServiceInterface` en `Application/Faq/Contracts/`.
    Justificada por U4 (FlowEngine tests necesitarán fake matcher). Consistente con
    `KnowledgeSearchServiceInterface` de FASE 17.
  - **Empty input**: si `normalize()` retorna `''`, retorna `null` sin query DB.
  - **Sin cache**: query indexada por `(tenant_id, status, normalized_question)` es
    suficiente. Sin Redis, sin optimization prematura.
- **Consecuencias**:
  - DDL: NONE (tabla `faqs` ya existe de U1).
  - Tests: 20 matcher (FAQ-MATCH-01..20) + 5 multi-tenancy (FAQ-MT-U2-01..05).
  - `FaqMatcherServiceInterface` listo para `FakeFaqMatcherService` en U4.
  - FlowEngine, MessageService, API, frontend: NO modificados en U2.

## ADR-071 · FAQ Runtime Integration — FlowEngine precedence and callback contract

- **Estado**: Aceptado · FASE 18 U4
- **Contexto**: La capa de FAQ (U1–U3) provee datos + API + matcher determinístico pero no
  interactúa con el pipeline de inbound messages. El motor de flujos (FlowEngine) procesa
  cada inbound bajo lock de conversación. Se necesita integrar FAQ como fallback cuando
  ningún flow matchea, sin duplicar locks, sin romper idempotencia existente y sin crear
  un segundo punto de decisión fuera del motor.
- **Decisión**:
  1. **Integration point**: FAQ se ejecuta como callback `onUnhandled` pasado al
     `FlowEngine::handleMessage()`. El callback corre **bajo el mismo lock de conversación**
     que el motor — una sola decisión atómica por inbound.
  2. **Precedence inside FlowEngine** (orden estricto, primero que retorne gana):
     a. `recoverCommittedHandoff` → handled: true
     b. `bot_paused` → handled: false, sin callback
     c. Active execution Running → handled: true, sin callback
     d. Active execution Waiting (question/buttons/delay) → handled: true, sin callback
     e. `matchFlow` → found → handled: true, sin callback
     f. No flow match → handled: false, **callback called**
  3. **created guard**: el job `ProcessIncomingWhatsAppMessage` solo pasa el callback
     cuando `$result->created === true` (el inbound fue persistido ahora, no es replay
     del mismo `provider_message_id`).
  4. **Fail-open**: cualquier excepción del matcher o de `createOutbound` se registra
     en log y se retorna sin romper el pipeline. FAQ es enrichment, no path crítico.
  5. **Defense-in-depth en FaqReplyService**: el servicio re-verifica `bot_paused`,
     `type === Text` y `body !== ''` antes de llamar al matcher. Si bien FlowEngine
     ya verificó `bot_paused`, la verificación defensiva protege contra invocation
     directa desde tests u otros puntos futuros.
  6. **Source attribution**: el outbound de FAQ incluye `faq_id` y `match_type` en
     `message.metadata`. Sin DDL nuevo (campo JSON ya existente).
  7. **Audit**: `faq.matched` con payload seguro: `tenant_id`, `conversation_id`,
     `message_id`, `faq_id`, `match_type`, `priority`. Sin PII, sin question, sin answer.
  8. **FlowHandleResult**: nuevo VO readonly con `bool $handled`. Retornado por
     `handleMessage()` en lugar de `void`. Backward-compatible (return value ignorado
     por callers existentes).
  9. **No second lock**: la lógica FAQ corre dentro del lock existente del FlowEngine.
     No hay competencia, no hay deadlock posible.
  10. **No AI calls**: en match FAQ, se llama 0 veces a AIProvider/EmbeddingProvider/
      KnowledgeSearchService. FAQ y RAG son capas independientes.
- **Consecuencias**:
  - FlowEngine `handleMessage()` cambia firma: `void` → `FlowHandleResult`, param
    `?callable $onUnhandled = null`. Todos los callers existentes usan el default
    `null` → backward-compatible.
  - ProcessIncomingWhatsAppMessage encapsula la creación del callback en closure.
    Resuelve `FaqReplyService` del container bajo request scope.
  - AppServiceProvider registra `FaqMatcherServiceInterface → FaqMatcherService`.
  - Tests: 10 precedence (FAQ-PREC-01..10), 7 idempotency (FAQ-IDEM-01..07),
    3 E2E (FAQ-E2E-01..03), 6 runtime MT (FAQ-RUNTIME-MT01..06),
    10 security (FAQ-SEC-U4-01..10) = 36 nuevos tests.

## ADR-072: Lead Data Model + Normalization (FASE 19 U1)

- **Fecha**: 2026-08-20
- **Estado**: ACEPTADO
- **Contexto**: FASE 19 establece un CRM básico multi-tenant para leads capturados
  manualmente. Los leads son independientes de contacts (sin FK). Se necesita
  data model, normalización de phone/email, status lifecycle, y indexes.
- **Decisión**:
  - **Tabla `leads`**: UUID PK, `tenant_id` FK cascadeOnDelete, `name` (VARCHAR 255,
    NOT NULL), `phone` (VARCHAR 30, nullable), `email` (VARCHAR 255, nullable),
    `status` (VARCHAR 20, default 'new'), `source` (VARCHAR 50, nullable),
    `notes` (TEXT, nullable), timestamps, soft deletes.
  - **LeadStatus enum**: `new`, `contacted`, `qualified`, `won`, `lost`. Lifecycle
    lineal simplificado. Enforcement de transiciones pertenece a U2.
  - **Phone normalization** (LeadPhoneNormalizer): reutiliza contrato de
    ContactService::normalizePhone(). trim → strip non-digits → prepend `+`.
    Representación canónica estilo internacional, NO validación E.164 completa.
  - **Email normalization** (LeadEmailNormalizer): trim → lowercase UTF-8. Sin
    plus-addressing strip, sin equivalencias provider-specific.
  - **Sin UNIQUE en phone/email**: la deduplicación es de aplicación (U2). Mismos
    datos pueden existir legítimamente (teléfonos compartidos, emails compartidos).
  - **Indexes**: `(tenant_id, status)`, `(tenant_id, created_at)`, `(tenant_id, phone)`
    partial PG, `(tenant_id, email)` partial PG. SQLite: standard indexes equivalentes.
  - **CHECK constraints (PG only)**: `status IN ('new','contacted','qualified','won','lost')`
    y `LENGTH(TRIM(name)) > 0`. SQLite: validación application-level.
  - **Soft deletes**: phone/email en registros deleted no afectan partial indexes.
    Dedup exclude soft-deleted (U2).
  - **Source**: VARCHAR nullable con vocabulary controlado (`manual`, `whatsapp`, `web`,
    `referral`, `other`). Sin tabla de sources, sin DB enum type.
  - **Manual-only boundary**: U1-U2 no implementan AI extraction, auto-create from
    WhatsApp, o Contact→Lead conversion.
- **Consecuencias**:
  - DDL: tabla `leads` con FK, CHECK constraints (PG), partial indexes (PG), standard
    indexes (SQLite). Migración condicional PG/SQLite.
  - 42 tests: 6 LeadStatus + 10 Phone + 8 Email + 17 Model + 12 PG.
  - LeadNotFoundException (404), LeadDuplicateException (409) preparados para U2.
  - LeadFactory con 5 status states + 3 nullable states.
  - Sin Service, Controller, API routes, permissions, o frontend en U1.

## ADR-073: Lead Application Service + API + Permissions (FASE 19 U2)

- **Fecha**: 2026-08-20
- **Estado**: ACEPTADO
- **Contexto**: FASE 19 U1 estableció data model + normalización para leads. U2
  implementa la capa de aplicación completa: service, controller, API, permissions,
  deduplicación, transiciones de estado, y auditoría.
- **Decisión**:
  - **LeadService**: casos de uso CRUD (index, show, create, update, delete).
    Normalización server-side vía LeadPhoneNormalizer/LeadEmailNormalizer.
    Deduplicación a nivel de aplicación (sin UNIQUE en DB) — phone o email duplicado
    dentro del mismo tenant → 409 LEAD_DUPLICATE. La dedup excluye soft-deleted.
    Transiciones de estado validadas vía LeadStatus.canTransitionTo() — transición
    inválida → 422 LEAD_INVALID_TRANSITION. Auditoría: lead.created/updated/deleted
    sin PII (solo tenant_id, status, source, changed).
  - **LeadStatus.canTransitionTo()**: lifecycle lineal — new→contacted,
    contacted→qualified/won/lost, qualified→won/lost, won→(terminal),
    lost→new (reabrir). Self-transition rechazada.
  - **LeadController**: controller delgado que delega a LeadService. Manejo de
    TenantMembershipException→404, PermissionDeniedException→403,
    TenantNotActiveException→409, LeadDuplicateException→409, DomainException→422.
  - **LeadResource**: JSON resource ocultando tenant_id y deleted_at.
  - **Requests**: LeadIndexRequest (search/status/source/per_page),
    StoreLeadRequest (name required, phone/email/status/source/notes optional),
    UpdateLeadRequest (todos nullable para PATCH). Email validación: `email` (sin dns).
  - **TenantPermission**: ViewLeads (owner/admin/agent), ManageLeads (owner/admin).
  - **Rutas**: tenant-scoped bajo `/api/v1/tenants/{tenant}/leads` — RESTful CRUD.
  - **Dedup rules**: phone normalizado duplicado → 409; email normalizado duplicado → 409;
    phone+email pueden ambos causar conflicto en la misma request.
  - **Sin scope creep**: no assigned_to, tags, scoring, kanban, imports, AI extraction,
    Contact→Lead, o FASE 20 features.
- **Consecuencias**:
  - 54 tests: 25 API (LEAD-API-01..25) + 13 transitions (LEAD-TRANS-01..13) +
    6 permissions (LEAD-PERM-01..06) + 10 MT (LEAD-MT-01..10) = 54 nuevos tests.
  - Total lead tests: 96 (42 U1 + 54 U2). FAQ regression: 161/161. Vitest: 302/302.
  - LeadService requires: AuthorizationService, AuditLogger, LeadPhoneNormalizer,
    LeadEmailNormalizer (4 dependencies via constructor injection).
   - PHPStan 0 errors after fixing Eloquent cast type inference via getAttribute().

## ADR-074: Lead Management Interface — Frontend (FASE 19 U3)

- **Estado**: Aceptado · FASE 19 U3
- **Contexto**: U2 implementó la API REST completa para leads (CRUD, transiciones, permisos).
  Se necesita una interfaz de usuario en Settings para que owners/admins gestionen leads
  y agents solo puedan visualizarlos.
- **Decisión**: Sigue el patrón CRUD de Settings/Faq.vue (inline table + modal, sin componentes
  compartidos). Módulo feature en `resources/js/features/leads/` con types, API, utils.
  - **LeadSettingsController**: Inertia render simple, devuelve prop lead vacío.
  - **Routes**: `/settings/leads` en web.php con middleware auth+verified.
  - **AppLayout nav**: link "Leads" en barra de navegación de settings.
  - **Feature module**: leadTypes.ts (tipos), leadApi.ts (CRUD fetch), leadUtils.ts (query builder,
    labels, colores, transiciones permitidas, whitelist de payload).
  - **Leads.vue**: tabla paginada, filtros (search/status/source), modal crear/editar,
    transiciones de estado via dropdown, confirmación de eliminación, UI basada en permisos.
  - **allowedLeadTransitions**: new→[contacted], contacted→[qualified,won,lost],
    qualified→[won,lost], lost→[new], won→[] (consistente con backend U2).
  - **Seguridad**: sin v-html con datos de usuario, whitelist de campos en payload,
    sin tenant_id en forms, permisos verificados en backend.
- **Consecuencias**:
  - 36 tests frontend: 28 leadUtils + 8 leadApi.
  - Total Vitest: 338/338. Typecheck: 0 errors. Vite build: clean.
  - Patrón de presentación reutilizable para futuros módulos CRUD de settings.

## ADR-075: Lead Hardening + Security Matrix + Cierre (FASE 19 U4)

- **Estado**: Aceptado · FASE 19 U4
- **Contexto**: FASE 19 U1-U3 implementaron el módulo Leads completo (data model, API, frontend).
  U4 realiza auditoría integral, hardening y cierre de fase.
- **Decisión**:
  - **Scope guard**: se confirma que Lead es CRM básico manual. No existen contact_id,
    conversation_id, assigned_to, tags, custom_fields, score, ai_metadata.
  - **Bug fixes** (3 items):
    - Duplicate `id="lead-source"` en Leads.vue (P1 HTML) → modal usa `id="lead-source-form"`.
    - Falta Escape key en modales (P1 a11y) → se agregó `@keydown.escape` en ambos overlays.
    - Placeholder de búsqueda inconsistente con backend → actualizado a incluir "notas".
  - **Security matrix**: 12 tests (LEAD-SEC-F01..F12): IDOR, tenant_id injection, mass assignment,
    SQL injection, XSS payload storage, PII audit, duplicate no-PII leak, invalid transitions
    → 422 (no 500), agent permissions, inactive membership (PG-only), soft delete, cross-tenant.
  - **E2E CRUD**: 7 tests (LEAD-E2E-01..07): full lifecycle, normalization, status transitions,
    duplicate 409, cross-tenant 404, agent read-only.
  - **Dedup race condition**: riesgo residual documentado. Sin UNIQUE DB, dos CREATE concurrentes
    con mismo phone/email pueden pasar el pre-check. Probabilidad baja (CRM manual), impacto bajo
    (lead duplicado, no corrupción). Mitigación futura: UNIQUE index o advisory lock.
  - **PostgreSQL tests (LEAD-PG-01..12)**: pendientes de ejecución (Docker daemon no disponible).
    Tests escritos y listos para ejecutar cuando PG real esté disponible.
  - **Security scan**: limpio — sin .env, keys, tokens, PII real, ni certificados.
  - **DDL**: NONE. No se requieren migraciones nuevas.
- **Consecuencias**:
  - 19 tests de seguridad + E2E (12 SEC + 7 E2E). Backend Lead total: 114 tests / 250 assertions.
  - Total Vitest: 338/338. PHPStan: 0 errors. Pint: clean.
  - FASE 19 declarada COMPLETADA.

## ADR-077 · Analytics Data Foundation (FASE 21 U1)

- **Estado**: Aceptado · FASE 21 U1
- **Contexto**: El SaaS necesita métricas de uso (mensajes, conversaciones, tokens AI, tiempos
  de respuesta) para dashboard de analytics. Sin una capa de datos aggregada, cada consulta
  tendría que escanear tablas transaccionales (messages, conversations, flow_executions) en
  tiempo real, lo cual es inviable a escala.
- **Decisión**: Dos tablas new, domain models, factories, y tests:
  - `analytics_daily`: tabla aggregada por tenant+date con contadores pre-calculados
    (total_messages, inbound/outbound, conversations_started/closed, avg_response_time_seconds,
    total_flow_executions, total_ai_tokens). PK UUID, UNIQUE(tenant_id,date), FK→tenants CASCADE.
    Todos los contadores INTEGER DEFAULT 0, total_ai_tokens BIGINT DEFAULT 0.
  - `conversation_metrics`: tabla por conversación con métricas acumuladas
    (total_messages, inbound/outbound, avg_response_time_ms, ai_tokens_used,
    first_response_at, last_message_at). PK UUID, composite FK(tenant_id,conversation_id)
    → conversations(tenant_id,id) CASCADE, UNIQUE(tenant_id,conversation_id).
  - `MetricGranularity` enum: Daily/Weekly/Monthly (BackedEnum).
  - AggregationService + AggregateDailyAnalyticsJob implementados en U2.
  - Schema cache (Redis) + API endpoint se implementan en U3.
  - Frontend dashboard + charts se implementan en U4.
- **Consecuencias**:
  - Migraciones verificadas en PostgreSQL 16 con UP/DOWN/UP.
  - 20 tests SQLite invariantes + 12 tests PostgreSQL reales.
  - tenant_id NOTfillable en ambos models (protección Anti-Exploration).
  - 0 PII almacenado en analytics (solo IDs de tenant/conversation, no phone/names).
  - ADR-076 (FASE 20 U4 Tags) precede este ADR.

## ADR-078 · Analytics Aggregation Service (FASE 21 U2)

- **Estado**: Aceptado · FASE 21 U2
- **Contexto**: U1 creó las tablas `analytics_daily` y `conversation_metrics` pero sin lógica
  de materialización. Se necesita un servicio que lea datos transaccionales y materialice
  aggregates diarios de forma idempotente, multi-tenant, y timezone-aware.
- **Decisión**:
  - `AggregationService`: servicio puro de aplicación (sin estado, sin cache en U2).
    - `aggregateForDate(Tenant, date)`: calcula window en tenant timezone, ejecuta 6 queries
      de conteo (messages, conversations, contacts, flow_executions, leads, AI tokens) + 1 query
      de conversation_metrics, y materializa en `analytics_daily` via DB::table UPSERT
      (no Model::updateOrCreate, por bug de date cast en SQLite).
    - `aggregateForTenant(tenantId, from, to)`: itera rango de fechas (máx 365 días).
    - TenantContext: se setea y restaura dentro de `aggregateForDate` para que
      `ConversationMetric` (BelongsToTenant) obtenga el tenant_id correcto.
    - Query SQL: parámetros posicionales (`?`) para compatibilidad PG (no mezclar named + positional).
  - `AggregateDailyAnalyticsJob`: Job ShouldBeUnique+TenantAwareJob, dispatch en cola `analytics`.
    - `uniqueId()`: `analytics:aggregate:{tenantId}:{date}` + `uniqueFor(300)`.
    - Cache::lock con 10s block, release en finally.
    - Tries=3, backoff=[30,60,120], timeout=300.
  - `AggregateDailyAnalyticsCommand`: `analytics:aggregate-daily [--date=]`.
    - Itera todos los tenants, dispatch job por tenant con fecha calculada en timezone del tenant.
  - Schedule: `dailyAt('02:00')` + `withoutOverlapping()`.
- **Consecuencias**:
  - 34 tests SQLite (AN-AGG-U01..16, AN-CM-01..10, AN-MT-U2-01..08) — todos verdes.
  - 13 tests Job/Command (AN-JOB-01..08, AN-CMD-01..05) — todos verdes.
  - 10 tests PostgreSQL (AN-PG-U2-01..10) — todos verdes.
  - Bug descubierto y corregido: `computeConversationMetrics` mezclaba parámetros named
    (`:tid`) y posicionales (`?`) en la query IN → fallo en PostgreSQL.
  - Bug descubierto y corregido: `AnalyticsDaily::updateOrCreate` falla en SQLite por
    date cast mismatch → solución: UPSERT manual via `DB::table`.
  - Cache de analytics (Redis) diferido a U3.
  - Frontend dashboard diferido a U4.

## ADR-079 · Analytics Overview API + Cache Semantics (FASE 21 U3)

- **Estado**: Aceptado · FASE 21 U3
- **Contexto**: U2 materializa datos diarios en `analytics_daily` pero no hay endpoint para
  consumirlos. Se necesita API REST legible, cacheada, con autorización granular y multi-tenant.
- **Decisión**:
  - `view_analytics` (`analytics.view`): permiso añadido a `TenantPermission`.
    Owner: YES, Admin: YES, Agent: NO (solo gestores ven métricas).
  - `AnalyticsService`: servicio de lectura (WRITE=AggregationService U2, READ=AnalyticsService U3).
    - `getOverview(User, Tenant, params)`: autoriza vía AuthorizationService, resuelve fechas
      en tenant timezone (default: últimos 30 días), consulta `analytics_daily` y
      `conversation_metrics` (para avg exacto), retorna `AnalyticsOverview` VO.
    - Cache: `Cache::remember("tenant:{id}:analytics:overview:{from}:{to}", 300s)`.
    - TenantContext save/restore dentro de la transacción de cache (misma defensa que U2).
  - Avg response time: AVG(response_time_seconds) de `conversation_metrics` en el rango,
    no AVG de promedios diarios (que sería incorrecto para pesos desiguales).
  - Daily series: fill missing days with zero (series continua para charts de U4).
    Empty range (sin rows) → daily: [].
  - `AnalyticsOverview`: VO readonly con `toArray()` — no Eloquent, no DTOs serializables.
  - `AnalyticsOverviewResource`: serializa VO a JSON. NO incluye tenant_id ni PII.
  - `OverviewRequest`: from/to nullable, date_format:Y-m-d, validación post: from<=to, max 365 días.
  - `AnalyticsController`: thin controller, patrón try/catch idéntico a FaqController/LeadController.
  - Route: `GET /api/v1/tenants/{tenant}/analytics/overview`.
  - Response shape: `{data: {period, messages, conversations, flows, leads, ai, daily}}`.
  - Cache invalidation: TTL 300s automático. No wildcard delete. Eventual consistency ≤5 min.
- **Consecuencias**:
  - 20 tests API (AN-API-01..13, AN-PERM-01..04, AN-MT-U3-01..08) — todos verdes.
  - 8 tests Cache (AN-CACHE-01..08) — todos verdes.
  - 122 analytics tests totales (U1+U2+U3 SQLite+PG) — todos verdes.
  - PHPStan 0 errors. Pint clean. Vitest 338 pass. vue-tsc pass. Vite build pass.
  - No new DDL, no new jobs, no frontend changes.

## ADR-080 · Analytics Dashboard — Frontend Visualization (FASE 21 U4)

- **Estado**: Aceptado · FASE 21 U4
- **Contexto**: U3 expone `GET /api/v1/tenants/{tenant}/analytics/overview` con datos agregados
  cacheados. No hay interfaz visual para consumirlo. Se necesita dashboard con stat cards,
  charts, presets de rango, y protección por permisos.
- **Decisión**:
  - **ApexCharts v6.10.0 + vue3-apexcharts v1.11.1**: única librería de charts. Lazy-loaded
    via Vite code splitting (chunk `Overview-BEE4JI_m.js` ≈ 977 KB con ApexCharts incluido).
  - **Feature module**: `resources/js/features/analytics/` con `analyticsTypes.ts`
    (tipos del contrato U3), `analyticsApi.ts` (fetchAnalyticsOverview), `analyticsUtils.ts`
    (safeRate, formatDuration, formatNumber, date presets, labels, extractErrorMessage).
  - **Components**: `StatCard.vue` (reutilizable), `MessageVolumeChart.vue` (area: inbound+outbound),
    `ConversationStatusChart.vue` (donut: open/resolved/archived), `LeadStatusChart.vue`
    (bar: new/won/lost), `FlowPerformanceChart.vue` (bar: completed/failed).
  - **Page**: `resources/js/Pages/Analytics/Overview.vue` — Inertia page en `/settings/analytics`.
    `AnalyticsSettingsController` + route en web.php + nav link en AppLayout.
  - **Presets**: 7d, 30d (default), 90d, custom (from/to inputs). Validación client-side
    (from<=to, max 365 días) antes de llamar API. Backend sigue siendo autoridad.
  - **Permission**: `analytics.view` verificado en frontend (hide UI) + backend (403).
    Owner/Admin ven dashboard completo. Agent ve "No tienes permiso".
  - **Loading**: skeleton animation (animate-pulse cards + chart placeholders).
    Error banner con "Reintentar" button. Empty state amigable cuando daily=[].
  - **Refresh manual**: button "Actualizar" re-petition API. Sin polling, sin WebSocket.
  - **Cache semantics UX**: texto "Datos agregados · {from} — {to}". Sin TTL expuesto.
  - **Stat cards**: 4 cards — Mensajes totales, Conversaciones activas, Conversión de leads
    (won/total), Finalización de flujos (completed/total). Denominator=0 → 0%, nunca NaN.
    Avg response time como subtitle de Conversaciones.
  - **Responsive**: grid 4 cols desktop, 2 tablet, 1 mobile. Charts 2-col → 1-col.
    Chart height 300px, width 100%.
  - **Security**: sin PII, sin v-html, sin console.log, sin tenant_id injection,
    sin IDs internos. Solo aggregate counters.
  - **Dark mode**: app no soporta → no se implementa.
- **Consecuencias**:
  - 61 tests frontend nuevos: 34 analyticsUtils + 7 analyticsApi + 3 StatCard + 17 dashboard.
  - Total Vitest: 399/399. Typecheck: 0 errors. Vite build: pass.
  - Backend regression: 100 analytics tests pass. PHPStan 0 errors. Pint clean.
  - composer audit: no vulnerabilities. npm audit: 0 vulnerabilities.
  - Bundle: app-DzPLfDgx.js → 247 KB + Overview chunk 977 KB (ApexCharts lazy-loaded).
  - FASE 21 U4 completada. U5 (hardening) pendiente.

## ADR-081 · Analytics Security Hardening + Closure (FASE 21 U5)

- **Estado**: Aceptado · FASE 21 U5
- **Contexto**: FASE 21 U1-U4 implementaron analytics completo (data model, aggregation, API,
  dashboard). U5 realiza auditoría integral de seguridad, tenant isolation, cache isolation,
  y cierre formal de la fase.
- **Decisión**:
  - **Auth-before-cache (AN-SEC-F05)**: AnalyticsService ejecuta authorize() ANTES de
    Cache::remember. Un agent denied en request 2 NO recibe cache generado por owner en request 1.
    Test explícito verifica que Cache::remember callback NO se ejecuta cuando auth falla.
  - **Response no PII (AN-SEC-F07)**: Response JSON contiene solo aggregate counters.
    Verificado: no tenant_id, no contact_id, no conversation_id, no phone, no email,
    no cache keys, no internal audit data.
  - **Aggregation no PII (AN-SEC-F08)**: AnalyticsDaily almacena solo columnas numéricas.
    ConversationMetric almacena solo counts y timestamps, nunca message body, prompt,
    response, phone, email, o API key.
  - **AI telemetry safe (AN-SEC-F09)**: computeAiTokens extrae solo payload->>'total_tokens'.
    No accede a prompt, response, content, api_key, contact, phone, email.
    Verificado vía ReflectionMethod sobre el source code.
  - **Concurrent aggregation (AN-SEC-F12)**: doble aggregateForDate produce 1 sola row.
    UPSERT manual + UNIQUE(tenant_id, date) garantiza idempotencia.
  - **Baseline verification**: 6 pre-existing HandoffFinalTest/HandoffRuntimeTest failures
    confirmados en origin/master (TypeError en TagNodeExecutor, no relacionado con FASE 21).
  - **Security scan**: 0 .env, 0 API keys, 0 tokens, 0 passwords, 0 PII fixtures.
  - **No bugs P0/P1/P2 found**: auditoría integral sin correcciones necesarias.
- **Consecuencias**:
  - 8 security matrix tests (AN-SEC-F05, F07a, F07b, F07c, F08a, F08b, F09, F12).
  - Total analytics tests: 108 (U1:20 + U2:47 + U3:33 + U4:64 + U5:8 → ajustado: 108 Backend).
  - Total Vitest: 399/399. PHPStan: 0 errors. Pint: 615 files clean.
  - composer audit: no vulnerabilities. npm audit: 0 vulnerabilities.
  - PG tests: 22 pass. Redis cache isolation verified.
  - FASE 21 declarada COMPLETADA. FASE 22 NO iniciada.

## ADR-082 · Notification Data Model + Domain Foundation (FASE 22 U1)

- **Estado**: Aceptado · FASE 22 U1
- **Contexto**: FASE 21 (Analytics) completada. FASE 22 es "Notificaciones" — items diferidos
  de ADR-051 (semántica terminal de handoff), ADR-053 (frontera realtime del Inbox para handoff),
  y FASE 15 U4 (notifications para handoff requests). Actualmente `Domain/Notifications/` existe
  pero está vacío. No hay tabla `notifications`, ni modelo, ni fábrica. El sistema notifica
  solo vía eventos in-process (InboxConversationChanged) sin persistencia.
- **Decisión**:
  - **Tabla `notifications`**: UUID PK, `tenant_id` FK CASCADE, `user_id` nullable FK SET NULL,
    `type` VARCHAR(100), `priority` VARCHAR(20) default 'normal', `title` VARCHAR(255),
    `body` TEXT, `data` JSON nullable, `read_at` nullable timestamp, `deleted_at` (SoftDeletes).
    Índices: `(tenant_id, user_id, read_at)`, `(tenant_id, created_at)`, `(tenant_id, type)`.
  - **SoftDeletes**: sí — usuario puede descartar; preserva trail de auditoría.
  - **user_id nullable**: sí — soporta dirigido (user_id != NULL) y tenant-wide (user_id = NULL).
  - **User FK**: SET NULL on delete — preservar historial.
  - **Tenant FK**: CASCADE on delete — datos de tenant mueren con el tenant.
  - **Sin unique constraint** en type+user+conversation — múltiples notificaciones legítimas.
  - **No unique constraint on (type, user_id, conversation_id)**: múltiples notificaciones del
    mismo tipo para la misma conversación y usuario son legítimas (ej: handoff rechazado →
    handoff aceptado).
  - **NotificationType enum**: HandoffRequested, ConversationAssigned, ConversationClaimed, System.
  - **NotificationPriority enum**: Low, Normal, High.
  - **Model Notification**: BelongsToTenant, HasUuids, HasFactory, SoftDeletes, con relaciones
    tenant()/user() e helpers isRead()/markAsRead().
  - **Data JSON**: metadata segura únicamente (conversation_id, agent_id, event type, route hints).
    NO PII (nombres, teléfonos, emails, bodies de mensaje).
  - **title/body**: texto plano únicamente.
  - **Preferences table**: DEFERRED a U4 (no existe storage actual).
  - **Push/Realtime/Email/Frontend**: DEFERIDOS a sub-unidades futuras (U2–U5).
  - **Alcance estricto**: U1 = data model + domain foundation únicamente. NO API,
    NO listeners, NO email, NO realtime, NO frontend, NO Redis, NO push.
- **Consecuencias**:
  - 3 archivos PHP nuevos: NotificationType, NotificationPriority, Notification model.
  - 1 factory (NotificationFactory) + 1 migración.
  - 19 tests SQLite (15 model + 4 enum), 12 tests PostgreSQL.
  - PHPStan 0 errors. Pint clean. composer audit clean.
  - Vitest 399/399. Typecheck 0 errors. Vite build pass.
  - Baseline: 6 pre-existing HandoffFinalTest/HandoffRuntimeTest failures (no relacionado).
  - FASE 22 U1 declarada DONE. U2 (Event Listeners + Notification Dispatch) pendiente.

## ADR-083 · Listener TenantContext Save/Restore (FASE 22 U2)

- **Estado**: Aceptado · FASE 22 U2
- **Contexto**: `CreateNotificationFromInboxChange` es un listener síncrono para
  `InboxConversationChanged`. Cuando se dispara dentro de una operación que ya tiene
  TenantContext activo (ej: `ConversationService::assign()` → dispatch → listener),
  el listener necesitaba `TenantContext::setId()` para crear notificaciones y
  `TenantContext::clear()` al finalizar. El `clear()` destruía el contexto del caller,
  causando `TenantContextMissingException` en operaciones subsiguientes dentro del
  mismo request (ej: `ConversationService::transfer()` después de `assign()`).
- **Decisión**: El listener **guarda y restaura** el TenantContext previo en vez de
  limpiarlo. Patrón:
  ```php
  $previousTenantId = TenantContext::id();
  TenantContext::setId($event->tenant->id);
  try { /* crear notificaciones */ }
  finally {
      if ($previousTenantId !== null) {
          TenantContext::setId($previousTenantId);
      } else {
          TenantContext::clear();
      }
  }
  ```
  Esto preserva el TenantContext del caller cuando el listener corre síncronamente
  en el mismo proceso, y limpió correctamente cuando el listener es el primero en
  establecer contexto (caller no tenía uno).
- **Consecuencias**:
  - Sin efectos secundarios en listeners asíncronos/queued (el restore afecta solo
    al mismo proceso/thread).
  - Patrón reutilizable para futuros listeners síncronos que manipulen TenantContext.
  - Los tests de integración (assign→transfer, claim→transfer) ahora pasan sin
    necesidad de re-establecer TenantContext manualmente en el test.
  - Los tests que llaman directamente al NotificationService (sin listener) siguen
    necesitando `TenantContext::setId()` explícito antes de la llamada.

## ADR-084 · Personal Notification Inbox + Read-State Semantics (FASE 22 U3)

- **Estado**: Aceptado · FASE 22 U3
- **Contexto**: FASE 22 U1 creó el modelo de notificaciones y U2 dispatch events into
  notifications. However, users have no way to view or manage their notifications. The
  inbox needs to be personal (each user sees only their own), support read/unread filtering,
  paginated listing, and idempotent mark-as-read. Two operations exist: individual
  mark-read (CAS on single row) and bulk mark-all-read.
- **Decisión**:
  - **Endpoint pattern**: Three endpoints — `GET /notifications` (index), `PATCH
    /notifications/{notification}/read` (mark single read), `POST /notifications/read-all`
    (mark all read). These follow the existing tenant-scoped controller pattern
    (FaqController, TagController).
  - **Permission**: New `ViewNotifications = 'notifications.view'` added to `TenantPermission`
    enum. Assigned to Owner, Admin, and Agent roles (all active members). No
    `ManageNotifications` — mark-read is personal inbox, not admin.
  - **Ownership enforcement**: Every query includes `where('user_id', $userId)` and
    `where('tenant_id', $tenantId)`. Users can only see/interact with their own
    notifications. Cross-user access returns 404.
  - **CAS-style markRead**: `UPDATE notifications SET read_at = now() WHERE id = ? AND
    read_at IS NULL AND tenant_id = ? AND user_id = ?`. Returns affected row count.
    Idempotent: second call affects 0 rows. No row-level locks needed — the WHERE clause
    prevents double-read at the SQL level.
  - **markAllRead**: Same CAS pattern but over all unread notifications for the user.
    Returns count of newly-read notifications.
  - **Index response shape**: `{notifications: [...], meta: {current_page, last_page,
    per_page, total}, counts: {unread: N}}`. The `unread` count is computed independently
    of any filter.
  - **Resource safety**: `NotificationResource` does NOT expose `tenant_id` or `user_id`.
    Exposes: id, type, priority, title, body, data, read_at, created_at.
  - **No audit for mark-read**: Marking a notification as read is UI state, not a
    business action. Audit logs from notification creation (U2) are retained.
  - **Tenant-wide rows** (`user_id = NULL`): Supported by schema but excluded from
    personal inbox queries in this MVP. Reserved for future system-wide notifications.
- **Consecuencias**:
  - Clean separation: U2 dispatches, U3 consumes. NotificationService handles both creation
    (U2) and read-state management (U3).
  - CAS semantics make the endpoints safe under concurrent requests without explicit locks.
  - The `counts.unread` in the index response supports badge indicators in the frontend.
  - Future tenant-wide notifications (system announcements) can leverage the existing
    `user_id = NULL` column with a separate query path.

## ADR-085 · Notification Multi-Tenancy Middleware Behavior (FASE 22 U3)

- **Estado**: Aceptado · FASE 22 U3
- **Contexto**: Cross-tenant access to notification endpoints needed clarification.
  The existing `TenantMiddleware` returns 404 (via `TenantMembershipException` →
  `NotFoundHttpException`) when the user is not a member of the tenant in the URL.
  This differs from explicit 403 PERMISSION_DENIED in service-level authorization.
- **Decisión**: The notification endpoints follow the same middleware behavior as all
  other tenant-scoped endpoints:
  - User not member of `{tenant}` in URL → **404** (via `TenantMiddleware`)
  - User member but lacks `notifications.view` permission → **403** `PERMISSION_DENIED`
  - Notification exists but belongs to different user → **404** (service-level, hides existence)
  - Notification does not exist → **404**
- **Consecuencias**:
  - Consistent with all other modules (contacts, conversations, etc.)
  - No information leakage about which tenants or notifications exist
  - Test assertions use `assertNotFound()` for cross-tenant (not `assertForbidden()`)

## ADR-086 · Notification Email Preferences + Handoff Email Semantics (FASE 22 U4)

- **Estado**: Aceptado · FASE 22 U4
- **Contexto**: FASE 22 U1–U3 created the in-app notification system. Users can view and manage
  their in-app notifications, but there is no way to receive email alerts for critical events.
  HandoffRequested events require immediate human attention, but users only discover them
  by actively checking the inbox. We need email notification support with per-user control.
- **Decisión**:
  - **Preference storage**: `email_notifications_enabled` BOOLEAN column on `tenant_users`
    pivot table. This is per-user-per-tenant (a user can have different preferences in
    Tenant A vs Tenant B). No new table — extends the existing membership model.
  - **DDL**: `ALTER TABLE tenant_users ADD COLUMN email_notifications_enabled
    BOOLEAN NOT NULL DEFAULT false`. Down: drop column.
  - **Default**: `false` (no existing email contract; avoids surprise spam until users
    explicitly opt in). Documented in ADR-086.
  - **Events that send email**: ONLY `HandoffRequested`. No email for ConversationAssigned,
    Transferred, Claimed. Minimal spam surface.
  - **Email targets**: Owners and Admins with `email_notifications_enabled = true` and valid
    email. Agents NEVER receive email (ADR-086).
  - **Inactive membership**: Excluded (query filters `status = active`).
  - **Cross-tenant**: Each membership is independent. User enabled on Tenant A does not
    receive email for Tenant B.
  - **Mail notification**: `HandoffRequestMailNotification` in
    `app/Domain/Users/Notifications/`. `final class`, `ShouldQueue`, `MailMessage` standard
    (no Blade custom). Spanish copy, generic content ("Hay una conversación que requiere
    atención humana"), no PII (phone, contact name, message body, AI content).
  - **Preference API**: `GET/PATCH /api/v1/tenants/{tenant}/notification-preferences`.
    Single field: `email_notifications_enabled`. Only modifies the authenticated user's
    own preference. No `user_id`/`tenant_id` in body. No new permission — any active member
    (owner/admin/agent) can modify their own preference.
  - **Queue + afterCommit**: Mail dispatched via `Notification::route('mail', ...)` inside
    the existing `CreateNotificationFromInboxChange` listener (already afterCommit from
    upstream event). `ShouldQueue` ensures async processing.
  - **Idempotency/replay**: Mail idempotency follows upstream event idempotency (HandoffService).
    No artificial DDL for email dedup. Residual replay risk documented.
  - **Audit**: `notification_preferences.updated` for preference changes (not PII). No per-email
    audit (queue/log covers delivery).
- **Consecuencias**:
  - Users control their own email notifications per tenant, reducing spam.
  - Only critical handoff events trigger email; non-critical events stay in-app only.
  - No frontend for preferences in U4 (API-only). Frontend can be added later.
  - The `email_notifications_enabled` column defaults to false, ensuring zero surprise emails
    on existing memberships.

## ADR-087 · Realtime Personal Notification Delivery + Frontend Notification Center (FASE 22 U5)

- **Estado**: Aceptado · FASE 22 U5
- **Contexto**: U1-U4 implementaron notificaciones in-app, inbox API, read-state y preferencias
  email. Falta entrega en tiempo real y frontend para que el usuario vea notificaciones
  sin polling.
- **Decisión**:
  - **Personal channel**: `private-tenant.{tenantId}.users.{userId}.notifications` — canal
    privado por usuario, no tenant-wide. Cada usuario solo puede suscribirse a su propio canal.
  - **Channel auth**:双重验证: `user.id === userId` AND `user.belongsToTenantById(tenantId)`.
    Un usuario jamás puede suscribirse al canal de otro usuario, ni de otro tenant.
  - **NotificationCreated event**: implementa `ShouldBroadcast`, `afterCommit = true`. Se despacha
    después de crear cada notificación en `NotificationService::createNotification()`.
  - **Broadcast payload**: usa `NotificationResource` (sin tenant_id, user_id, PII). Solo campos
    públicos: id, type, priority, title, body, data, read_at, created_at.
  - **Unread count endpoint**: `GET /api/v1/tenants/{tenant}/notifications/unread-count` — ligero,
    sin carga de listado. Ubicado ANTES de `{notification}` para evitar conflicto de routing.
  - **Frontend module**: `features/notifications/` con types, API client, utils puros, composable
    `useNotificationChannel` (subscribe/listen/cleanup). Patrón idéntico a `useInboxChannel`.
  - **NotificationBell**: ícono campana + badge unread en el header de `AppLayout`. Click abre
    `NotificationCenter` (dropdown). Integrado solo cuando `currentTenantId !== null`.
  - **NotificationCenter**: dropdown compacto con listado, mark-read individual, mark-all-read,
    "cargar más", loading/error/empty states. Escape para cerrar.
  - **NotificationPreferenceToggle**: integrado en Dashboard. Solo visible para owner/admin
    (agent no tiene email efectivo). Toggle con loading/disabled/error states.
  - **Tenant switch**: al cambiar de tenant, el composable abandona el canal anterior, limpia
    notificaciones locales y se suscribe al nuevo canal. No hay fuga de datos entre tenants.
  - **Dedup**: eventos duplicados se ignoran por `id` (Set cap en 500). Prepend sin duplicados.
  - **No realtime read-state**: el broadcast solo notifica creación. Marcar leído es solo HTTP.
  - **No full notifications page**: bell + dropdown es MVP suficiente.
  - **Tests**: 12 backend broadcast tests (event, channel, auth, payload safety, service dispatch),
    4 routing tests, 29 frontend utils tests, 12 realtime composable tests.
- **Consecuencias**:
  - El usuario recibe notificaciones al instante sin refrescar la página.
  - El canal personal aísla completamente las notificaciones de cada usuario.
  - Tenant switch limpio: no hay contaminación cruzada de notificaciones.
  - La UI de preferencias email se expone solo a roles que tienen efecto real.
  - No se implementan push notifications, SMS, ni integraciones externas.

## ADR-088 · Plan & Subscription Data Foundation (FASE 23 U1)

- **Estado**: Aceptado · FASE 23 U1
- **Contexto**: FASE 22 (Notificaciones) completada. FASE 23 es "Planes" — base de datos para
  plan de suscripción, catálogo de planes globales, suscripciones tenant-scoped, items de
  suscripción y registro de uso append-only. FASE 24 será Stripe/Billing y FASE 25 UsageGuard.
- **Decisión**:
  - **Plan es GLOBAL** (sin `tenant_id`): catálogo compartido por todos los tenants.
    Managed por super_admin. Tabla `plans`: uuid PK, slug UNIQUE, name, description, is_active,
    price_monthly/price_yearly (decimal:2), limits JSON (quota por categoría), features JSON
    (feature flags), sort_order, timestamps, softDeletes.
  - **Subscription es TENANT-SCOPED**: source of truth para plan assignment del tenant.
    Tabla `subscriptions`: uuid PK, tenant_id FK CASCADE (BelongsToTenant), plan_id FK NULL,
    status (SubscriptionStatus enum: active|cancelled), quantity int, current_period_start/end,
    metadata JSON (append-only), timestamps, softDeletes.
  - **One active subscription per tenant**: UNIQUE parcial `(tenant_id) WHERE deleted_at IS NULL`.
    Soft deletes permite plan changes (cancelled old + new active).
  - **SubscriptionItem es TENANT-SCOPED**: categoría de uso + quota incluida + precio unitario.
    Tabla `subscription_items`: uuid PK, tenant_id FK CASCADE, subscription_id FK CASCADE,
    category (UsageCategory enum), included_usage int, per_unit_price, timestamps.
    UNIQUE parcial `(subscription_id, category) WHERE deleted_at IS NULL`.
  - **UsageRecord es TENANT-SCOPED + append-only**: ledger inmutable de consumo.
    Tabla `usage_records`: uuid PK, tenant_id FK CASCADE, subscription_id FK NULL,
    category (UsageCategory), quantity int, description nullable, metadata JSON,
    recorded_at datetime, timestamps. NO soft deletes (inmutable).
  - **tenants.plan_id FK**: `nullOnDelete` — si se elimina plan, tenant va a NULL.
    Denormalized cache de la relación active subscription.
  - **SubscriptionStatus enum**: `active`, `cancelled` en U1 (FASE 24 extiende con
    trialing, past_due, expired).
  - **PlanInterval enum**: `monthly`, `yearly` (FASE 24 lo usa).
  - **UsageCategory enum**: `messages`, `ai_tokens`, `contacts`, `flow_executions`,
    `users`, `knowledge_documents`.
  - **PlanSeeder**: solo plan `free` (no hay planes definidos en specs contractuales).
    Idempotente via `updateOrCreate` por slug.
  - **Plan model methods**: `getLimit(string $category): ?int`, `hasFeature(string $feature): bool`.
  - **Subscription model methods**: `isActive(): bool`.
  - **Factories**: PlanFactory, SubscriptionFactory (con states: active, cancelled),
    SubscriptionItemFactory, UsageRecordFactory.
  - **BelongsToTenant en Subscription/SubscriptionItem/UsageRecord**: tenant_id auto-asignado
    desde TenantContext. TenantContextMissingException si context no está activo.
  - **Billing permissions**: `ViewBilling` → owner+admin, `ManageBilling` → solo owner.
    Agent no tiene acceso a billing.
  - **Tests**: 52 tests (BILL-ENUM-01..09, BILL-DOM-01..25, BILL-MT-01..08, BILL-SEC-01..10).
- **Consecuencias**:
  - El catálogo de planes es global — un solo super_admin gestiona pricing.
  - La suscripción es la source of truth; tenants.plan_id es cache denormalizado.
  - UsageRecord append-only preserva historial completo para auditoría y facturación.
  - FASE 24 (Stripe) extiende sin breaking changes: agrega facturas, invoices, webhooks.
  - FASE 25 (UsageGuard) consume UsageRecord para enforce quotas del plan.
  - Plan free como base garantiza que todo tenant tiene un plan válido desde el inicio.

## ADR-089 · Append-Only Usage Metering Infrastructure (FASE 23 U2)

- **Estado**: Aceptado · FASE 23 U2
- **Contexto**: U1 estableció el data model (Plan, Subscription, SubscriptionItem, UsageRecord).
  U2 implementa la capa de aplicación: registro de uso, cómputo de periodos, resumen por
  categoría e historial paginado. Requisitos: append-only, sin update/delete expuestos,
  multi-tenant, periodos flexibles, metadata sanitizada.
- **Decisión**:
  - **UsageTrackingService** (`app/Application/Billing/Services/UsageTrackingService.php`):
    servicio `final` de aplicación, interno (sin rutas HTTP). Métodos: `record()`,
    `currentPeriodUsage()`, `currentPeriodSummary()`, `history()`.
  - **Append-only ledger**: solo INSERT + SELECT + SUM. Sin UPDATE/DELETE expuestos.
    UsageRecord ES el ledger completo; no hay tabla auxiliar ni AuditLog por registro.
  - **Subscription resolution server-side**: `Subscription::where(status=Active)->latest()->first()`.
    El caller NUNCA controla la suscripción contra la que se registra uso.
  - **Period boundaries [start, end)**: start inclusive, end exclusive. Si subscription tiene
    `current_period_start` y `current_period_end`, usa esos. Si no: fallback a calendar month UTC.
  - **null limit = unlimited**: `Plan::getLimit()` retorna `?int`. null = sin límite.
    No se usan magic numbers (999999). `UsageCategorySummary.remaining` es null cuando limit es null.
  - **Metadata whitelist**: solo `message_id`, `conversation_id`, `flow_execution_id`,
    `knowledge_document_id`, `source`. Cualquier otra clave se elimina silenciosamente.
    NO hay PII, NO hay phone, NO hay email.
  - **Sin Redis, sin cache, sin batching**: PostgreSQL es source of truth directo.
    Performance suficiente para volúmenes iniciales. Optimización futura (Redis counters)
    será transparente al caller.
  - **Value Objects**: `UsageCategorySummary` (used, limit, remaining), `UsageSummary`
    (subscriptionId, periodStart, periodEnd, categories).
  - **Exceptions**: `SubscriptionNotFoundException`, `InvalidUsageQuantityException`.
  - **History**: paginado (`LengthAwarePaginator`), ordenado por `recorded_at DESC, id DESC`.
    Filtros: category, from, to, per_page.
  - **Tests**: 36 tests U2 (BILL-USG-01..20, BILL-PERIOD-01..06, BILL-MT-U2-01..06,
    BILL-USG-SEC-01..07, BILL-USG-CONC-01). Total FASE 23: 88 tests.
  - **Unique constraint**: `usage_records_unique_per_period` en
    `(tenant_id, subscription_id, category, recorded_at)` previene duplicados.
- **Consecuencias**:
  - El servicio es la ÚNICA interfaz para registrar consumo. No hay way-around.
  - Append-only garantiza auditoría completa: cada registro es inmutable.
  - null limit permite planes sin cuotas (ilimitados) sin wrappers especiales.
  - Metadata whitelist previene PII leakage silenciosamente (no exception, solo strip).
  - FASE 25 (UsageGuard) consume `currentPeriodSummary()` para enforce quotas.
  - FASE 24 (Stripe) puede extender con facturación basada en el mismo ledger.

## ADR-090 · Billing API Layer (FASE 23 U3)

- **Estado**: Aceptado · FASE 23 U3
- **Contexto**: U1 estableció el data model (Plan, Subscription, SubscriptionItem, UsageRecord).
  U2 implementó UsageTrackingService (record, summary, history). U3 expone la capa HTTP:
  catálogo de planes, gestión de suscripciones y endpoints de uso. Controllers thin,
  services de aplicación, AuthorizationService (no Policies), patrón FaqController.
- **Decisión**:
  - **7 endpoints** bajo `{tenant}/`:
    - `GET plans` (billing.view) — lista planes activos globales
    - `GET plans/{plan}` (billing.view) — detalle de plan
    - `GET subscriptions` (billing.view) — suscripción activa del tenant
    - `POST subscriptions` (billing.manage) — crear/reemplazar suscripción
    - `PATCH subscriptions` (billing.manage) — cambiar plan de suscripción
    - `DELETE subscriptions` (billing.manage) — cancelar suscripción (status=cancelled)
    - `GET usage` (billing.view) — resumen de uso del periodo actual
    - `GET usage/history` (billing.view) — historial paginado de uso
  - **PlanController**: delega a `SubscriptionService::listPlans()` y `showPlan()`.
    `@mixin Plan` en Resource para PHPStan property resolution.
  - **SubscriptionController**: delega a `SubscriptionService::assignPlan()`,
    `changePlan()`, `cancel()`, `currentSubscription()`. Store excluye tenant_id
    existente; update solo acepta `plan_id`. DELETE usa `cancelled` (U1 define el
    estado).
  - **UsageController**: reutiliza `UsageTrackingService::currentPeriodSummary()` y
    `history()`. Inyecta `AuthorizationService` directamente (no a través de service
    de dominio). 404 cuando no hay suscripción activa.
  - **SubscriptionService** (`app/Application/Billing/Services/SubscriptionService.php`):
    servicio `final` de aplicación. Inyecta `AuthorizationService` + `AuditLogger`.
    `assignPlan()` cancela soft la existente en la misma transacción y crea la nueva;
    sincroniza `tenants.plan_id` (denormalized cache). `changePlan()` valida plan
    diferente; si es el mismo, es no-op. `cancel()` soft-delete + status=cancelled
    + limpia plan_id.
  - **FormRequests**: `StoreSubscriptionRequest` (plan_id required uuid),
    `UpdateSubscriptionRequest` (plan_id required uuid). Autorización en service, no
    en request.
  - **Resources**: `PlanResource`, `SubscriptionResource`, `UsageSummaryResource`
    (acepta UsageSummary VO), `UsageRecordResource`. Todos sin tenant_id en respuesta.
  - **Exceptions**: `PlanNotFoundException`, `SubscriptionNotActiveException`.
  - **PlanSeeder registrado** en `DatabaseSeeder` (idempotente via updateOrCreate).
  - **AuthorizationService pattern**: owner+admin tienen ViewBilling; solo owner tiene
    ManageBilling. Admin NO puede crear/cambiar/cancelar suscripción (solo ve).
    Non-member → TenantMiddleware retorna 403.
  - **Tests**: 45 tests nuevos U3 (BILL-API-PLAN-01..05, BILL-API-SUB-01..11,
    BILL-API-USG-01..08, BILL-API-PERM-01..10, BILL-API-MT-U3-01..05,
    BILL-API-SEC-U3-01..06). Total FASE 23: 133 tests.
  - **Quality gates**: PHPStan 0 errors, Pint fixed (8 style issues), composer audit
    0 vulnerabilities. PG: 133/133 pass. Regression: 0 new failures (6 pre-existing
    Handoff failures).
- **Consecuencias**:
  - API REST completa para billing. Controllers thin, services orquestan toda lógica.
  - Plan es global (no tenant-scoped); Subscription y UsageRecord son tenant-scoped.
  - DELETE/cancel es válido: SubscriptionStatus::cancelled existe en U1.
  - Admin tiene ViewBilling pero NO ManageBilling (decisión de diseño, no un bug).
  - FASE 24 (Stripe) extiende con facturación y webhooks; FASE 25 (UsageGuard) consume
    `currentPeriodSummary()` para enforce quotas.

## ADR-091 · Billing Frontend (FASE 23 U4)

- **Estado**: Aceptado · FASE 23 U4
- **Contexto**: U1-U3 establecieron el data model, UsageTrackingService y la API layer de
  billing (7 endpoints REST). No existe interfaz visual. El usuario autorizó U4 como
  "Billing Frontend" con restricción explícita: NO Stripe, NO Checkout, NO PaymentIntent,
  NO tarjetas, NO facturas reales, NO UsageGuard, NO backend DDL nuevo.
- **Decisión**:
  - **Controller thin** `BillingSettingsController` (Inertia, verified+tenant): pasan user,
    roles, permissions al componente `Settings/Billing.vue`.
  - **Módulo `features/billing/`**: `billingTypes.ts` (interfaces TS: Plan, PlanLimits,
    Subscription, SubscriptionPlan, UsageCategorySummary, UsageSummary, UsageRecord,
    UsageHistoryMeta, BillingActionState), `billingApi.ts` (7 wrappers sobre window.axios:
    fetchPlans, fetchCurrentSubscription, assignPlan, changePlan, cancelSubscription,
    fetchUsageSummary, fetchUsageHistory), `billingUtils.ts` (categoryLabel Spanish,
    statusLabel/statusColor, formatCurrency, formatUsageValue, usagePercent, isUnlimited,
    formatDate/formatDateTime, extractErrorMessage, buildUsageSummary, UsageCategoryItem).
  - **Billing.vue**: página SaaS con: plan actual (status badge, precio, fecha fin), resumen
    de uso por categoría con progress bars, grilla de planes disponibles con "Seleccionar"/
    "Cambiar a este plan", tabla de historial con paginación, dialogs de confirmación
    (asignar/cambiar/cancelar) con texto explicativo, gate de permisos (owner ve manage,
    admin ve read-only, agent sin billing.view no ve nada), tenant switch watcher que
    refresca datos, loading/error/empty states, disabled buttons durante acciones,
    responsive (grid 1→3 cols), sin v-html, sin XSS vector.
  - **Ruta** `settings/billing` (web.php, verified+tenant, import BillingSettingsController).
  - **Nav**: "Billing" link en AppLayout tras Analytics.
  - **Sin cambios backend**: consume únicamente la API existente de U3.
  - **Tests Vitest** (50): billingApi.test.ts (10: URL correctness, params, returns),
    billingUtils.test.ts (20: categoryLabel, statusLabel/Color, formatCurrency,
    formatUsageValue, usagePercent, isUnlimited, formatDate/DateTime, extractErrorMessage,
    buildUsageSummary), billingDashboard.test.ts (20: render, fetch on mount, owner manage,
    admin read-only, agent denied, assign/change/cancel dialogs, loading/error/empty states,
    unlimited usage, NaN safety, tenant switch, no v-html, no hardcoded prices, security
    no tenant_id in payload).
  - **Quality gates**: vue-tsc 0 errors, vite build ok, 501/501 Vitest green, Pint ok,
    PHPStan 0 errors, composer audit 0 vulnerabilities, 133/133 backend tests ok.
- **Consecuencias**:
  - Módulo de billing completo en frontend con patrón idéntico a Analytics/Contacts/Conversations.
  - NO procesa pagos reales (Stripe llega en FASE 24). Los datos de planes son del seeder.
  - Admin puede ver pero NO gestionar suscripciones (decisión de diseño U3, mantenido en U4).
  - 50 tests frontend cubren API wrappers, utils, page render, permissions, security, UX.

## ADR-092 · Stripe Provider Infrastructure + Mappings (FASE 24 U1)

- **Estado**: Aceptado · FASE 24 U1
- **Contexto**: FASE 23 estableció el data model de billing (Plan, Subscription, UsageRecord),
  UsageTrackingService, API layer (7 endpoints) y frontend (Billing.vue). FASE 24 implementa
  la integración con Stripe. U1 es la capa de infraestructura: instalar SDK, definir interfaz
  de proveedor, implementar proveedor Stripe, mapear tenant→customer y plan→price, extender
  campos en modelos existentes.
- **Decisión**:
  - **SDK**: `stripe/stripe-php` directo (NO Laravel Cashier). Cashier agrega complejidad
    innecesaria para este caso de uso y acopla el dominio a Eloquent extra.
  - **Interfaz**: `BillingProviderInterface` en `app/Domain/Billing/Contracts/` con 4 métodos:
    `createCustomer()`, `retrieveCustomer()`, `validatePrice()`, `providerName()`.
  - **Implementación**: `StripeProvider` en `app/Infrastructure/Billing/` — `final class`,
    traduce excepciones `Stripe\Exception\ApiErrorException` a `BillingProviderException`.
    Los objetos de Stripe NUNCA escapan de esta clase.
  - **DTO**: `BillingCustomerData` — value object puro con `providerCustomerId`, `provider`,
    `email`, `metadata`. Se usa para transferir datos entre capas.
  - **billing_customers**: tabla tenant-scoped con `provider` (varchar 50),
    `provider_customer_id` (varchar 255). UNIQUE(tenant_id, provider) + UNIQUE(provider,
    provider_customer_id). Sin email (PII redundante). FK cascade on delete.
  - **Plan price mapping**: columnas nullable `stripe_price_id_monthly`/`stripe_price_id_yearly`
    en tabla `plans` (aceptable para MVP). Planes free = NULL price IDs.
  - **Subscription provider fields**: `stripe_subscription_id` (nullable UNIQUE),
    `cancel_at_period_end` (boolean default false). No `stripe_customer_id` (vive en billing_customers).
  - **SubscriptionStatus**: extiende con caso `Pending` (trial/awaiting payment). `isActive()`
    no cambia semántica — solo `Active` = activo.
  - **Container binding**: `AppServiceProvider` registra `BillingProviderInterface→StripeProvider`
    siguiendo el patrón existente (AI→OpenAI, WhatsApp→Meta).
  - **Config**: `config/services.php` sección `stripe` (secret + webhook_secret).
    `.env.example` ya tenía `STRIPE_SECRET_KEY=` y `STRIPE_WEBHOOK_SECRET=`.
  - **Seguridad**: API key solo en `.env`, nunca en código. Price IDs no se exponen via API.
    Provider valida secret al invocar (no al boot). No se valida Stripe secret al arrancar
    la app (backward compat con STRIPE_SECRET_KEY vacío).
  - **Backward compat**: app funciona con STRIPE_SECRET_KEY vacío. BillingProviderInterface
    lanza BillingProviderException si se invoca sin configurar.
  - **Testing**: Tests de configuración y validación del provider sin llamadas reales a Stripe.
    Multi-tenancy verificada para billing_customers. 32 tests nuevos U1.
- **Consecuencias**:
  - Capa de infraestructura de facturación lista para FASE 24 U2-U5.
  - Patrón consistente con AIProviderInterface y WhatsAppProviderInterface.
  - No hay dependencia de Cashier ni Eloquent billing custom.
  - Price IDs no expuestos — seguridad por diseño.
  - Provider knockout en config vacío — FASE 23 funciona sin Stripe.

## ADR-093 · Checkout + Customer Portal (FASE 24 U2)

- **Estado**: Aceptado · FASE 24 U2
- **Contexto**: U1 estableció la capa de infraestructura (BillingProviderInterface, StripeProvider,
  billing_customers, price mappings). U2 implementa la sesión de pago y el portal de facturación
  para el propietario del tenant. U2 NO confirma pagos — eso se hace en U3 via webhooks.
- **Decisión**:
  - **No activation from redirect**: Return URLs (`?checkout=success`, `?checkout=cancelled`) son
    feedback informativo solamente. NO mutan suscripciones. La confirmación de pago vive en U3
    (webhook `invoice.paid`). P0.
  - **Free plan bypass**: Si `price_monthly == 0 && price_yearly == 0`, el servicio lanza
    BillingProviderException con mensaje "Este plan es gratuito. Use la asignación directa."
    (usando SubscriptionService existente). Si se requiere downgrade paid→free con cancelación
    en Stripe: defer a U3/U4.
  - **Backend resolves price ID**: El frontend envía solo `plan_id` (UUID) + `interval`
    (monthly|yearly). El backend resuelve `stripe_price_id_monthly`/`stripe_price_id_yearly`
    del plan. NO se exponen price IDs, amounts, o currency al frontend. P0.
  - **Safe redirect**: Las URLs de checkout/portal se validan como `https://checkout.stripe.com/...`
    o `https://billing.stripe.com/...`. No open redirect. Frontend redirige con
    `window.location.href`.
  - **Checkout idempotencia**: Sin DDL nuevo en U2. Stripe Checkout Session es idempotente por
    diseño (misma key = misma sesión). Frontend usa disabled button durante redirect. Residual
    risk: double-submit possible if user clicks fast — documented.
  - **Race condition (customer creation)**: `BillingCustomerService.ensureCustomer()` ejecuta
    SELECT → dentro de DB::transaction INSERT. UNIQUE(tenant_id, provider) o
    UNIQUE(provider, provider_customer_id) violación → catch QueryException → re-read.
    Si re-read falla → re-throw (rare). Orphan Stripe customers: residual risk, documented.
  - **Controller thin**: `CheckoutController` (store=checkout, portal) delega a
    `CheckoutService`. `BillingCustomerService` maneja la resolución tenant→provider customer.
  - **FormRequest**: `StoreCheckoutRequest` acepta `plan_id` (required uuid) +
    `interval` (required, in:monthly,yearly). Fields extra (price_id, amount, currency,
    tenant_id) silently stripped by Laravel validation.
  - **Authorization**: `billing.manage` = Owner only. Admin = 403. Agent = 403.
    CheckoutService y PortalService invocan `AuthorizationService->authorize()` internamente.
  - **Multi-tenancy**: Checkout usa tenant del URL (TenantMiddleware). BillingCustomer
    resuelto por tenant_id. Cross-tenant → 403/404 (TenantMiddleware + AuthorizationService).
  - **Routes**: `POST {tenant}/billing/checkout` (store), `POST {tenant}/billing/portal` (portal).
  - **Frontend**:
    - `billingApi.ts`: +createCheckoutSession, +createPortalSession.
    - `billingTypes.ts`: Subscription.status + 'pending'.
    - `billingUtils.ts`: statusLabel + 'Pendiente de pago', statusColor + 'amber'.
    - `Billing.vue`: isPaidPlan() helper, interval selector (monthly/yearly) en dialog,
      redirect a checkout URL para planes paid, portal button "Gestionar facturación",
      onMounted() lee `?checkout=success|cancelled` → muestra mensaje y limpia URL,
      confirmPlanAction bifurca: paid→createCheckoutSession→redirect, free→assignPlan/changePlan.
  - **Config**: `app.url` (para success_url/cancel_url/return_url). Verificado en `.env.example`.
  - **Testing**:
    - `BillingU2ProviderTest` (5): Provider interface, config validation, checkout/portal throws.
    - `BillingU2CheckoutServiceTest` (10): ensureCustomer reuse, free plan bypass, invalid interval,
      no price configured, authorization (agent/admin denied), portal URL, portal without customer.
    - `BillingU2ApiTest` (14): owner/admin/agent matrix for checkout+portal, validation, extra fields
      rejected, billing customer creation, portal response.
    - `BillingU2MultiTenancyTest` (6): cross-tenant isolation for checkout+portal, unique customers.
    - `BillingU2SecurityTest` (6): billing.manage matrix, auth required, outsider 403, response
      containment, idempotent customer creation.
    - `BillingU2PostgresTest` (4): unique constraints, different providers, unique provider_customer_id,
      plan stripe columns.
    - `billingApi.test.ts` (+5): createCheckoutSession, createPortalSession, security.
    - `billingUtils.test.ts` (+2): pending statusLabel/statusColor.
    - Total U2: **41 tests** (32 Pest backend + 4 PostgreSQL + 7 Vitest frontend).
  - **Quality gates**: pint 727 files clean, vue-tsc 0 errors, vite build ok,
    206/206 billing tests pass, composer audit 0 vulnerabilities.
- **Consecuencias**:
  - Propietarios de tenant pueden iniciar checkout y acceder al portal de facturación.
  - Free plans siguen usando SubscriptionService directamente (sin Stripe).
  - Los pagos NO se confirman en U2 — esperan U3 webhooks.
  - Precio y price IDs nunca llegan al frontend (backend-authoritative).
  - Redirección segura contra open redirect.
  - Residual risks documentados: orphan Stripe customers, double-submit race.
  - Patrón consistente con CheckoutService/BillingCustomerService como orquestadores.

## ADR-094 · Stripe Webhook Ingestion + Subscription Sync

- **Estado**: Aceptado · FASE 24 U3
- **Contexto**: U2 creó Checkout Sessions en Stripe y Customer Portal, pero la activación de
  suscripciones y la sincronización de estados de pago dependen exclusivamente de webhooks de
  Stripe. Sin U3, las suscripciones quedan en estado `pending` indefinidamente, y los pagos
  exitosos/fallidos no se reflejan en el sistema. P0: REDIRECT DE STRIPE ≠ PAYMENT
  CONFIRMATION — solo webhooks firmados confirman pago/activan suscripción.
- **Decisión**:
  - **Webhook endpoint público**: `POST /api/webhooks/stripe` sin auth Bearer, sin middleware
    tenant, sin CSRF. Firma verificada vía `BillingProviderInterface::constructWebhookEvent()`.
  - **Firma**: Stripe-Signature header verificada con `stripe/stripe-php` SDK. El SDK verifica
    la firma ANTES de parsear JSON; payload malformado con firma inválida produce
    `SignatureVerificationException`, no un error de parseo.
  - **DTO (no raw Stripe objects)**: `constructWebhookEvent()` retorna `ProviderWebhookEvent` DTO
    (eventId, type, createdAt, objectId, customerId, data[]). Ningún objeto Stripe escapa a
    Infrastructure.
  - **Tenant resolution**: `customer` event → Stripe customer ID →
    `BillingCustomer.provider_customer_id` → `tenant_id`. NO se confía en `metadata` del payload
    (unhint no verificado).
  - **Idempotencia ledger**: tabla `billing_webhook_events` con `UNIQUE(provider, provider_event_id)`.
    Eventos duplicados se registran como `Processed` sin mutar datos.
  - **Event ordering**: columna `provider_updated_at` en `subscriptions`. Un evento con
    `provider_updated_at` ≤ el valor local es descartado como stale. `cancelled` nunca se
    resucita por un evento stale.
  - **Señales de activación**: `checkout.session.completed` crea suscripción `pending` (NO activa).
    `invoice.paid` es la señal autoritativa de activación → `status = active`.
  - **`customer.subscription.updated`**: sincroniza plan, status, `current_period_start/end`,
    `cancel_at_period_end` desde Stripe hacia la suscripción local.
  - **`customer.subscription.deleted`**: actualiza status a `cancelled`.
  - **`invoice.payment_failed`**: actualiza status a `past_due`.
  - **No payload raw almacenado**: solo eventId, type, status, timestamps en el ledger.
  - **Sin PII en logs/auditoría**: el servicio de logging sanitiza datos sensibles.
  - **Response siempre `{"received": true}`** para eventos válidos; 400 para firma inválida.
  - **PastDue agregado** a `SubscriptionStatus` enum (active, pending, cancelled, past_due).
- **Consecuencias**:
  - Los pagos exitosos se confirman únicamente vía webhook firmado (P0).
  - El sistema es idempotente ante reenvíos de Stripe.
  - El ordering previene la activación por eventos stale.
  - El ledger permite auditoría y debugging de eventos recibidos.
  - Sin raw payload storage → minimal attack surface.
  - Tenant resolution server-side → no se confía en metadata del cliente.
  - Response 200 siempre para válidos → Stripe no reintenta infinitamente.
  - Duplicados → idempotente via UNIQUE constraint.

## ADR-095 · Billing Frontend Provider UX (FASE 24 U4)

- **Estado**: Aceptado · FASE 24 U4
- **Contexto**: U2 implementó checkout + portal funcionales pero el frontend trataba todo como
  sistema propio (botón "Confirmar", status simple, sin flujos de retorno de Stripe). Con U3
  confirmando pagos vía webhooks, el frontend debe reflejar el flujo real: redirección a Stripe
  Checkout, Customer Portal, estados `pending`/`past_due`, y feedback post-checkout.
- **Decisión**:
  - **Checkout → redirect**: plan de pago abre sesión Checkout vía API → redirección a
    `checkout_url` de Stripe. El frontend NO confirma el pago; solo feedback post-retorno.
  - **Customer Portal**: botón "Gestionar facturación" llama a API → redirección a Stripe Portal.
  - **Return URL feedback**: `?checkout=success` → mensaje "pago enviado para confirmación"
    (NO "suscripción activada"). `?checkout=cancelled` → mensaje informativo. Sin mutación local.
  - **Provider-aware status**: `billingUtils` soporta `pending`, `past_due`, `cancelled` con
    labels y colores en español. `hasActiveSubscription` = status ≠ `cancelled` (pending y
    past_due muestran detalles de suscripción, no "sin suscripción").
  - **Cancel at period end**: `cancel_at_period_end` expuesto en SubscriptionResource + UI
    muestra "Se cancela al final del periodo".
  - **Top-level error display**: errores de portal/checkout visibles sin scroll.
  - **Backend mínimo**: solo `SubscriptionResource` modificado (+`cancel_at_period_end`).
    Sin webhooks nuevos, sin tablas nuevas, sin notifications, sin UsageGuard.
- **Consecuencias**:
  - El frontend refleja fielmente el flujo Stripe (redirect → webhook confirmation).
  - El usuario entiende que el pago está "en proceso" tras volver de Stripe.
  - `past_due` visible permite que el usuario tome acción antes de suspensión.
   - El sistema mantiene la separación: frontend = UX, backend = autoridad via webhooks.

## ADR-096 · Webhook Hardening: Transient vs Permanent Error Classification (FASE 24 U5)

- **Estado**: Aceptado · FASE 24 U5
- **Contexto**: La auditoría de U5 identificó que `StripeWebhookService.handle()` trataba todos los
  `QueryException` como duplicados (P1-01) o errores permanentes (P1-02), perdiendo eventos que
  requerían retry por errores transitorios de BD. Además, la clasificación de errores en `handle()`
  no distinguía entre violaciones UNIQUE (permanent, no-retry) y otros errores de BD (transient, retry).
- **Decisión**:
  - **recordEvent()**: QueryException catch ahora verifica SQLSTATE 23505 (PostgreSQL) o 23000
    (SQLite) + mensaje text (UNIQUE constraint failed, duplicate key value) antes de tratar como
    duplicado. Otros QueryException rethrow (antes: todos eran treated como duplicados → eventos
    perdidos).
  - **handle()**: Clasificación dual:
    - UNIQUE violations (SQLSTATE 23505/23000 o mensaje UNIQUE) → return 200 (permanent, no retry)
    - Otros QueryException + deadlock + connection → rethrow 500 (transient, Stripe retry)
    - Cualquier otro error → return 200 (permanent business error, no retry)
  - **isNewerEvent()**: Strict `>` solamente, sin tie-break por mismo timestamp (antes: `>=` permitía
    tie → stale events podían resucitar suscripciones canceladas).
  - **Idempotency keys**: checkout (`checkout:{tenant}:{plan}:{interval}`) y portal
    (`portal:{tenant}:{timestamp}`) ahora incluyen idempotency keys para prevenir sesiones
    duplicadas en Stripe.
  - **URL validation**: Frontend valida `https:` protocol antes de redirect a Stripe (previene
    open redirect).
  - **Dialog state**: Tenant switch limpia estado de diálogos (previene data leakage cross-tenant).
- **Consecuencias**:
  - Eventos con errores transitorios de BD son reintentados por Stripe (500 → retry).
  - Eventos con errores permanentes (UNIQUE, business logic) no generan retries infinitos.
  - SQLite (tests) y PostgreSQL (producción) ambos soportan la clasificación.
  - El ordenamiento de eventos es estrictamente monótono (sin ties).

## ADR-097 · UsageGuard + Atomic Quota Reservation (FASE 25 U1)

- **Estado**: ACEPTADO · FASE 25 U1+U2+HOTFIX · U3+U4+U5 PENDIENTES
- **Contexto**: FASE 23 instaló `UsageTrackingService` (append-only ledger, dormante en producción)
  y el catálogo de `Plan::limits` por categoría. FASE 25 necesita un guard de cuotas
  atómico, idempotente y multi-tenant que prevenga over-limit antes de enviar mensajes, ejecutar
  IA o crear contactos — sin integrares aún con servicios de negocio (MessageService, etc.).
- **Decisión**:
  1. **UsageGuard** (`app/Application/Billing/Guards/UsageGuard.php`) expone `remaining()`,
     `reserve()`, `commit()`, `release()`. Cada `reserve()` adquiere un advisory lock de
     PostgreSQL (`pg_advisory_xact_lock`) por `(tenant_id, category, period_start)` para serializar
     reservas concurrentes. La DB es source of truth; no se usa Redis para cuotas.
  2. **EntitlementResolver** (`app/Application/Billing/Guards/EntitlementResolver.php`) resuelve la
     suscripción + plan de un tenant. Políticas: Active + PastDue = permitido; Pending/Cancelled =
     fail-closed; `cancel_at_period_end` = sigue activo para el período actual.
  3. **UsageReservation** (`app/Domain/Billing/Models/UsageReservation.php`) es un Eloquent model
     con tabla `usage_reservations`. Estados: `reserved` → `committed` (crea UsageRecord) o
     `released`. CHECK `quantity > 0`. Idempotencia por `idempotency_key` UNIQUE parcial.
     `expires_at` permite limpieza de reservas stale.
  4. **TenantQuotaExceededException** (`app/Domain/Billing/Exceptions/TenantQuotaExceededException.php`):
     HTTP 429, code `TENANT_QUOTA_EXCEEDED`, safe fields: `category`, `limit`, `used`. No expone
     tenant_id, subscription_id, plan_id ni provider IDs. Renderer en `bootstrap/app.php`.
  5. **Limites del U1**: UsageGuard es infraestructura interna. NO se integra con MessageService,
     SendWhatsAppMessage, FlowExecutionService, AiNodeExecutor, KnowledgeSearchService,
     ContactService ni InvitationService. NO hay endpoints API. NO hay Redis quota cache. NO hay
     frontend. NO hay Stripe integration.
- **Consecuencias**:
  - Advisory locks en PostgreSQL serializan concurrentes por tenant+categoría+período.
  - SQLite (tests) omite advisory locks (sin pg_advisory_xact_lock) — la serialización
    depende del `DB::transaction()`.
  - La migración `usage_reservations` incluye CHECK constraint y UNIQUE parcial.
   - U3 (AI tokens) y U4 (contacts/users/KDocuments) integran UsageGuard en futuras unidades.

### U2 · Message + Flow Quota Enforcement (extensión de ADR-097)

- **Estado**: ACEPTADO · FASE 25 U2
- **Contexto**: U1 instaló UsageGuard como infraestructura interna. U2 integra UsageGuard con los
  chokepoints de negocio: MessageService (outbound), SendWhatsAppMessage (worker), y
  FlowExecutionService (start). Se necesita enforce atómico con idempotencia, retry safety, y
  manejo de suscripciones inexistentes.
- **Decisión**:
  1. **MessageService** (`app/Application/Messages/Services/MessageService.php`): reserve quota
     con key `message:{message_id}` (TTL 900s) antes de crear el mensaje. UUID pre-generado via
     `Str::uuid()`. ID asignado via `forceFill(['id' => $messageId])` (id no está en `$fillable`).
  2. **SendWhatsAppMessage** (`app/Jobs/SendWhatsAppMessage.php`): re-reserva con misma key tras
     CAS claim. Éxito del provider → commit. Fallo permanente → release. `failed()` llama
     `releaseReservationIfExists()` antes de `failMessage()`. SubscriptionNotFoundException
     propaga → `failed()` → mensaje terminal.
  3. **FlowExecutionService** (`app/Application/Flows/Services/FlowExecutionService.php`): genera
     UUID pre-creado, reserva con key `flow_execution:{uuid}` (TTL 300s), crea FlowExecution con
     ID pre-generado, commitea inmediatamente. Start = consumed. Errores posteriores NO liberan.
  4. **UsageGuard::reserve()** retorna `?UsageReservation` (null = plan limit es null = unlimited).
     `SubscriptionNotFoundException` propaga (fail-closed). `remaining()` idem.
  5. **SubscriptionNotActiveException** renderer: HTTP 409, code `SUBSCRIPTION_NOT_ACTIVE` en
     `bootstrap/app.php`.
  6. **SubscriptionNotFoundException** renderer: HTTP 409, code `SUBSCRIPTION_NOT_FOUND` en
     `bootstrap/app.php`.

### U2-HOTFIX · Fail-closed when usage entitlement is missing

- **Estado**: ACEPTADO · FASE 25 U2 HOTFIX
- **Commit**: `755a192`
- **Contexto**: U2 integró UsageGuard en MessageService, SendWhatsAppMessage y
  FlowExecutionService. Sin embargo, `UsageGuard::reserve()` y `remaining()` capturaban
  `SubscriptionNotFoundException` y retornaban `null` (tratando tenants sin suscripción como
  unlimited). Esto significaba que un tenant sin suscripción activa podía enviar mensajes y
  ejecutar flujos ilimitadamente — violando el principio fail-closed deEntitlementResolver.
- **Causa raíz**: Catch blocks en `reserve()` (línea ~94) y `remaining()` (línea ~48) que
  convertían `SubscriptionNotFoundException` en `null`.
- **Decisión**:
  1. **UsageGuard::reserve()** y **remaining()**: eliminados ambos catch blocks.
     `SubscriptionNotFoundException` ahora propaga al caller. `null` retorno = plan limit
     es `null` = unlimited (no necesita reserva).
  2. **MessageService::createOutbound()**: `reserve()` movido ANTES de la creación del
     mensaje. UUID pre-generado via `Str::uuid()` para construir la idempotency key.
     ID asignado via `forceFill(['id' => $messageId])` (descubrimiento: `id` no está en
     `$fillable` del modelo Message).
  3. **bootstrap/app.php**: renderer para `SubscriptionNotFoundException` → HTTP 409,
     code `SUBSCRIPTION_NOT_FOUND`.
  4. **Worker defense** (ya existente en U2): `SendWhatsAppMessage::sendLocked()` llama
     `reserve()` después de CAS claim. SubscriptionNotFoundException → exception
     propagates → `failed()` → libera reserva y falla mensaje permanentemente.
- **Consecuencias**:
  - Tenant sin suscripción → `SubscriptionNotFoundException` en el primer chokepoint
    (createOutbound, start flow). HTTP 409 o job failure terminal.
  - Tenant con plan unlimited → null retorno (sin cambio). `reserve()` retorna null
    después del early-return de `limit === null`.
  - Tenant con plan limitado → quota enforcement sin cambios.
  - 136 tests fixture regressions resueltos con helper `ensure_test_usage_entitlement()`.
  - 6 Handoff test failures preexistentes (TypeError `TagNodeExecutor` con EventFake,
    no relacionado con el hotfix).
