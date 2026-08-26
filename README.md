# SaaS WhatsApp Chatbot Platform

Plataforma SaaS multi-tenant para chatbots de WhatsApp. Cada negocio conecta un número de WhatsApp Business, automatiza atención al cliente con flujos visuales + IA, gestiona conversaciones, captura leads y transfiere a agentes humanos.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3, Laravel 11, Sanctum, Queue, Events |
| Frontend | Vue 3, TypeScript, Tailwind CSS, Vite, Inertia.js |
| DB | PostgreSQL 16 (pgvector) |
| Cache/Queue | Redis |
| Tiempo real | Laravel Reverb + Laravel Echo |
| WhatsApp | Meta WhatsApp Cloud API (oficial) |
| IA | OpenAI API (abstracta) |
| Flow Builder | Vue Flow |
| Roles/Permisos | spatie/laravel-permission |
| Storage | S3 compatible (MinIO local) |
| Testing | Pest (backend), Vitest (frontend) |
| Infra | Docker Compose |

## Arquitectura modular

```
app/
├── Domain/         # Entidades, value objects, lógica de dominio pura
├── Application/    # Casos de uso / servicios de orquestación
├── Infrastructure/ # Implementaciones concretas (Meta, OpenAI, S3, Stripe)
├── Http/           # Controllers, Middleware, Requests, Resources
└── Jobs/           # Cola de procesamiento asíncrono
```

## Quickstart

```bash
# Clonar e instalar
git clone <repo-url> && cd whatsapp-saas
composer install && npm install

# Copiar .env y configurar
cp .env.example .env
php artisan key:generate

# Levantar servicios
docker compose up -d

# Migrar y sembrar
php artisan migrate --seed

# Desarrollo
npm run dev
php artisan serve
```

## Testing

```bash
# Backend (Pest)
php artisan test

# Frontend (Vitest)
npm run test

# Análisis estático
./vendor/bin/phpstan analyze
./vendor/bin/pint --test
```

## Seguridad

- Multi-tenancy: aislamiento estricto por `tenant_id` en todas las queries del dominio.
- Webhooks: rate limiting, firma HMAC-SHA256, idempotencia.
- Provider errors: los errores raw de Meta/OpenAI/Stripe nunca se exponen al cliente; se registran en log.
- LIKE queries: wildcard escaping para prevenir bypass de búsqueda.
- Secretos: todo se configura vía `.env`. Nunca se commitean credenciales.

## Documentación

| Documento | Descripción |
|---|---|
| [architecture.md](docs/architecture.md) | Arquitectura modular, decisiones de alto nivel |
| [multi-tenancy.md](docs/multi-tenancy.md) | Estrategia de aislamiento entre tenants |
| [security.md](docs/security.md) | Políticas de seguridad |
| [api.md](docs/api.md) | Contrato de API REST |
| [roadmap.md](docs/roadmap.md) | Estado de cada fase |
| [decisions.md](docs/decisions.md) | Architecture Decision Records (ADR) |
| [testing.md](docs/testing.md) | Estrategia de testing |

## License

Proprietary. Todos los derechos reservados.
