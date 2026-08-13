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
  ├─ conversations 1─N messages · 1─N conversation_participants
  ├─ chatbots 1─N flows 1─N flow_nodes 1─N flow_connections
  ├─ flow_executions
  ├─ leads
  ├─ knowledge_base 1─N knowledge_documents 1─N knowledge_chunks
  ├─ faqs
  ├─ audit_logs
  ├─ subscriptions 1─N usage_records
  └─ analytics_daily
```

## 3. Tablas por módulo

### Plataforma (sin tenant_id)

| Tabla | Propósito | Notas |
|---|---|---|
| `users` | Usuarios globales | email único; `password`, `email_verified_at`, `current_tenant_id` nullable FK→tenants (tenant activo) |
| `tenants` | Negocios | `name`, `slug` único, `status`, `plan_id`, `timezone`, `locale` |
| `tenant_users` | Relación user-tenant | `tenant_id`, `user_id`, `role` (owner/admin/agent), `status` (active/invited/disabled), `invited_at`/`joined_at`; UNIQUE `(tenant_id, user_id)` |
| `tenant_invitations` | Invitaciones a un tenant (FASE 4, ADR-027) | `tenant_id`, `email`, `role`, `token_hash` (sha256, único; nunca el token plano), `invited_by`, `status` (pending/accepted/revoked/expired), `expires_at` (7 días), `accepted_at` |
| `plans` | Planes (FREE/PRO/BUSINESS) | límites en `limits` JSON |
| `webhook_events` | Eventos crudos recibidos de Meta | Nivel plataforma: `provider_event_id` UNIQUE (id global de Meta), `tenant_id` nullable (se resuelve por phone_number_id), `status` (received/enqueued/processed/failed) |
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

### `webhook_events` (plataforma, ver arriba)

```
id uuid PK
provider_event_id varchar(255) UNIQUE   → id de Meta: messages[].id o statuses[].id
tenant_id FK → tenants (nullable, se resuelve por metadata.phone_number_id)
payload JSONB                            → crudo (mínimo necesario)
status enum(received, enqueued, processed, failed)
event_type enum(message, status)
duplicate boolean
created_at / processed_at nullable
```

## 4. Columnas críticas

### `conversations`
```
id uuid PK
tenant_id FK → tenants
contact_id FK → contacts
status enum(open, pending, resolved, archived)
last_message_at timestamp
last_interaction_at timestamp
assignment: agent_id nullable, auto_assigned boolean
context JSONB  → variables de conversación ({{custom.x}})
bot_paused boolean → handoff a humano
flow_execution_id nullable → ejecución activa
timestamps
```

### `messages`
```
id uuid PK
tenant_id FK
conversation_id FK
provider_message_id varchar(255) nullable  → idempotencia (unique con tenant)
direction enum(inbound, outbound)
type enum(text, image, audio, video, document, location, interactive, template)
status enum(pending, sent, delivered, read, failed)
body text
media_url / media_mime / media_size nullable
metadata JSONB
sent_at, delivered_at, read_at, failed_at nullable
```

### `flow_nodes`
```
id uuid PK
tenant_id FK
flow_id FK → flows
type enum(message, buttons, question, condition, delay, tag, webhook, ai, human, end)
name varchar(255)
position_x / position_y int → editor Vue Flow
config JSONB → contenido específico del nodo (texto, botones, condición, prompt IA, ...)
is_start boolean → único nodo de entrada
```

Constraint: UNIQUE `(flow_id)` WHERE `is_start` → un solo nodo de entrada por flujo.

### `flow_connections`
```
id uuid PK
tenant_id FK
flow_id FK
source_node_id FK → flow_nodes
target_node_id FK → flow_nodes
label varchar nullable → condición/resultado de rama
```

### `flow_executions`
```
id uuid PK
tenant_id FK
flow_id FK
conversation_id FK
current_node_id FK nullable
status enum(running, waiting, completed, failed, handed_off)
variables JSONB
attempts int → reintentos ante fallo
```

Constraint: UNIQUE parcial `(tenant_id, conversation_id) WHERE status IN ('running','waiting')`
→ una sola ejecución activa por conversación (evita doble ejecución).

### `knowledge_chunks`
```
id uuid PK
tenant_id FK
document_id FK → knowledge_documents
content text
embedding vector(1536) → índice IVFFlat o HNSW
token_count int
```

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

### `whatsapp_accounts` / `whatsapp_phone_numbers`

- `whatsapp_accounts`: `tenant_id`, `waba_id` UNIQUE por tenant, `access_token` **cifrado**
  (atributo `encrypted`), `status`. Un tenant puede tener varios WABA (uno por número conectado).
- `whatsapp_phone_numbers`: `tenant_id`, `account_id`, `phone_id` UNIQUE (id de Meta, clave de
  resolución del webhook), `phone_number` E.164, `verified`, `quality_rating`, `status`.

## 5. Índices y constraints recomendados

- `conversations (tenant_id, status, last_message_at DESC)` + `(tenant_id, contact_id)`
- `messages (tenant_id, conversation_id, created_at DESC)`
- `messages` UNIQUE parcial `(tenant_id, provider_message_id) WHERE provider_message_id IS NOT NULL`
- `contacts (tenant_id, phone)` UNIQUE (phone normalizado E.164)
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
- `leads (tenant_id, status)`
- `users (email)` UNIQUE + índice `(current_tenant_id)`

## 6. Migraciones

Cada módulo crea sus propias migraciones con prefijo de módulo para legibilidad. Las migraciones
deben ser idempotentes y testeadas con `migrate:fresh` en CI.

## 7. pgvector

Instalar extensión: `CREATE EXTENSION IF NOT EXISTS vector;`. Índice HNSW para búsqueda de
similaridad (mejor latencia que IVFFlat para tamaños medianos).
