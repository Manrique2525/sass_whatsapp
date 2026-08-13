# Multi-tenancy

## 1. Estrategia

**Shared database + `tenant_id`** en todas las tablas del dominio tenant.

- Costo correcto para 1K–10K tenants.
- Un único PostgreSQL con buenos índices.
- Migración futura a shards/pools viable porque las PK son UUID y las queries siempre pasan
  por `TenantContext`.

## 2. Modelo de usuarios y tenants

Un usuario puede pertenecer a **múltiples tenants** con **roles distintos por tenant**,
y tiene un **tenant activo** seleccionable:

- `users`: identidad global (email único). Columna `current_tenant_id` (nullable, FK→tenants)
  = tenant activo del usuario (lo usa el middleware `tenant`).
- `tenant_users`: pivot `tenant_id + user_id + role + status`. `role` ∈
  {`owner`, `admin`, `agent`}; `status` ∈ {`active`, `invited`, `disabled`}.
  UNIQUE `(tenant_id, user_id)`.
- Roles por tenant: se implementa con **spatie/laravel-permission en modo `teams`**
  (`team_id = tenant_id`). Así `owner/admin/agent` se asignan por tenant. `super_admin` es un
  rol global de plataforma (sin team). Ver ADR-012.
- Cambio de tenant activo: `POST /api/v1/auth/switch-tenant {tenant_id}` (valida membresía
  en `tenant_users`), actualiza `users.current_tenant_id` y devuelve las credenciales nuevas.
  El cliente Reverb debe re-suscribirse a los canales del nuevo tenant.
- Invitaciones: `tenant_invitations` (email, role, token, expires_at, tenant_id, status).
- Regla: el middleware `tenant` resuelve el tenant desde `users.current_tenant_id`, NUNCA desde
  un parámetro de la URL (excepto `switch-tenant`, que valida membresía antes de cambiar).

## 3. Piezas del sistema

### 3.1 `TenantContext`

Clase singleton que mantiene el tenant activo durante la request/job:

```php
TenantContext::set(Tenant $tenant);
TenantContext::tenant(): Tenant;       // lanza excepción si no hay tenant
TenantContext::id(): string;           // tenant_id actual
TenantContext::clear();                // fin de request/job (evita fugas)
```

- En **HTTP**: el middleware `tenant` lo establece desde el usuario autenticado.
- En **Jobs**: cada job de dominio tenant inicia con `TenantContext::set()` explícito y termina
  con `TenantContext::clear()` en un bloque `finally` (los workers son procesos de larga
  duración; sin `clear()` el contexto de un job fugaría al siguiente). NUNCA dependen del tenant
  de quien encoló.
- En **CLI/seeders**: se fija el tenant de trabajo al inicio.
- Si un model con scope se consulta sin contexto: en dev/CI lanza error claro; en producción
  el query no devuelve filas (nunca filas de otro tenant). La norma es fallar rápido en dev.

### 3.2 Middleware `tenant`

```php
public function handle(Request $request, Closure $next): mixed
{
    $user = $request->user();
    $tenant = $user->currentTenant;      // users.current_tenant_id
    abort_unless($tenant, 403);
    TenantContext::set($tenant);
    return $next($request);
}
```

Aplica a todas las rutas `api/v1/*` (excepto auth global y webhooks). Los webhooks NO usan este
middleware: resuelven el tenant por `metadata.phone_number_id` del payload (ver `whatsapp.md`).

### 3.3 Model scopes

Trait `BelongsToTenant` añadido a todos los models de dominio tenant:

```php
protected static function booted(): void
{
    static::addGlobalScope('tenant', function (Builder $query) {
        $query->where('tenant_id', TenantContext::id());
    });
    static::creating(function ($model) {
        $model->tenant_id = TenantContext::id();   // forzado, nunca desde el request
    });
}
```

Protege a todo query olvidadizo. El scope se aplica a lecturas y escrituras. El `creating` hook
garantiza que NINGÚN insert lleve un `tenant_id` ajeno o nulo.

### 3.4 Policies

Aunque exista el scope, las operaciones de detalle y escritura se refuerzan con Policies:

```php
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->tenant_id === TenantContext::id();
    }
}
```

### 3.5 Forzado de `tenant_id` en escrituras

Los casos de uso de Application siempre asignan `tenant_id` desde `TenantContext::id()`.
Nunca se acepta `tenant_id` desde el request (se ignora o se rechaza). El hook `creating`
de `BelongsToTenant` es el último resguardo.

## 4. Manejo de "acceso indebido"

- **Lectura de entidad de otro tenant**: el scope la excluye → `ModelNotFoundException` → **404**.
- **Escritura/update sobre entidad de otro tenant**: 404 (no existe desde la perspectiva del tenant).
- **Policies**: retorno `403` cuando aplique.
- **Regla**: nunca revelar la existencia de datos ajenos. Preferir **404** en lecturas y
  **403** solo cuando sea seguro (p. ej. acciones de administración).
- El recurso raíz `tenants` (sin scope propio): el controller valida SIEMPRE que el usuario
  pertenezca a ese tenant vía `tenant_users` antes de devolver nada (404 en otro caso).

## 5. Aislamiento en colas, eventos y notificaciones

- Cada job de dominio tenant comienza re-estableciendo `TenantContext` y termina con `clear()`
  en `finally` (ADR-008). Nunca confiar en estado del proceso.
- Los `Event` y `Notification` que viajan a la cola serializan `tenant_id` y lo restauran al
  procesarse (listeners/notifications que leen DB en cola deben re-establecer el contexto).
- Los broadcasts de Reverb se canalizan por canal privado con scope al tenant:
  `private-tenant.{tenant_id}.conversations`. La autorización del canal (`channels.php`) valida
  que el usuario autenticado pertenece al tenant (`tenant_users`) — no basta con estar autenticado.

## 6. Aislamiento de cache (Redis) y Storage (S3)

Redis y S3 son **compartidos entre tenants**. Ninguna clave/objeto puede ser legible por un
tenant distinto del propietario:

- **Cache**: todas las claves se prefijan con `tenant:{id}:` (p. ej.
  `tenant:{id}:analytics:overview`). Nunca cachear datos de un tenant bajo una clave global.
- **Rate limits / throttle**: las claves incluyen `tenant_id` (o `user_id`) además del recurso.
- **Redis locks**: las claves de lock incluyen el tenant (`lock:tenant:{id}:flow:{conversation}`).
- **Storage S3**: prefijo `tenant/{tenant_id}/...` en el bucket. Las URLs firmadas se generan
  solo para objetos del propio tenant y expiran.
- **Sesiones/colas**: los payloads de jobs llevan `tenant_id` explícito (las colas no se
  particionan por tenant).

## 7. Aislamiento en IA y base de conocimiento

- La query de similaridad de embeddings SIEMPRE filtra `tenant_id` del buscador.
- El prompt RAG solo recibe chunks del tenant.
- Ver tests en `docs/testing.md`.

## 8. Super admin / soporte

- Rol `super_admin` (plataforma) puede operar cross-tenant **solo** mediante servicios
  explícitos de Application (nunca queries directas desde controllers) y queda registrado
  en `audit_logs`. Cuando opera sin tenant concreto, los models con scope exigen
  `withoutGlobalScope('tenant')` explícito dentro de esos servicios.

## 9. Tests obligatorios de aislamiento

1. Tenant A NO puede ver conversación de Tenant B → 403/404.
2. Tenant A NO puede enviar mensaje a contacto de Tenant B → 403/404.
3. Tenant A NO puede recuperar chunks de Knowledge Base de Tenant B.
4. El worker de cola que procesa datos del Tenant B no contamina el contexto del Tenant A.
5. Un webhook de un número del Tenant B no escribe en datos del Tenant A.
6. Una clave de cache del Tenant A no es legible bajo el namespace del Tenant B.
7. Un objeto de Storage del Tenant A no es accesible por el Tenant B.
8. Un canal Reverb del Tenant B rechaza la suscripción de un usuario del Tenant A.
