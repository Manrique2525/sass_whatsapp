# Arquitectura

Estado: **Borrador aprobado** · Última revisión: FASE 7

## 1. Objetivo

Construir un SaaS multi-tenant de chatbots para WhatsApp (similar a WATI/Manychat) orientado
a pequeños y medianos negocios. Prioridad: **seguridad, aislamiento entre tenants, escalabilidad
horizontal y mantenibilidad**, sin sobre-arquitecturar.

## 2. Decisiones de alto nivel

| Decisión | Elección | Justificación |
|---|---|---|
| Arquitectura de aplicación | Modular DDD-inspired (no monolito tradicional) | Separación de responsabilidades, testabilidad, límites claros entre proveedores externos |
| Multi-tenancy | Shared database + `tenant_id` | Costo/beneficio correcto para 1K–10K tenants; evita la complejidad operativa de DB-per-tenant |
| Tiempo real | Laravel Reverb (WebSockets) + Echo | Mismo ecosistema Laravel, sin infra externa adicional |
| WhatsApp | Meta WhatsApp Cloud API (oficial) | Única opción legal y soportada. Prohibido APIs no oficiales |
| IA | OpenAI vía `AIProviderInterface` | Desacoplar proveedor; sustituible (Anthropic, Azure, local) |
| Base de conocimiento | pgvector (PostgreSQL) | Una sola base de datos, embeddings nativos, sin Elasticsearch extra |
| Frontend | SPA Vue 3 + TypeScript + Inertia | Rutas de negocio server-driven con Inertia; coste de desarrollo menor que SPA puro |
| Async | Laravel Queue + Redis | El webhook responde rápido y delega el trabajo al worker |

## 3. Estructura de carpetas

```
app/
├── Domain/                      # Lógica de dominio pura (sin framework)
│   ├── Tenants/
│   │   ├── Models/              # Eloquent models (puerta de datos)
│   │   └── Services/            # Lógica de negocio del dominio
│   ├── Users/
│   ├── WhatsApp/
│   ├── Contacts/
│   ├── Conversations/
│   ├── Messages/
│   ├── Chatbots/
│   ├── Flows/
│   ├── Agents/
│   ├── AI/
│   ├── KnowledgeBase/
│   ├── Billing/
│   ├── Analytics/
│   └── Notifications/
│
├── Application/                 # Casos de uso (orquestación)
│   ├── Tenants/
│   ├── WhatsApp/
│   ├── Conversations/
│   ├── Chatbots/
│   ├── AI/
│   └── Billing/
│
├── Infrastructure/              # Implementaciones de proveedores
│   ├── WhatsApp/                # MetaWhatsAppProvider
│   ├── AI/                      # OpenAIProvider
│   ├── Storage/                 # S3
│   └── Billing/                 # StripeProvider (adapter)
│
├── Http/                        # Solo orquestación HTTP
│   ├── Controllers/
│   ├── Middleware/              # tenant, etc.
│   ├── Requests/                # Validación
│   └── Resources/               # Respuestas API
│
└── Jobs/                        # Cola asíncrona
```

Razón de la estructura: mantener la lógica de negocio independiente de Laravel, de los
proveedores externos y del transporte HTTP. Los controllers quedan delgados y la lógica se
puede probar sin HTTP ni red.

## 4. Diagrama de componentes

```
                    ┌──────────────────────────────────────┐
                    │            Navegador (SPA)           │
                    │   Vue 3 + TS + Tailwind + Echo       │
                    └──────────────┬───────────────────────┘
                                   │ HTTPS / Inertia / Axios
                    ┌──────────────▼───────────────────────┐
                    │              Laravel                 │
                    │  Http/Controllers + Middleware       │
                    │  (tenant, auth:sanctum, throttle)    │
                    └──┬───────────────┬───────────────────┘
                       │               │
              ┌────────▼──────┐  ┌─────▼──────────────────┐
              │  Application  │  │   Reverb (WebSockets)  │
              │  (casos de    │  └───────────▲────────────┘
              │   uso)        │              │ broadcast
              └──┬────────┬───┘   ┌──────────┴──────────┐
                 │        │       │  Queue Workers      │
        ┌────────▼───┐  ┌─▼────────▼─────┐              │
        │  Domain    │  │  Jobs          │              │
        └────────┬───┘  └─┬────────┬─────┘              │
                 │        │        │                    │
     ┌───────────▼────────▼───┐  ┌─▼──────────┐   ┌─────▼──────┐
     │   PostgreSQL (pgvector)│  │   Redis    │   │  S3 (minio)│
     └────────────────────────┘  └────────────┘   └────────────┘
```

Flujo del mensaje entrante (WhatsApp → Inbox):

1. Meta envía webhook a `POST /api/webhooks/whatsapp`. **Implementado en FASE 6.**
2. El controller valida firma `X-Hub-Signature-256` (App Secret global de la app) sobre el
   **cuerpo crudo**, y hace **dedupe** insertando `webhook_events` con UNIQUE `provider_event_id`
   (`ON CONFLICT DO NOTHING`). Resuelve el tenant por `metadata.phone_number_id`.
   **Implementado en FASE 6.**
3. Responde `200` de inmediato y despacha `ProcessIncomingWhatsAppMessage` a la cola
   (`forTenant($tenantId)`). Un **sweeper (outbox)** re-encola eventos huérfanos para no perder
   ninguno. **FASE 6**: ingestión + acuse (jobs marcan `processed`) y encolado en el mismo
   request; el sweeper llega con la fase de mensajería (FASE 9).
4. El worker (con `TenantContext` propio): localiza número → busca o crea Contact → crea
   Conversation si aplica → guarda Message → ejecuta el motor de flujos **bajo lock de Redis
   por conversación** o notifica a agentes → emite eventos/broadcasts.
   **FASE 7**: contactos implementados (el find-or-create por teléfono E.164 que usa el worker
   ya existe, ver `ContactService::findOrCreateForPhone`). Conversation/Message/engine en FASE 9+.

Autenticación (dos modos, ADR-011):

- **Interno**: Sanctum stateful (cookies + CSRF) para el SPA Inertia en el mismo origen.
- **Externo**: tokens Bearer Sanctum para integraciones.
- Ambos resuelven el tenant desde `users.current_tenant_id` (multi-tenant, ver ADR-012/016).

Aislamiento de infraestructura compartida:

- **Redis**: toda clave de cache/lock/rate-limit lleva prefijo `tenant:{id}:`.
- **S3**: objetos bajo `tenant/{tenant_id}/...` con URLs firmadas del propio tenant.

## 5. Módulos

| Módulo | Responsabilidad | Interface clave |
|---|---|---|
| Tenants | Identidad del negocio, estado, configuración | `TenantContext` |
| Users | Usuarios (multi-tenant), roles por tenant (spatie teams), invitaciones | spatie/laravel-permission |
| WhatsApp | Cuentas/números, webhooks (dedupe + outbox), envío | `WhatsAppProviderInterface` |
| Contacts | CRM mínimo: contactos (E.164, soft delete, unique parcial por tenant), etiquetas | — |
| Conversations | Sesiones de chat, estados, asignaciones | — |
| Messages | Historial, estados (sent/delivered/read) | — |
| Chatbots | Chatbots, flujos, triggers | `ChatbotEngine` |
| Flows | Definición de nodos/conexiones (Flow Builder) | — |
| Agents | Usuarios que atienden conversaciones | — |
| AI | IA generativa + RAG | `AIProviderInterface` |
| KnowledgeBase | Documentos, chunks, embeddings (pgvector) | — |
| Billing | Planes, suscripciones, límites de uso | `BillingProviderInterface` |
| Analytics | Métricas agregadas | — |
| Notifications | Email, internas, alertas | Laravel Notifications |

## 6. Flujo de trabajo por módulo

Obligatorio (ver AGENTS.md): DB → backend → tests → frontend → tests → lint → docs → commit.
Un módulo solo se declara DONE cumpliendo la "Definición de Done" de `docs/roadmap.md`.

## 7. Escalabilidad

- La carga se procesa en la cola; los workers escalan horizontalmente.
- Reverb escala con más servidores/nodos.
- Consultas pesadas (analytics) usan tablas de agregados + Redis cache.
- El motor de flujos es determinista y guarda estado de ejecución (FlowExecution) para
  reanudar conversaciones ante fallos.

## 8. Referencias

- Base de datos: `docs/database.md`
- Multi-tenancy: `docs/multi-tenancy.md`
- WhatsApp: `docs/whatsapp.md`
- Motor de flujos: `docs/chatbot-engine.md`
- IA: `docs/ai.md`
- Seguridad: `docs/security.md`
- Despliegue: `docs/deployment.md`
