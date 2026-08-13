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

## Pendientes de decisión

- Proveedor de email en producción (mailpit en dev; SES/Resend/SMTP en prod) → FASE 22.
- Pasarela de pagos (Stripe propuesto) → FASE 24.
- Estructura de OpenAPI (spec manual vs. paquetes) → FASE 35.
