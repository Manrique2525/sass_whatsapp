# Multi-tenancy

Estado: **implementado en FASE 3 + FASE 4 + FASE 5 + FASE 6 + FASE 7 + FASE 8 + FASE 9**. Este documento describe el
diseño y cómo quedó materializado en código (rutas, clases y semántica HTTP reales).

## 1. Estrategia

**Shared database + `tenant_id`** en todas las tablas del dominio tenant.

- Costo correcto para 1K–10K tenants.
- Un único PostgreSQL con buenos índices.
- Migración futura a shards/pools viable porque las PK son UUID y las queries siempre pasan
  por `TenantContext`.

## 2. Modelo de usuarios y tenants

Un usuario puede pertenecer a **múltiples tenants** con **roles distintos por tenant**,
y tiene un **tenant activo** seleccionable:

- `users`: identidad global (email único). Columna `current_tenant_id` (nullable, **FK→tenants
  `nullOnDelete`** desde FASE 3) = tenant activo del usuario (lo usa el middleware `tenant`).
- `tenants`: `id` (UUID), `name`, `slug` (unique), `status` (enum `TenantStatus`:
  `active`/`suspended`), `timezone`, `locale`, `settings` (json).
- `tenant_users`: pivot `tenant_id + user_id + role + status`. `role` ∈
  {`owner`, `admin`, `agent`}; `status` ∈ {`active`, `invited`, `disabled`}.
  UNIQUE `(tenant_id, user_id)`. **FK→tenants `cascadeOnDelete`** desde FASE 3.
- `tenant_invitations` (FASE 4, ADR-027): invitación por email con `token_hash` (sha256),
  `status` ∈ {`pending`, `accepted`, `revoked`, `expired`} y `expires_at` (7 días). Solo se
  persiste el hash; el token plano viaja solo en el enlace `/invitations/{token}`.
- `business_profiles` (FASE 5, ADR-028): perfil 1:1 del negocio (`tenant_id` UNIQUE, FK
  `cascadeOnDelete`). Modelo con trait `BelongsToTenant` (`app/Domain/Business/Models/
  BusinessProfile.php`). Se crea bajo demanda en la primera lectura.
- `whatsapp_accounts` / `whatsapp_phone_numbers` / `message_send_attempts` (FASE 6, ADR-029):
  modelos con trait `BelongsToTenant` en `app/Domain/WhatsApp/Models`. Una cuenta por tenant con
  el `access_token` cifrado; `whatsapp_phone_numbers.phone_id` (id de Meta) es la **clave de
  resolución del webhook**.   `webhook_events` es tabla de **plataforma** (sin scope): un evento de
  Meta es único a nivel global y llega sin tenant resuelto; el `tenant_id` se rellena al
  resolver el `metadata.phone_number_id`.
- `contacts` / `tags` / `contact_tag` (FASE 7, ADR-030): `contacts` con trait `BelongsToTenant`
  (scope + forzado de `tenant_id`) y **soft delete**. La unicidad del teléfono es por tenant y
  solo entre contactos activos (índice UNIQUE parcial `(tenant_id, phone) WHERE deleted_at IS
  NULL`): un contacto borrado libera el número. `Tenant::contacts()` (hasMany). `findOrCreateForPhone`
  (uso interno de los jobs del webhook, FASE 9) busca fuera del scope pero SIEMPRE filtrando por
  `tenant_id` del tenant resuelto. Los tags pertenecen a `Contact`, no a `Conversation`; la
  ejecución automática del trigger `tag` se difiere a FASE 20 (ADR-050), que deberá resolver el
  tenant desde su writer centralizado y nunca desde IDs aportados por el cliente.
- `conversations` / `conversation_participants` / `conversation_assignments` (FASE 8, ADR-031):
  los tres modelos usan `BelongsToTenant`; U1 de FASE 15 añadió `tenant_id` UUID NOT NULL + FK e
  índices tenant-first a assignments/participants mediante backfill determinista desde la
  conversación. `conversations` mantiene `contact_id` del mismo tenant (validado en el servicio)
  y soft delete. `Tenant::conversations()` (hasMany). `findOrCreateActiveForContact` busca fuera
  del scope pero SIEMPRE filtrando por `tenant_id` del tenant resuelto.
- Roles por tenant: se implementa con **spatie/laravel-permission en modo `teams`**
  (`team_id = tenant_id`). Así `owner/admin/agent` se asignan por tenant. `super_admin` es un
  rol global de plataforma (sin team). Ver ADR-012, ADR-018 y ADR-025 (migración de
  `tenant_id` de spatie a UUID). El team lo resuelve `TenantTeamResolver`
  (`app/Infrastructure/Tenancy/TenantTeamResolver.php`): override explícito →
  `TenantContext::id()` → `users.current_tenant_id` → `null` (roles globales).
- La autorización por tenant (FASE 4, ADR-026) exige SIEMPRE tres condiciones:
  `current_tenant_id == tenant` (tenant activo) + `tenant_users.status = active` + permiso en la
  matriz de código `TenantPermission::permissionsForRole(rol)` (24 permisos; FASE 5 añade
  `business_profile.view/update`; FASE 6 añade `whatsapp.view`/`whatsapp.manage`; FASE 7 añade
  `contacts.view`/`contacts.manage`; FASE 8 añade `conversations.view`/`conversations.manage`/
  `conversations.assign`; FASE 11 añade `flows.view`/`flows.manage`; FASE 15 añade
  `conversations.claim`). Sin membresía
  o inactivo → **404**; sin permiso → **403**
  `PERMISSION_DENIED`. Los roles spatie se mantienen como espejo de `tenant_users.role` vía
  `TenantRoleManager` (`syncRoles` reemplaza, nunca suma).
- Cambio de tenant activo: `POST /api/v1/tenants/{tenant}/switch` (valida membresía en
  `tenant_users` + tenant activo), actualiza `users.current_tenant_id`, audita y dispara
  `TenantSwitched`. El cliente Reverb debe re-suscribirse a los canales del nuevo tenant.
- Regla: el middleware `tenant` resuelve el tenant desde `users.current_tenant_id`, NUNCA desde
  un parámetro de la URL (excepto `switch`, que valida membresía antes de cambiar).

## 3. Piezas del sistema

### 3.1 `TenantContext`

Clase estática (`app/Infrastructure/Tenancy/TenantContext.php`) que mantiene el tenant activo
durante la request/job:

```php
TenantContext::set(Tenant $tenant);   // fija tenant + id
TenantContext::setId(string $id);     // fija solo el id (jobs, sin cargar el modelo)
TenantContext::tenant(): ?Tenant;     // modelo (null si solo hay id)
TenantContext::id(): ?string;         // tenant_id actual (null sin contexto)
TenantContext::bound(): bool;         // ¿hay id de tenant activo?
TenantContext::clear();               // fin de request/job (evita fugas)
```

- En **HTTP**: el middleware `tenant` lo establece desde el usuario autenticado y lo libera en
  un `finally` (incluso si el handler lanza excepción).
- En **Jobs**: cada job de dominio tenant usa el trait `TenantAwareJob` (ADR-021) que establece
  `TenantContext::setId()` desde su propio payload y lo limpia en `finally`. NUNCA dependen del
  tenant de quien encoló.
- En **CLI/seeders**: se fija el tenant de trabajo al inicio.
- **Fail-safe**: sin contexto, los modelos con `BelongsToTenant` fallan de forma segura —
  lecturas devuelven vacío (`TenantScope` → `whereRaw('1 = 0')`), escrituras lanzan
  `TenantContextMissingException` (ADR-020).

### 3.2 Middleware `tenant`

`app/Http/Middleware/TenantMiddleware.php`, alias `tenant` (ver `bootstrap/app.php`):

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if (! $user instanceof User) {
        return $this->deny($request);               // 403 NO_TENANT / abort(403)
    }
    $tenant = $user->currentTenant;
    if ($tenant === null || ! $tenant->isActive() || ! $user->belongsToTenant($tenant)) {
        return $this->deny($request);               // mismo 403
    }
    TenantContext::set($tenant);
    try {
        return $next($request);
    } finally {
        TenantContext::clear();
    }
}
```

Comportamiento real:
- Rechaza (403) si el usuario no tiene tenant activo, si el tenant está suspendido, o si
  `current_tenant_id` quedó obsoleto (el usuario ya no pertenece). La pertenencia se valida
  SIEMPRE contra `tenant_users`, no se confía en el valor persistido.
- En rutas `api/*` devuelve JSON `{message: 'Sin tenant activo.', code: 'NO_TENANT'}`; en web
  `abort(403)`.
- Aplica a los recursos del tenant (`routes/api.php`): `show`/`update` de `tenants`. Los
  endpoints de auth y `switch` NO lo usan (no requieren contexto previo).

### 3.3 Model scopes

Trait `BelongsToTenant` (`app/Domain/Tenants/Traits/BelongsToTenant.php`) añadido a todos los
models de dominio tenant:

```php
protected static function bootBelongsToTenant(): void
{
    static::addGlobalScope(new TenantScope);       // aislamiento en lecturas
    static::creating(function (Model $model): void {
        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            throw new TenantContextMissingException(...);  // fallo seguro en escrituras
        }
        $model->setAttribute('tenant_id', $tenantId);      // forzado, nunca del request
    });
}
```

`TenantScope` (`app/Domain/Tenants/Scopes/TenantScope.php`):
- Con contexto: `WHERE tenant_id = TenantContext::id()` (columna cualificada por modelo).
- Sin contexto: `WHERE 1 = 0` → vacío, jamás expone datos de ningún tenant.

Las lecturas cross-tenant de administración se hacen SOLO desde servicios de aplicación
autorizados mediante `scopeWithoutTenantScope()`.

### 3.4 Policies

`app/Policies/TenantPolicy.php` (capa programática: `authorize()` y `can:viewAny` del index;
**las rutas no usan policies** para show/update/switch, el enforcement efectivo lo hacen los
Application Services + controller → **404/409**, ver §4):

```php
viewAny(User $user): bool                       // true (la lista se filtra por membresía)
view(User $user, Tenant $tenant): bool          // belongsToTenant
update(User $user, Tenant $tenant): bool        // belongsToTenant
switch(User $user, Tenant $tenant): bool        // belongsToTenant && isActive
```

El servicio de aplicación (`TenantService`) exige además que sea el **tenant activo** para
`show`/`update` (otro tenant al que se pertenezca requiere hacer `switch` primero) → 404.

### 3.5 Forzado de `tenant_id` en escrituras

Los casos de uso de Application siempre asignan `tenant_id` desde `TenantContext::id()`.
Nunca se acepta `tenant_id` desde el request (se ignora o se rechaza — test
`RequestTenantIdTamperTest`). El hook `creating` de `BelongsToTenant` es el último resguardo.

## 4. Manejo de "acceso indebido"

- **Lectura de entidad de otro tenant**: el scope la excluye → `ModelNotFoundException` → **404**.
- **Escritura/update sobre entidad de otro tenant**: 404 (no existe desde la perspectiva del tenant).
- **Recurso raíz `tenants`**: `show`/`update`/`switch` NO usan policy en la ruta; el controller
  valida via `TenantService`/`SwitchTenant` (`belongsToTenant` + tenant activo). No-miembro /
  no-activo → **404** (oculta existencia, nunca 403); miembro de tenant suspendido → **409**.
- **Policies**: quedan como capa programática (`authorize()`); no producen oráculo 403 en rutas.
- **Regla**: nunca revelar la existencia de datos ajenos. Preferir **404** en lecturas.
- **Switch** (ADR-023): no-miembro → 404; miembro de tenant suspendido → 409 `TENANT_NOT_ACTIVE`.
- **Usuarios/roles (FASE 4)**: los endpoints `/tenants/{tenant}/users*` exigen que `{tenant}`
  sea el tenant **activo** y el permiso correspondiente. Otro tenant al que se pertenezca (o
  ajeno) → **404** (no revela existencia); tenant activo sin permiso → **403**
  `PERMISSION_DENIED`; invitación ajena al tenant o token inexistente → **404**; token de
  invitación aceptado → 409, revocado/expirado → 410, email del usuario distinto al invitado →
  403 `INVITATION_EMAIL_MISMATCH`. Aceptar una invitación NO cambia el tenant activo (no da
  acceso hasta `switch`).
- **Business profile (FASE 5)**: el perfil es 1:1 con el tenant (`business_profiles.tenant_id`
  UNIQUE, FK cascade). El modelo usa el trait `BelongsToTenant` (scope + forzado de `tenant_id`
  por TenantContext); el `tenant_id` jamás se acepta del frontend (test BP-8). Reglas:
  `/tenants/{tenant}/business-profile` exige `{tenant}` activo → otro → **404**; sin
  `business_profile.view` → 403; `update` solo owner/admin. Creado bajo demanda en la primera
  lectura (invariante 1:1). Aislamiento CRITICO (BP-6): el tenant A no lee ni modifica el perfil
  de B.
- **WhatsApp (FASE 6)**: `whatsapp_accounts`/`whatsapp_phone_numbers`/`message_send_attempts`
  con trait `BelongsToTenant`; los endpoints `/tenants/{tenant}/whatsapp*` exigen `{tenant}`
  activo (otro → 404) y permiso `whatsapp.view` (lectura) / `whatsapp.manage` (owner/admin).
  El `access_token` viaja cifrado y nunca se expone. El **webhook** es la excepción deliberada:
  es público (autenticado por firma) y escribe en `webhook_events` (plataforma) + resuelve el
  tenant por `phone_id` sin contexto; los jobs `ProcessIncomingWhatsAppMessage` /
  `ProcessWhatsAppStatusUpdate` usan `TenantAwareJob` con el tenant resuelto (aislamiento
  garantizado por diseño).
- **Contactos (FASE 7)**: `contacts`/`tags` con trait `BelongsToTenant`; los endpoints
  `/tenants/{tenant}/contacts*` exigen `{tenant}` activo (otro → 404) y permiso
  `contacts.view` (lectura) / `contacts.manage` (owner/admin). El `{contact}` del path NO usa
  route-model binding implícito (el middleware `SubstituteBindings` corre antes que `tenant`):
  el servicio resuelve el contacto con `withoutTenantScope()` pero filtrando SIEMPRE por
  `tenant_id` del tenant autorizado → contacto ajeno o inexistente → **404** (no revela
  existencia; CONTACT-12 CRITICO). El `tenant_id` del body se ignora (CONTACT-13).
  `findOrCreateForPhone` (webhook, FASE 9) consulta sin scope con filtro por `tenant_id` y setea
  `TenantContext` solo alrededor del create, liberándolo en `finally` (no contamina jobs).
- **Conversaciones (FASE 8)**: `conversations`/`conversation_participants`/
  `conversation_assignments` con trait `BelongsToTenant`; los endpoints `/tenants/{tenant}/
  conversations*` exigen `{tenant}` activo (otro → 404) y permiso `conversations.view` (lectura)
  / `conversations.manage` (mutaciones de estado y bot, owner/admin) /
  `conversations.assign` (asignar/transferir, owner/admin) / `conversations.claim` (claim propio,
  todos los roles activos). El `{conversation}` del path NO usa
  route-model binding implícito: el servicio resuelve con `withoutTenantScope()` filtrando SIEMPRE
  por `tenant_id` del tenant autorizado → conversación a ajena o inexistente → **404**. Crear
  sobre un contacto ajeno → **404** (`ConversationContactNotFoundException`). El `tenant_id` del
  body se ignora en assign/create/update y se rechaza en claim (CONV-20/HMT-05). Aislamiento
  CRITICO (CONV-18/19): el tenant A jamás lee, modifica ni
  asigna conversaciones de contactos del tenant B, y una conversación creada sobre un contacto de
  B es invisible para A (404). Asignación solo a miembros activos del tenant (422
  `AGENT_NOT_IN_TENANT` en caso contrario). `findOrCreateActiveForContact` (webhook, FASE 9)
  consulta sin scope con filtro por `tenant_id` y usa `TenantContext::withId()` alrededor del
  create (no pisa ni limpia un contexto ya activo, p. ej. el del motor de flujos, FASE 11).
- **Handoff data (FASE 15 U1, ADR-051/052)**: assignments/participants reciben el tenant solo
  desde `TenantContext`, nunca del request; sin contexto las lecturas devuelven vacío y las
  escrituras fallan seguro. `messages.sent_by_user_id` no es fillable ni se confía desde payload
  público; U3 resuelve el actor desde el usuario autenticado, revalida membership activa y exige
  assignment propia para agent. Owner/admin conservan override dentro del tenant. El origen
  `automation|human|handoff` también lo fija exclusivamente el backend.
- **Assignment/claim atómico (FASE 15 U2)**: las tres operaciones resuelven la conversación con
  filtro explícito de tenant y `FOR UPDATE`, y vuelven a leer memberships activas después de
  adquirir el `conversationLock`. Claim no acepta IDs del cliente. FKs compuestas y scopes impiden
  referencias A/B, mientras la UNIQUE parcial evita dos assignments abiertas incluso ante bypass
  de aplicación.
- `messages` (FASE 9, ADR-032): tabla con trait `BelongsToTenant`, `tenant_id` FK
  `cascadeOnDelete` y `conversation_id` FK→`conversations` del **mismo tenant** (cascade; el
  contacto se resuelve por la conversación, no se duplica). `Tenant::messages()` (hasMany). La
  idempotencia es por tenant: UNIQUE `(tenant_id, provider_message_id)` (mensaje duplicado =
  no-op, backstop `QueryException`). Los jobs del webhook (`ProcessIncomingWhatsAppMessage`/
  `ProcessWhatsAppStatusUpdate`) y el de envío (`SendWhatsAppMessage`) usan `TenantAwareJob` con
  el tenant resuelto/encolado; `MessageService` resuelve SIEMPRE con
  `withoutTenantScope()->where('tenant_id', ...)` y setea `TenantContext` solo alrededor de los
  creates (liberado en `finally`) → el webhook del número de B jamás persiste en datos de A
  (MSG-6, STAT-8, CRITICO).
- `chatbots` / `flows` / `flow_nodes` / `flow_connections` / `triggers` / `flow_executions` /
  `flow_execution_logs` (FASE 11, ADR-034): TODAS con trait `BelongsToTenant`, `tenant_id` FK
  `cascadeOnDelete`. FKs de dominio siempre entre entidades del MISMO tenant (chatbot→tenant,
  flow→chatbot del mismo tenant, node/connection→flow, execution→conversation del mismo tenant,
  log→execution). La **ejecución activa por conversación** se garantiza con el UNIQUE parcial
  `(tenant_id, conversation_id) WHERE status IN ('running','waiting')` (una por tenant).
  `FlowEngine` se ejecuta SIEMPRE dentro del `TenantContext` del job `TenantAwareJob`
  (no lo crea ni lo limpia); los servicios internos usan `TenantContext::withId()` para crear
  modelos sin pisar el contexto activo (FASE 11). El nodo `webhook` usa `WebhookUrlGuard`
  (anti-SSRF) y las URLs externas llevan `execution_id` para idempotencia.

## 5. Aislamiento en colas, eventos y notificaciones

- Cada job de dominio tenant usa el trait **`TenantAwareJob`** (ADR-021): transporta `tenantId`
  en su payload (`forTenant()`), `handle()` establece `TenantContext::setId()` y lo libera en
  `finally`. La lógica vive en `executeInTenantContext()`. El job usa su propio tenant, nunca el
  contexto existente al encolarse. Nunca confiar en estado del proceso.
- Los `Event` y `Notification` que viajan a la cola serializan `tenant_id` y lo restauran al
  procesarse (listeners/notifications que leen DB en cola deben re-establecer el contexto).
- Los broadcasts de Reverb se canalizan por canal privado con scope al tenant:
  `tenant.{tenantId}.conversations.{conversationId}` (**sin comodín `*`**, ver ADR-022). La
  autorización del canal (`routes/channels.php`) valida la pertenencia real del usuario al tenant
  (`belongsToTenantById`) — no basta con estar autenticado. Cada recurso registra su propio
  patrón: `tenant.{tenantId}.<recurso>.{recursoId}`.
- El canal tenant-wide `tenant.{tenantId}.inbox` (FASE 15 U4, ADR-053) emite
  `InboxConversationChanged` a todos los miembros activos del tenant con permiso
  `conversations.view`. La auth del canal usa `belongsToTenantWithPermission` que verifica
  membresía activa + matriz de permisos de código (`TenantPermission::permissionsForRole`),
  no registros spatie. El canal está aislado: usuario de otro tenant o sin el permiso recibe 403.

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
  `withoutTenantScope()` explícito dentro de esos servicios.

## 9. Tests obligatorios de aislamiento

1. Tenant A NO puede ver conversación de Tenant B → 403/404.
2. Tenant A NO puede enviar mensaje a contacto de Tenant B → 403/404.
3. Tenant A NO puede recuperar chunks de Knowledge Base de Tenant B.
4. El worker de cola que procesa datos del Tenant B no contamina el contexto del Tenant A
   (tests 9–11 de `TenantContextJobTest`: jobs con `forTenant()`, contexto limpio tras ejecución).
5. Un webhook de un número del Tenant B no escribe en datos del Tenant A.
6. Una clave de cache del Tenant A no es legible bajo el namespace del Tenant B.
7. Un objeto de Storage del Tenant A no es accesible por el Tenant B.
8. Un canal Reverb del Tenant B rechaza la suscripción de un usuario del Tenant A
   (`ReverbChannelAuthTest`).
9. (FASE 4) Los permisos dependen del rol en el tenant **activo**, no del usuario: el mismo
   usuario ve `agent` en A y `admin` en B según `current_tenant_id` (`MT-22`).
10. (FASE 4) Aceptar una invitación a B no da acceso a B sin `switch` previo (`MT-23`, crítico X).
11. (FASE 4) `super_admin` global no gestiona usuarios de tenants sin membresía activa (403
    `NO_TENANT`), y un admin en A pierde al instante el acceso a usuarios al operar en B como
    agent (403 `PERMISSION_DENIED`) — test CRITICO.
12. (FASE 5) Tenant A NO lee ni modifica el perfil de negocio de Tenant B: 404 en GET y PUT, y el
    perfil de B queda intacto (`BP-6`, CRITICO). El `tenant_id` enviado en el body es ignorado
    (`BP-8`).
 13. (FASE 6) El webhook de un número del tenant B jamás escribe en datos del tenant A: resuelve el
    tenant por `phone_id` y encola un job con `forTenant(B)` (`WHATSAPP-11`, CRITICO); Tenant A no
    ve ni desconecta la cuenta de B (404 en GET/POST, `WHATSAPP-20`).
14. (FASE 7) Tenant A jamás lee, modifica ni elimina contactos de Tenant B: 404 en
    GET/PATCH/DELETE y el contacto de B queda intacto (`CONTACT-12`, CRITICO). El `tenant_id`
    enviado en el body se ignora (`CONTACT-13`). `findOrCreateForPhone` crea/consulta siempre bajo
    el tenant indicado y deja el contexto limpio (`CONTACT-19`).
15. (FASE 8) Tenant A jamás lee, modifica ni asigna conversaciones de Tenant B: 404 en
    GET/PATCH/assign/transfer y la conversación de B queda intacta (`CONV-19`, CRITICO). Crear
    sobre un contacto del Tenant B → 404 (`CONV-18`, CRITICO). El `tenant_id` enviado en el body
    se ignora (`CONV-20`). `findOrCreateActiveForContact` crea/consulta siempre bajo el tenant
    indicado y deja el contexto limpio (`CONV-24`).
16. (FASE 9) El webhook de un número del tenant B jamás persiste mensajes/contactos/
    conversaciones en el tenant A (`MSG-6`, CRITICO); los status de B no tocan mensajes de A
    (`STAT-8`, CRITICO). `MessageService` y los jobs de mensajes resuelven siempre con filtro por
    `tenant_id` y dejan el contexto limpio en `finally`.
17. (FASE 11) Tenant A jamás ve/edita/ejecuta recursos de flujos del Tenant B: chatbots, flujos,
    triggers y ejecuciones de B → 404 en GET/PATCH/DELETE/POST y quedan intactos (FLOW-24,
    CRITICO). El `tenant_id` del body se ignora (no existe como campo en los `FormRequest`; la
    pertenencia la fuerza `BelongsToTenant` en escrituras). El motor (`FlowEngine`) corre dentro
    del     `TenantContext` del job `TenantAwareJob` y sus creates internos usan `TenantContext::withId`
    (FLOW-14, FLOW-15). La ejecución activa por conversación está acotada por tenant
    (UNIQUE parcial `(tenant_id, conversation_id)`).
18. (FASE 12) El editor visual abre solo flujos del tenant activo: `FlowEditorSettingsController`
    resuelve chatbot+flujo filtrando por `tenant_id` del contexto (FLOW-31) y la carga vía
    `GET /flows/{flow}` devuelve 404 a otro tenant (FLOW-39, CRITICO). El `tenant_id` jamás se
    envía en el payload del draft (FLOW-40). La edición del borrador exige `flows.manage`;
    un agent del tenant ve el editor en read-only (FLOW-41). El lock optimista
    (`base_updated_at`) compara contra el `updated_at` de la propia fila del tenant.

19. (FASE 15 U5) Scope filters del inbox (mine/all/unassigned) se ejecutan SIEMPRE bajo
    `TenantContext` del middleware → imposible ver conversaciones de otro tenant. Los counts
    (all/mine/unassigned) se calculan dentro del mismo TenantContext y no se filtran por el
    scope activo → siempre reflejan el estado real del tenant. El canal Reverb tenant-wide
    `tenant.{tenantId}.inbox` está aislado por `belongsToTenantWithPermission` (membresía
    activa + `conversations.view`); usuario de otro tenant recibe 403.

20. (FASE 16 U2) AI node output de Tenant A jamás aparece en Tenant B. El output se guarda
    en `execution.variables.custom` dentro del `TenantContext` del flow engine. Variables custom
    de A no son resolubles en templates de B (`VariableResolver` recibe solo `custom` del
    contexto actual). Config injection en `node.config` no puede cambiar `tenant_id` del
    contexto (AI-S10). Tests AI-MT-01..06 verifican aislamiento cross-tenant.
