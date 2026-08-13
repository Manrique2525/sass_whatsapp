# AGENTS.md — Reglas del proyecto

Guía obligatoria para cualquier agente (IA o humano) que trabaje en este repositorio.

## Propósito del producto

SaaS multi-tenant de chatbots para WhatsApp. Cada negocio (tenant) conecta un número de
WhatsApp Business, automatiza atención al cliente mediante flujos visuales + IA, gestiona
conversaciones, captura leads y transfiere a agentes humanos.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3, Laravel 11/12, Sanctum, Queue, Events, Notifications, Policies |
| Frontend | Vue 3, TypeScript, Tailwind CSS, Vite, Inertia.js |
| DB | PostgreSQL 16 (pgvector) |
| Cache/Queue | Redis |
| Tiempo real | Laravel Reverb + Laravel Echo |
| WhatsApp | Meta WhatsApp Cloud API (oficial). PROHIBIDO usar APIs no oficiales |
| IA | OpenAI API, abstraída tras interfaz |
| Flow Builder | Vue Flow |
| Roles/Permisos | spatie/laravel-permission |
| Storage | S3 compatible (minio local) |
| Testing | Pest (backend), Vitest (frontend), Playwright (E2E) |
| Infra | Docker Compose, GitHub Actions, Sentry, Laravel Telescope (dev) |

## Reglas absolutas

1. **Multi-tenancy desde el inicio.** Toda tabla del dominio tenant tiene `tenant_id`.
   Nunca exponer datos de un tenant a otro. Ver `docs/multi-tenancy.md`.
2. **No APIs de WhatsApp no oficiales.** Solo Meta WhatsApp Cloud API.
3. **Sin código falso.** Prohibido: `return true;` para aparentar, arrays vacíos para ocultar
   errores, métodos `NOT_IMPLEMENTED`, mocks permanentes, datos hardcodeados.
   Si algo no se puede implementar aún: marcar `TODO` claro con explicación.
4. **Sin secretos en Git.** Todo se configura vía `.env`. `.env` nunca se commitea.
5. **Nunca confiar en datos del frontend.** Toda validación de negocio y límites ocurre en backend.
6. **Backend primero.** Cada módulo: DB → backend → tests → frontend → tests → lint → docs → commit.

## Arquitectura

Arquitectura modular inspirada en DDD/Clean (no un monolito Laravel tradicional).
Documentada en `docs/architecture.md`. Estructura base:

```
app/
├── Domain/            # Entidades, value objects, lógica de dominio pura (sin framework)
├── Application/       # Casos de uso / servicios de aplicación (orquestación)
├── Infrastructure/    # Implementaciones concretas (Meta, OpenAI, S3, Stripe)
├── Http/              # Controllers, Middleware, Requests, Resources
└── Jobs/              # Cola de procesamiento asíncrono
```

Convenciones de cada capa:

- **Domain**: sin dependencias de Laravel en lógica pura. Eloquent models viven en
  `app/Domain/<Modulo>/Models` pero solo se usan como puerta de datos; la lógica de negocio
  vive en services de Domain o Application.
- **Application**: un service por caso de uso. Inyecta interfaces (de Domain o Infrastructure),
  nunca implementaciones concretas.
- **Infrastructure**: implementa interfaces (`WhatsAppProviderInterface`, `AIProviderInterface`).
  Una clase por proveedor (Meta, OpenAI...).
- **Http**: solo orquestación HTTP. Validación en `Requests`, respuesta en `Resources`.

## Convenciones de código

- **PHP**: PSR-12, tipado estricto (`declare(strict_types=1)`), PHP 8.3 features.
- **Naming**:
  - Tablas: `snake_case`, plural (`flow_nodes`).
  - Columnas: `snake_case` (`last_interaction_at`).
  - Models: `Singular` (`FlowNode`).
  - Services: `ModuleService` o caso de uso descriptivo (`ProcessIncomingMessage`).
  - Jobs: verbo + sustantivo (`ProcessIncomingWhatsAppMessage`).
  - Interfaces: `<Nombre>Interface`.
  - Implementaciones: `<Proveedor><Nombre>` (`MetaWhatsAppProvider`, `OpenAIProvider`).
- **Rutas API**: `api/v1/<recurso>`, plural, RESTful.
- **Idempotencia**: todo procesamiento de eventos (webhooks) debe ser idempotente.
- **Errores**: excepciones de dominio con mensaje claro; nunca tragar excepciones.

## Multi-tenancy

- Todas las queries del dominio tenant pasan por `TenantContext` (el tenant activo).
- Un usuario puede pertenecer a varios tenants (`users.current_tenant_id` = tenant activo;
  pivot `tenant_users` con rol por tenant: owner/admin/agent). Roles con spatie en modo `teams`
  (`team_id = tenant_id`); `super_admin` es rol global de plataforma.
- Middleware `tenant` resuelve el tenant del usuario y aplica un scope global.
- `Policies` verifican `tenant_id` de la entidad contra el tenant del usuario.
- Redis/S3 compartidos: claves `tenant:{id}:...` y objetos `tenant/{tenant_id}/...`.
- Aislamiento probado: Tenant A jamás lee/actúa sobre datos de Tenant B (403/404).
- Referencia: `docs/multi-tenancy.md`.

## Testing

- **Backend**: Pest. `php artisan test`. Cada módulo exige tests (unit + feature).
- **Frontend**: Vitest.
- **E2E**: Playwright.
- **Cobertura objetivo**: >= 80%, priorizando código crítico (webhooks, motor de flujos,
  aislamiento tenant, billing, seguridad).
- **Regla**: un módulo NO se declara terminado sin tests verdes.

## Comandos obligatorios antes de declarar un módulo DONE

```bash
php artisan test               # suite completa de backend
composer run lint              # o pint (si aplica)
./vendor/bin/phpstan analyze   # análisis estático
php artisan migrate --database=<test>  # migraciones sin errores
npm run build                  # build de frontend sin errores
npm run typecheck              # vue-tsc sin errores
```

## Commits

- Pequeños y descriptivos.
- Formato: `feat(auth): implement authentication`, `fix(whatsapp): handle duplicate webhook events`.
- Un commit por unidad lógica. No commits gigantes con cambios no relacionados.

## Documentación

Toda decisión relevante se registra en `docs/decisions.md` (ADR). Los módulos se documentan
según el formato de reporte (ver `docs/roadmap.md`).

## Estado actual

Ver `docs/roadmap.md` para el estado de cada fase. Solo trabajar sobre la fase activa.
