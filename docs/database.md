# Base de datos

PostgreSQL 16 con extensiones `pgvector` y `uuid-ossp`. Motor InnoDB-equivalente: Postgres.

## 1. Estrategia

- **Shared database + `tenant_id`**: todas las tablas del dominio tenant llevan `tenant_id`.
- `UUID` como PK en toda la plataforma (evita enumeración de recursos, simplifica migración a shards).
- Índices obligatorios: `tenant_id` + columnas de búsqueda/filtro.
- `ON DELETE CASCADE` para entidades que no tienen sentido sin su padre.
- Soft deletes (`deleted_at`) en contactos, conversaciones y flujos.

## 2. Diagrama ERD (resumen)

```
tenants
  ├─ business_profiles (1:1)
  ├─ users (N:M via tenant_users)
  ├─ whatsapp_accounts 1─N whatsapp_phone_numbers
  ├─ contacts 1─N tags (N:M contact_tag)
  ├─ conversations 1─N messages · 1─N conversation_participants · 1─N conversation_assignments
  ├─ chatbots 1─N flows 1─N flow_nodes 1─N flow_connections
  ├─ flow_executions
  ├─ leads
  ├─ knowledge_base 1─N knowledge_documents 1─N knowledge_chunks
  ├─ faqs
  ├─ audit_logs
  ├─ subscriptions 1─N usage_records
  ├─ analytics_daily
  └─ conversation_metrics

## 10. FASE 22 — Notifications (DDL)

### notifications

| Columna | Tipo | Constraint |
|---|---|---|
| `id` | uuid | PK |
| `tenant_id` | uuid | NOT NULL, FK→tenants `cascadeOnDelete` |
| `user_id` | uuid | NULLABLE, FK→users `nullOnDelete` |
| `type` | varchar(100) | NOT NULL |
| `priority` | varchar(20) | NOT NULL, DEFAULT 'normal' |
| `title` | varchar(255) | NOT NULL |
| `body` | text | NOT NULL |
| `data` | json | NULLABLE |
| `read_at` | timestamp | NULLABLE |
| `created_at` / `updated_at` / `deleted_at` | timestamp | — (SoftDeletes) |

- **Índices**: `idx_notifications_tenant_user_read (tenant_id, user_id, read_at)`, `idx_notifications_tenant_created (tenant_id, created_at)`, `idx_notifications_tenant_type (tenant_id, type)`.
- **FK compuesta**: `(tenant_id, user_id)` → `users(tenant_id, id)` — integridad referencial tenant-first.
- **user_id nullable**: permite notificaciones tenant-wide (user_id = NULL) y dirigidas (user_id != NULL).
- **SoftDeletes**: usuario puede descartar; preserva historial de auditoría.
- **Sin unique constraint** en type+user+conversation — múltiples notificaciones del mismo tipo son legítimas.
- Migración verificada: PostgreSQL 16 — `migrate:up` completa, `migrate:rollback` revierte, segundo `migrate:up` re-aplica limpiamente.

### tenant_users — FASE 22 U4 (email_preferences)

| Columna | Tipo | Constraint |
|---|---|---|
| `email_notifications_enabled` | boolean | NOT NULL, DEFAULT `false` |

- Agregado a tabla existente `tenant_users` vía `Schema::table()` (FASE 22 U4).
- Per-user-per-tenant: cada membresía tiene su propio valor.
- Default `false`: sin contrato previo de email, evita spam involuntario (ADR-086).
- Migración verificada: PostgreSQL 16 — `migrate:up` completa, `migrate:rollback` revierte.

## 3. Tablas por módulo

### Plataforma (sin tenant_id)

| Tabla | Propósito | Notas |
|---|---|---|
| `users` | Usuarios globales | email único; `password`, `email_verified_at`, `current_tenant_id` nullable FK→tenants (tenant activo) |
| `tenants` | Negocios | `name`, `slug` único, `status`, `plan_id`, `timezone`, `locale` |
| `tenant_users` | Relación user-tenant | `tenant_id`, `user_id`, `role` (owner/admin/agent), `status` (active/invited/disabled), `invited_at`/`joined_at`; UNIQUE `(tenant_id, user_id)` |
| `tenant_invitations` | Invitaciones a un tenant (FASE 4, ADR-027) | `tenant_id`, `email`, `role`, `token_hash` (sha256, único; nunca el token plano), `invited_by`, `status` (pending/accepted/revoked/expired), `expires_at` (7 días), `accepted_at` |
| `business_profiles` | Perfil de negocio 1:1 (FASE 5, ADR-028) | `tenant_id` UNIQUE FK→tenants `cascadeOnDelete`; `name`, `description`, `category`, `address`, `website`, `email`, `phone`, `working_hours` (JSON). Se crea bajo demanda en la primera lectura |
| `plans` | Planes (FREE/PRO/BUSINESS) | límites en `limits` JSON |
| `webhook_events` | Eventos crudos recibidos de Meta (FASE 6, ADR-029) | Nivel plataforma: `provider_event_id` UNIQUE (id global de Meta), `tenant_id` nullable (se resuelve por phone_number_id), `status` (received/enqueued/processed/failed), `event_type` (message/status), `duplicate` |
| `audit_logs` | Auditoría | `tenant_id` nullable, `actor_id`, `action`, `payload` |
| `failed_jobs`, `cache`, `sessions`, `personal_access_tokens`, `password_reset_tokens` | Framework | estándar |

> `webhook_events` es de plataforma (no tiene `tenant_id` en la constraint de dedupe) porque
> un mismo evento de Meta es único a nivel global y puede llegar sin que el tenant esté aún
> resuelto. El `tenant_id` se rellena al resolver `metadata.phone_number_id`.

### Dominio tenant (todas con `tenant_id`)

| Módulo | Tablas |
|---|---|
| Business profile | `business_profiles` |
| WhatsApp | `whatsapp_accounts`, `whatsapp_phone_numbers`, `message_send_attempts` |
| Contacts | `contacts`, `tags`, `contact_tag`, `contact_imports` |
| Conversations | `conversations`, `conversation_participants`, `conversation_assignments` |
| Messages | `messages` |
| Chatbots | `chatbots`, `triggers` |
| Flows | `flows`, `flow_nodes`, `flow_connections`, `flow_executions`, `flow_execution_logs` |
| Leads | `leads` |
| Knowledge | `knowledge_bases`, `knowledge_documents`, `knowledge_chunks` |
| FAQ | `faqs` |
| Billing | `subscriptions`, `subscription_items`, `invoices`, `usage_records` |
| Analytics | `analytics_daily`, `conversation_metrics` |
| Variables | `conversation_context` (JSON dentro de `conversations`) |

### `webhook_events` (plataforma, ver arriba) — FASE 6

```
id uuid PK
provider_event_id varchar(255) UNIQUE   → messages[].id para mensajes; para statuses la clave
                                        → compuesta "id|status|timestamp" (Meta reusa el id de
                                        → mensaje en delivered/read → UNIQUE simple colisionaba)
tenant_id FK → tenants (nullable, se resuelve por metadata.phone_number_id)
payload JSONB                            → crudo (mínimo necesario)
status enum(received, enqueued, processed, failed)
event_type enum(message, status) nullable → qué job procesa el evento
duplicate boolean                        → true si Meta re-envió un evento ya registrado
error_code varchar(100) nullable         → motivo de fallo (unknown_phone_number_id, ...)
processed_at timestamp nullable
created_at / updated_at
```

Índices: `webhook_events_provider_event_id_unique` (UNIQUE) + `(status, created_at)` para el
sweeper/outbox. **FASE 9**: el comando `whatsapp:reprocess-webhook-events` (programado cada minuto,
`withoutOverlapping`) re-encola eventos `status='received'` con `created_at` anterior a 5 minutos
(limit 100); `created_at`/`updated_at` no son `$fillable` (se insertan por query-builder).

## 4. Columnas críticas

### `conversations` / `conversation_participants` / `conversation_assignments` (FASE 8, ADR-031)
```
conversations
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  contact_id FK → contacts (cascadeOnDelete)   → contacto del MISMO tenant (validado en el servicio)
  status varchar(20) default 'open'             → open/pending/resolved/archived (máquina de estados)
  last_message_at timestamp nullable
  last_interaction_at timestamp nullable
  agent_id FK → users.id (BIGINT) nullable      → asignación VIGENTE (nullOnDelete)
  auto_assigned boolean default false           → true si la asignó el sistema (FASE 9+)
  bot_paused boolean default false              → handoff a humano
  handoff_requested_at timestamp nullable       → solicitud explícita de atención humana (FASE 15 U1)
  context JSONB nullable                        → variables de conversación ({{custom.x}})
  flow_execution_id uuid nullable               → ejecución activa (SIN FK hasta FASE 11)
  created_at / updated_at / deleted_at (soft delete)
```
- `agent_id` referencia `users.id` (BIGINT, igual que `tenant_users.user_id`); `tenant_id`/`contact_id`
  son UUID. El historial de asignaciones NO vive aquí: está en `conversation_assignments`.
- Índices: `(tenant_id, status, last_message_at)`, `(tenant_id, contact_id)`,
  `(tenant_id, agent_id)`, `(tenant_id, last_interaction_at)` y
  `(tenant_id, handoff_requested_at)`.

```
conversation_participants                      → quién estuvo/está involucrado (agentes y, en el futuro, bots)
  id bigint PK
  tenant_id FK → tenants (cascadeOnDelete)
  conversation_id FK → conversations (cascadeOnDelete)
  user_id FK → users (BIGINT, cascadeOnDelete)
  role varchar(50)                             → rol del tenant en la activación más reciente
  joined_at / left_at timestamp nullable       → participación acumulativa; reactivar conserva joined_at
  UNIQUE (conversation_id, user_id) + índices (user_id, conversation_id),
  (tenant_id, conversation_id), (tenant_id, user_id)

conversation_assignments                       → historial acumulativo de asignaciones/transferencias
  id bigint PK
  tenant_id FK → tenants (cascadeOnDelete)
  conversation_id FK → conversations (cascadeOnDelete)
  agent_id FK → users (BIGINT, cascadeOnDelete)
  assigned_by FK → users (BIGINT) nullable     → quién realizó la asignación (nullOnDelete)
  assigned_at timestamp                        → inicio de la asignación
  unassigned_at timestamp nullable             → se rellena al transferir/reasignar
  reason varchar(30) default 'manual'          → manual | transfer | claim
  UNIQUE parcial (conversation_id) WHERE unassigned_at IS NULL
  índices (conversation_id, assigned_at), (agent_id, assigned_at),
  (tenant_id, conversation_id), (tenant_id, agent_id)
```

### `messages` (FASE 9, ADR-032)
```
id uuid PK
tenant_id FK → tenants (cascadeOnDelete)
conversation_id FK → conversations (cascadeOnDelete)   → el contacto se resuelve por la conversación
sent_by_user_id FK → users nullable (nullOnDelete)      → actor humano; inbound/bot = NULL
provider_message_id varchar(255) nullable  → idempotencia (UNIQUE (tenant_id, provider_message_id))
direction varchar(10) → inbound/outbound
type varchar(20) → text, image, audio, video, document, location, interactive, template
status varchar(20) → pending, sending, sent, delivered, read, failed
body text nullable                         → texto, caption/filename de media o address de location
media_url varchar(2048) nullable
media_mime varchar(100) nullable
media_size bigint nullable
  metadata JSONB nullable                    → inbound: from/provider_timestamp + payload del tipo
                                           → outbound: text + origin automation|human|handoff;
                                           → bloqueo interno: error_code/error_source
sent_at, delivered_at, read_at, failed_at nullable   → columna por estado (ADR-032)
created_at / updated_at
```
- UNIQUE `(tenant_id, provider_message_id)`: los NULL no colisionan (mensajes outbound aún sin id
  de Meta). Índices: `(tenant_id, conversation_id, created_at)` y `(conversation_id)`.
- Los status de Meta **actualizan** la fila por `provider_message_id` (nunca crean mensajes);
  `sending` es el estado CAS del job `SendWhatsAppMessage` (`pending → sending` atómico).
- El detalle de error del proveedor y sus intentos vive en `message_send_attempts`. El bloqueo
  interno anterior al provider por handoff no crea attempt y guarda únicamente
  `BOT_PAUSED_HANDOFF`/`internal` en metadata del mensaje.

### `chatbots` / `flows` / `triggers` / `flow_nodes` / `flow_connections` / `flow_executions` / `flow_execution_logs` (FASE 11, ADR-034)

```
chatbots
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  name varchar(255)
  description text nullable
  created_at / updated_at / deleted_at (soft delete)
  índice (tenant_id, created_at)
```

```
flows                                   → la fila ES la versión (ADR-036; sin flow_versions)
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  chatbot_id FK → chatbots (cascadeOnDelete)
  name varchar(255)
  description text nullable
  status varchar(20) default 'draft'     → draft/published/inactive (máquina de estados)
  config JSONB nullable
  created_at / updated_at / deleted_at (soft delete)
  índices (tenant_id, status) y (chatbot_id)
```

`created_at` / `updated_at` de `flows` son `timestamp(6)` (migración FASE 12) para que el lock
optimista del borrador (ADR-041) distinga escrituras concurrentes; `base_updated_at` en el
`PUT /draft` se compara contra `updated_at`.

```
triggers
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  flow_id FK → flows (cascadeOnDelete)
  type varchar(20)      → keyword/new_message/start implementados (FASE 11); tag/schedule/webhook registrados (FASE 14)
  keyword varchar(255) nullable          → requerido si type=keyword
  config JSONB nullable                  → p. ej. cron para schedule
  priority int default 0                 → desempate entre triggers del mismo tipo
  active boolean default true
  índices (flow_id) y (tenant_id, type, active)
```

```
flow_nodes
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  flow_id FK → flows (cascadeOnDelete)
  type varchar(20)      → message, buttons, question, condition, delay, tag, webhook, human, end
                          (ai registrado pero BLOQUEADO hasta FASE 16)
  name varchar(255)
  position_x / position_y int default 0  → editor Vue Flow (FASE 12)
  config JSONB nullable                  → contenido específico del nodo (texto, botones, condición, prompt, ...)
  is_start boolean default false         → único nodo de entrada (el inicio es un nodo REAL; no existe tipo "start")
  created_at / updated_at
  índices (flow_id) y (tenant_id, flow_id)
```

Constraint: UNIQUE `(flow_id)` WHERE `is_start` → un solo nodo de entrada por flujo.

```
flow_connections
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  flow_id FK → flows (cascadeOnDelete)
  source_node_id FK → flow_nodes (cascadeOnDelete)
  target_node_id FK → flow_nodes (cascadeOnDelete)
  label varchar(255) nullable            → condición/resultado de rama
  created_at / updated_at
  índices (flow_id), (source_node_id) y (target_node_id)
```

```
flow_executions
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  flow_id FK → flows (cascadeOnDelete)
  conversation_id FK → conversations (cascadeOnDelete)  → la ejecución activa se enlaza también en conversations.flow_execution_id (FK real, FASE 11)
  current_node_id FK → flow_nodes nullable              → nodo en curso (null al terminar)
  status varchar(20) default 'running'                  → running/waiting/completed/failed/handed_off
  variables JSONB default '{}'                          → respuestas de question + {{custom.*}}
  attempts int default 0                                → reintentos ante fallo
  last_inbound_message_id FK → messages nullable        → barrera de idempotencia del motor (un inbound nunca avanza dos veces)
  created_at / updated_at
  índices (flow_id) y (status, created_at)
```

Constraint: UNIQUE parcial `(tenant_id, conversation_id) WHERE status IN ('running','waiting')`
→ una sola ejecución activa por conversación (evita doble ejecución; barrera de concurrencia a
nivel de DB, junto al lock Redis y el CAS de avance del motor, ADR-037).

```
flow_execution_logs
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  execution_id FK → flow_executions (cascadeOnDelete)
  node_id FK → flow_nodes nullable
  event varchar(50)      → step/advance/finish/error/...
  payload JSONB nullable → traza por paso (debug + auditoría)
  sequence int default 0 → orden dentro de la ejecución
  created_at
  índices (execution_id, sequence) y (execution_id, created_at)
```

### `knowledge_bases` / `knowledge_documents` / `knowledge_chunks` (FASE 17, ADR-058)

```
knowledge_bases
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  name varchar(255)
  description text nullable
  created_at / updated_at / deleted_at (soft delete)
  UNIQUE parcial (tenant_id, name) WHERE deleted_at IS NULL
  índices (tenant_id, created_at)
```

```
knowledge_documents
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  knowledge_base_id FK → knowledge_bases (cascadeOnDelete)
  filename varchar(255)
  storage_path varchar(1024)
  file_size bigint
  mime_type varchar(100)
  file_hash varchar(64)                  → SHA-256 del contenido binario
  status enum(uploaded, processing, ready, failed)
  chunk_count int default 0
  total_tokens int default 0
  processed_at timestamp nullable
  error_message text nullable
  created_at / updated_at / deleted_at (soft delete)
  FK compuesta (tenant_id, knowledge_base_id) → knowledge_bases
  UNIQUE parcial (tenant_id, knowledge_base_id, file_hash) WHERE deleted_at IS NULL
  índices (tenant_id, knowledge_base_id), (tenant_id, status)
```

```
knowledge_chunks
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  document_id FK → knowledge_documents (cascadeOnDelete)
  content text
  embedding vector(1536) NULLABLE           → NULL = embedding pendiente (U3 lo materializará)
  token_count int
  chunk_index int
  metadata JSONB nullable                → page, section, headers (provenance)
  created_at / updated_at
  FK compuesta (tenant_id, document_id) → knowledge_documents (PostgreSQL only)
  UNIQUE (document_id, chunk_index)
  HNSW index idx_knowledge_chunks_embedding ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)
  WITH (m = 16, ef_construction = 64)
```

- Sin soft delete en chunks: datos derivados regenerables. CASCADE elimina al eliminar documento padre.
- `vector(1536)` hardcodeado (contrato con text-embedding-3-small, ADR-058).
- `embedding` NULLABLE hasta U3 (ADR-062). NULL = embedding pendiente/no generado.
- Migración condicional: vector + HNSW solo en PostgreSQL; tests SQLite omiten estas columnas.

### `faqs` (FASE 18, ADR-069)

```
faqs
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  question varchar(500)          → texto curado del tenant
  normalized_question varchar(500) → representación canónica (FaqQuestionNormalizer)
  answer text                    → respuesta textual determinista
  status varchar(20) default 'active' → active | inactive
  priority int default 0         → mayor = primero en matching futuro
  created_at / updated_at / deleted_at (soft delete)
```

- **FK compuesta**: `(tenant_id, id)` UNIQUE (PostgreSQL).
- **Unique parcial**: `UNIQUE(tenant_id, normalized_question) WHERE deleted_at IS NULL` (PostgreSQL);
  `UNIQUE(tenant_id, normalized_question)` en SQLite.
- **Índice**: `(tenant_id, status)` para queries de matching.
- **Sin hit_count**: métricas pertenecen a telemetría, no a la tabla de dominio.
- **Normalization**: FaqQuestionNormalizer — trim, Unicode NFC, lowercase, edge punctuation removal,
  whitespace collapse. Preserva acentos, ñ, emoji. Sin accent folding.

### `usage_records`
```
id uuid PK
tenant_id FK
subscription_id FK
feature enum(messages, ai_tokens, contacts, storage)
quantity bigint
period_start / period_end timestamp
```

Constraint: UNIQUE `(tenant_id, subscription_id, feature, period_start)` → el contador del
período se actualiza con UPSERT atómico (`quantity = quantity + N`), nunca con filas duplicadas.

### `whatsapp_accounts` / `whatsapp_phone_numbers` / `message_send_attempts` (FASE 6, ADR-029)

- `whatsapp_accounts` (1 por tenant): `tenant_id` UNIQUE FK `cascadeOnDelete`,
  `whatsapp_business_account_id` (waba_id, nullable), `display_name`, `access_token` **cifrado**
  (atributo `encrypted`), `status` (`connected`/`disconnected`). Un tenant = una WABA en esta fase.
- `whatsapp_phone_numbers`: `tenant_id` FK `cascadeOnDelete`, `whatsapp_account_id` FK
  `nullOnDelete`, `phone_id` UNIQUE (id de Meta, **clave de resolución del webhook**),
  `display_phone_number` E.164, `verified_name`, `quality_rating`, `status`, `is_default`.
- `message_send_attempts`: `tenant_id` FK, `whatsapp_phone_number_id` FK `cascadeOnDelete`,
  `provider_message_id`, `to`, `type`, `payload` (sin secretos), `status`
  (pending/sent/failed), `error_code` (`WHATSAPP_*`), `error_message`, `attempt`/`max_attempts`,
  `attempted_at`. Registra CADA llamada al provider. **FASE 9**: `SendWhatsAppMessage` rellena
  `attempt`/`max_attempts`/`attempted_at` reales y el detalle del error (retryable/error_code) en
  `payload`; el backoff de cola usa `tries()`/`backoff()` del job. Índice `(tenant_id, status)`.

### `contacts` / `tags` / `contact_tag` (FASE 7, ADR-030)
```
contacts
  id uuid PK
  tenant_id FK → tenants (cascadeOnDelete)
  phone varchar(40)              → E.164 canónico con '+' y sin separadores (normalizado)
  name varchar(255)
  email varchar(255) nullable
  avatar_url varchar(2048) nullable
  metadata JSON nullable         → custom fields del tenant
  provider_contact_id varchar(255) nullable → correlación wa_id de Meta (pendiente, FASE 10+)
  last_interaction_at timestamp nullable
  created_at / updated_at / deleted_at (soft delete)
```
- Unicidad por tenant entre activos: índice UNIQUE **parcial**
  `contacts_tenant_phone_unique ON contacts (tenant_id, phone) WHERE deleted_at IS NULL`
  → un contacto soft-deleted libera el teléfono (se puede re-crear).
- Índices: `(tenant_id, created_at)` y `(tenant_id, name)`.

```
tags                 → id uuid PK, tenant_id FK, name varchar(100), UNIQUE (tenant_id, name)
contact_tag          → PK (contact_id, tag_id), FKs cascadeOnDelete
```
`tags`/`contact_tag` existen desde FASE 7 pero sin API/UI (preparadas para FASE 20).

## 5. Índices y constraints recomendados

- `conversations (tenant_id, status, last_message_at DESC)` + `(tenant_id, contact_id)` +
  `(tenant_id, agent_id)` + `(tenant_id, last_interaction_at)` (FASE 8, ADR-031)
- `conversation_participants (conversation_id, user_id)` UNIQUE + índice `(user_id, conversation_id)`
- `conversation_participants (tenant_id, conversation_id)` + `(tenant_id, user_id)` (FASE 15 U1);
  FK compuesta `(tenant_id, conversation_id)` garantiza pertenencia a la conversación del tenant
- `conversation_assignments (conversation_id, assigned_at)` + `(agent_id, assigned_at)` +
  `(tenant_id, conversation_id)` + `(tenant_id, agent_id)`
- `conversation_assignments (conversation_id)` UNIQUE parcial `WHERE unassigned_at IS NULL`
- `conversation_assignments`: FK compuesta `(tenant_id, conversation_id)` a `conversations`
- `conversations (tenant_id, handoff_requested_at)` (FASE 15 U1)
- `messages (sent_by_user_id)` (FASE 15 U1; soporte de FK `nullOnDelete` y consultas por actor)
- `messages (tenant_id, conversation_id, created_at DESC)` + `(conversation_id)` (FASE 9, ADR-032)
- `messages` UNIQUE `(tenant_id, provider_message_id)` (composite; los NULL no colisionan →
  los outbound sin id de Meta son válidos) (FASE 9, ADR-032)
- `contacts (tenant_id, phone)` UNIQUE **parcial** `WHERE deleted_at IS NULL` (FASE 7, ADR-030)
  + índices `(tenant_id, created_at)` y `(tenant_id, name)`
- `flow_nodes (flow_id)`; `flow_nodes` UNIQUE parcial `(flow_id) WHERE is_start`
- `flow_connections (flow_id)`
- `flow_executions (tenant_id, conversation_id)` UNIQUE parcial `WHERE status IN ('running','waiting')`
- `knowledge_chunks (tenant_id, document_id)` + índice vectorial HNSW sobre `embedding`
- `webhook_events (provider_event_id)` UNIQUE + `(status, created_at)` para el sweeper/outbox
- `whatsapp_phone_numbers (phone_id)` UNIQUE
- `tenant_users (tenant_id, user_id)` UNIQUE + índice `(user_id)`
- `tenant_invitations (token)` UNIQUE + `(tenant_id, email)`
- `usage_records (tenant_id, subscription_id, feature, period_start)` UNIQUE
- `audit_logs (tenant_id, created_at DESC)`
- `tags (tenant_id, name)` UNIQUE
- `contact_tag` PK `(contact_id, tag_id)` + índices por ambas FKs
- `leads (tenant_id, status)`
- `users (email)` UNIQUE + índice `(current_tenant_id)`

## 6. Migraciones

Cada módulo crea sus propias migraciones con prefijo de módulo para legibilidad. Las migraciones
deben ser idempotentes y testeadas con `migrate:fresh` en CI.

## 7. pgvector

Instalar extensión: `CREATE EXTENSION IF NOT EXISTS vector;`. Índice HNSW para búsqueda de
similaridad (mejor latencia que IVFFlat para tamaños medianos).

## 8. FASE 21 — Analytics Data Foundation (DDL)

Migraciones verificadas en PostgreSQL 16 con `migrate:up`, `migrate:rollback`, y segundo `migrate:up`.

### analytics_daily

| Columna | Tipo | Constraint |
|---|---|---|
| `id` | uuid | PK |
| `tenant_id` | uuid | NOT NULL, FK→tenants `cascadeOnDelete` |
| `date` | date | NOT NULL |
| `total_messages` | integer | NOT NULL, DEFAULT 0 |
| `inbound_messages` | integer | NOT NULL, DEFAULT 0 |
| `outbound_messages` | integer | NOT NULL, DEFAULT 0 |
| `total_conversations` | integer | NOT NULL, DEFAULT 0 |
| `conversations_started` | integer | NOT NULL, DEFAULT 0 |
| `conversations_closed` | integer | NOT NULL, DEFAULT 0 |
| `avg_response_time_seconds` | integer | NULLABLE |
| `total_flow_executions` | integer | NOT NULL, DEFAULT 0 |
| `total_ai_tokens` | bigint | NOT NULL, DEFAULT 0 |
| `created_at` / `updated_at` | timestamp | — |

- **Constraints**: `UNIQUE (tenant_id, date)`, `idx_analytics_daily_tenant_date`.
- **Sin soft deletes**: datos históricos nunca se eliminan.
- **Sin tenant_id fillable**: protección Anti-Exploration (ADR-077).

### conversation_metrics

| Columna | Tipo | Constraint |
|---|---|---|
| `id` | uuid | PK |
| `tenant_id` | uuid | NOT NULL, FK→tenants `cascadeOnDelete` |
| `conversation_id` | uuid | NOT NULL |
| `total_messages` | integer | NOT NULL, DEFAULT 0 |
| `inbound_messages` | integer | NOT NULL, DEFAULT 0 |
| `outbound_messages` | integer | NOT NULL, DEFAULT 0 |
| `avg_response_time_ms` | integer | NULLABLE |
| `ai_tokens_used` | integer | NOT NULL, DEFAULT 0 |
| `first_response_at` | timestamp | NULLABLE |
| `last_message_at` | timestamp | NULLABLE |
| `created_at` / `updated_at` | timestamp | — |

- **Constraints**: `UNIQUE (tenant_id, conversation_id)`, composite FK `(tenant_id, conversation_id)` → `conversations(tenant_id, id)`.
- **Índices**: `idx_conversation_metrics_tenant_conversation`, `idx_conversation_metrics_tenant_last_message`.
- **Sin soft deletes**: métricas acumuladas se preservan.
- **Anti-Cross-Tenant FK**: FK compuesta garantiza que conversation_id pertenece al mismo tenant.

### U2 — Aggregation Service (runtime, no DDL)

U2 no crea nuevas tablas. Opera sobre las tablas de U1:

- `AggregationService::aggregateForDate()`: usa DB::table para UPSERT en `analytics_daily`
  (no Model::updateOrCreate por bug de date cast en SQLite).
- `ConversationMetric::updateOrCreate()` dentro de transacción con TenantContext
  save/restore para que `BelongsToTenant::creating` obtenga el tenant_id correcto.
- Query SQL: parámetros posicionales (`?`) exclusivamente — PG rechaza mezcla named + positional.
- Schedule: `analytics:aggregate-daily` a las 02:00 UTC, `withoutOverlapping()`.
- Tabla de scheduling: `job_batches` (ya existente en Laravel).

## 9. FASE 15 — Transferencia a humano (DDL)

Migraciones verificadas con UP/DOWN/UP en PostgreSQL 16.

| Tabla | Cambio | Detalle |
|---|---|---|
| `conversation_assignments` | + `tenant_id` | UUID, NOT NULL, FK→tenants `cascadeOnDelete`, índice `(tenant_id, conversation_id)` y `(tenant_id, agent_id)`, FK compuesta `(tenant_id, conversation_id)` → `conversations` |
| `conversation_assignments` | unique parcial | `UNIQUE (conversation_id) WHERE unassigned_at IS NULL` → una assignment abierta por conversación |
| `conversation_participants` | + `tenant_id` | UUID, NOT NULL, FK→tenants `cascadeOnDelete`, índice `(tenant_id, conversation_id)` y `(tenant_id, user_id)`, FK compuesta `(tenant_id, conversation_id)` → `conversations` |
| `messages` | + `sent_by_user_id` | FK→users `nullOnDelete`, índice propio `(sent_by_user_id)` |
| `conversations` | + `handoff_requested_at` | timestamp nullable, índice `(tenant_id, handoff_requested_at)` |
| `conversations` | unique index | `UNIQUE (tenant_id, id)` — integridad referencial tenant-first |

Backfill: `tenant_id` en assignments/participants se deriva exclusivamente de
`conversation_id → conversations.tenant_id`. Abort si no puede derivar.

Migración verificada: PostgreSQL 16 — `migrate:up` completa, `migrate:rollback` revierte,
segundo `migrate:up` re-aplica limpiamente. Filas legacy (sin `tenant_id`) se backfillan
antes de añadir `NOT NULL`.

## 11. FASE 23 — Billing/Plans DDL

### 11.1 plans (global — sin tenant_id)

| Column | Type | Constraints |
|---|---|---|
| id | uuid | PK |
| slug | varchar(100) | UNIQUE, NOT NULL |
| name | varchar(100) | NOT NULL |
| description | text | nullable |
| is_active | boolean | default true |
| price_monthly | decimal(10,2) | default 0 |
| price_yearly | decimal(10,2) | default 0 |
| limits | jsonb | nullable |
| features | jsonb | nullable |
| sort_order | int | default 0 |
| timestamps, soft_deletes | | |

### 11.2 subscriptions (tenant-scoped)

| Column | Type | Constraints |
|---|---|---|
| id | uuid | PK |
| tenant_id | uuid | FK→tenants CASCADE, NOT NULL |
| plan_id | uuid | FK→plans NULL ON DELETE, NOT NULL |
| status | varchar(20) | default 'active' |
| quantity | int | default 1 |
| current_period_start | timestamp | nullable |
| current_period_end | timestamp | nullable |
| metadata | jsonb | default {} |
| timestamps, soft_deletes | | |
| UNIQUE | (tenant_id) WHERE deleted_at IS NULL |

### 11.3 subscription_items (tenant-scoped)

| Column | Type | Constraints |
|---|---|---|
| id | uuid | PK |
| tenant_id | uuid | FK→tenants CASCADE, NOT NULL |
| subscription_id | uuid | FK→subscriptions CASCADE, NOT NULL |
| category | varchar(50) | NOT NULL |
| included_usage | int | NOT NULL |
| per_unit_price | decimal(10,2) | default 0 |
| timestamps, soft_deletes | | |
| UNIQUE | (subscription_id, category) WHERE deleted_at IS NULL |

### 11.4 usage_records (tenant-scoped, append-only)

| Column | Type | Constraints |
|---|---|---|
| id | uuid | PK |
| tenant_id | uuid | FK→tenants CASCADE, NOT NULL |
| subscription_id | uuid | FK→subscriptions SET NULL |
| category | varchar(50) | NOT NULL |
| quantity | int | NOT NULL |
| description | text | nullable |
| metadata | jsonb | nullable |
| recorded_at | timestamp | NOT NULL |
| timestamps | | |

### 11.5 tenants.plan_id FK

- `plan_id` uuid, nullable, FK→plans NULL ON DELETE
- Denormalized cache de la relación active subscription
