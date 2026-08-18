# Testing

## 1. Estrategia

Pirámide de tests con prioridad en lo crítico:

1. **Unit** — motor de flujos, condiciones, variables, validadores, providers (con `Http::fake`).
2. **Feature** — APIs REST, webhooks, aislamiento tenant, autorización, límites de uso.
3. **Integration** — jobs de cola, webhook → worker → DB → broadcast.
4. **E2E (Playwright)** — flujos de usuario completos (FASE 30).
5. **Security** — 403/401 en cada recurso, firma de webhook, rate limits, tenant isolation.

## 2. Herramientas

| Capa | Herramienta | Nota |
|---|---|---|
| Backend | Pest + Testbench | `tests/Unit`, `tests/Feature` |
| Frontend | Vitest | componentes + composables |
| E2E | Playwright | `tests/e2e` (carpeta separada) |
| Cobertura | Xdebug/pcov | objetivo >= 80% en código crítico |
| Calidad | Pint, PHPStan, ESLint, vue-tsc | gates de CI |

## 3. Convenciones

- Un test por comportamiento. Naming: `Pest\it('...')` descriptivo.
- Fixtures de fábricas: `Database\Factories` (tenant, usuario, contacto, conversación, flujo).
- El **tenant** se crea por test: helper `actingAsTenant($user)` fija `TenantContext` + auth.
- Tests de cola con `Queue::fake()` salvo los de integración (usar `database` sync).
- No se usan `Mockery` permanente para proveedores reales: `Http::fake` para Meta/OpenAI;
  la interfaz se puede falsificar solo en tests de dominio (doble de test, no mock permanente).

## 4. Tests obligatorios por módulo

### Aislamiento tenant (crítico)
- `it('rejects access to another tenant conversation')` → 403/404.
- Ídem para contacts, messages, flows, chunks KB, leads, analytics.
- `it('job does not leak tenant context between executions')`.

### WhatsApp (crítico — FASE 6, 42 tests WHATSAPP-1..40 + 37b/39b)
- Verificación GET: token correcto → challenge en texto plano; incorrecto → 403.
- Firma `X-Hub-Signature-256` ausente/incorrecta → 401 `WHATSAPP_SIGNATURE_INVALID`.
- Mensaje/status entrante: ingesta + dedupe + resolución de tenant por `phone_number_id` +
  dispatch del job correcto (`WHATSAPP-6/7/12`).
- Evento duplicado → `duplicate = true`, no se reprocesa (`WHATSAPP-8`).
- `phone_number_id` desconocido / payload malformado → **200** + `webhook_events.failed`
  (nunca 500; sin reintentos en bucle de Meta) (`WHATSAPP-5/9/10`).
- Aislamiento CRITICO: webhook de un número de B no toca datos de A (`WHATSAPP-11`); Tenant A no
  ve/desconecta la cuenta de B (`WHATSAPP-20`).
- Conexión/envío: token cifrado en reposo (`WHATSAPP-15`), 401/404/409 en Meta, 403 sin permiso,
  404 no-miembro, el token nunca se expone por API (`WHATSAPP-29`), error permanente vs.
  transitorio (`WHATSAPP-26/28`), registro en `message_send_attempts` (`WHATSAPP-25`).
- Provider unit: firma HMAC, payload oficial de sendText, mapeo de errores retryable/no-retryable,
  timeout, `subscribed_apps` (`WHATSAPP-31..40`).
- `Http::fake` con patrones por URL: el matcher de Laravel compara contra la URL **con query
  string** (p. ej. `?fields=...` en `getPhoneNumberInfo`), así que los patrones absorben el query
  (`graph.facebook.com/*/phone-1*`); los `Http::fake` se registran en UNA sola llamada (los
  callbacks se acumulan, no se reemplazan).

### Contactos (FASE 7, 19 tests CONTACT-1..19 + 13 vitest en `contactUtils.test.ts`)
- CRUD por rol: agent no crea/edita/borra (403 `PERMISSION_DENIED`), owner/admin sí (`CONTACT-2/7/14`).
- Validación backend: teléfono inválido (<7 o >15 dígitos, caracteres raros) → 422 (`CONTACT-4`);
  nombre/email/metadata/avatar validados (`CONTACT-16`).
- **Normalización E.164**: `'+54 9 1155 5554-444'` → `+5491155554444`; email parcial
  (`?email=carla`) filtra (`CONTACT-3/9`); dedupe activo → 409 `CONTACT_DUPLICATE` (`CONTACT-6`);
  soft delete libera el teléfono para re-crear (`CONTACT-11`); `provider_contact_id` único
  (`CONTACT-18`).
- **Aislamiento CRITICO**: Tenant A jamás lee/modifica/elimina contactos de B (404 y B intacto,
  `CONTACT-12`); `tenant_id` del body ignorado (`CONTACT-13`).
- `findOrCreateForPhone`: crea bajo el tenant indicado y devuelve el existente (idempotente);
  libera `TenantContext` en `finally` (`CONTACT-19`).
- Auditoría `contact.created/updated/deleted` con `changed` (`CONTACT-15`).
- Paginación con `meta` explícito (`CONTACT-10`); 404 en contacto inexistente/ajeno (`CONTACT-8`).
- Frontend (Vitest): `normalizePhone`, `hasValidPhoneDigits`, `buildContactQuery`,
  `extractErrorMessage`, `parseMetadata`.

### Conversaciones (FASE 8, 24 tests CONV-1..24 + 7 vitest en `conversationUtils.test.ts`)
- CRUD por rol: agent no crea/edita/cierra/asigna (403 `PERMISSION_DENIED`), owner/admin sí
  (`CONV-21`); agent solo lectura (`CONV-22`); no-miembro/suspendido → 404/409.
- Crear: exige `contact_id` del MISMO tenant → 404 si ajeno (`CONV-18` CRITICO); 201 con
  contacto válido; `context`/`bot_paused`/`status` iniciales aceptados; `tenant_id` del body
  ignorado (`CONV-20`).
- Máquina de estados: `open→pending→resolved→archived`, reabrir desde cualquier ≠ `open`,
  transición inválida → 409 `CONVERSATION_INVALID_STATE`, mismo estado → no-op
  (`CONV-6/7/14`); `context` merge por claves en PATCH (`CONV-8`).
- Assign/transfer: asignación a miembro ACTIVO del tenant → 200 y `agent_id` actualizado;
  agente de otro tenant → 422 `AGENT_NOT_IN_TENANT` (`CONV-9/10`); transfer cierra la
  asignación/participación previas (`unassigned_at`/`left_at`) y crea historial con reason
  `transfer` (`CONV-11/13`); cada transición registra `conversation_assignments` y audita
  (`CONV-15`).
- **Aislamiento CRITICO**: Tenant A jamás lee/modifica/asigna conversaciones de B (404 y B
  intacto, `CONV-19`); crear sobre contacto de B → 404 (`CONV-18`); `tenant_id` del body
  ignorado (`CONV-20`).
- `findOrCreateActiveForContact`: reutiliza la activa del contacto o crea; con contacto
  soft-deleted no la resucita; libera `TenantContext` en `finally` (`CONV-24`).
- Listado: filtros search (sobre el contacto)/status/agent_id + paginación `meta`
  (`CONV-2/5`); 404 en conversación inexistente/ajena (`CONV-3`).
- Frontend (Vitest): `buildConversationQuery`, `formatLastInteraction`, `canClose/canReopen`,
  `CONVERSATION_STATUS_META`, `extractErrorMessage`.

### Mensajes (FASE 9, 28 tests MSG-1..9 / STAT-1..8 / OUT-1..7 / OUTBOX-1..4)
- Inbound (`MSG-1..9`): el webhook de Meta persiste contact+conversation+message e2e (`MSG-1`);
  dedupe por `provider_message_id` (mismo evento dos veces → mismo mensaje, `MSG-2`, y a nivel
  de servicio `MSG-3`); **aislamiento CRITICO** `MSG-6` (webhook de un número de B nunca toca
  datos de A); tipo no soportado → evento `failed` (`MSG-4`); payload sin id/from → no-op con log
  (`MSG-5`); media image persiste `media_mime`/`media_size` + metadata (`MSG-7`); conversación
  `resolved` se reabre (`MSG-8`); audita `message.received` (`MSG-9`).
- Status (`STAT-1..8`): `delivered`/`read` actualizan estado + columna temporal e2e (`STAT-1/2`);
  clave compuesta `id|status|timestamp` permite delivered+read del mismo mensaje (`STAT-4`);
  repetición idéntica → idempotente (`STAT-3`); `failed` → conversación `pending` (`STAT-5`);
  sin mensaje → no-op con log (`STAT-6`); status desconocido → no-op (`STAT-7`); aislamiento A/B
  (`STAT-8`).
- Outbound (`OUT-1..7`): `createOutbound` encola `SendWhatsAppMessage` con mensaje `pending`
  (`OUT-1`); éxito → `sent` + `provider_message_id` + attempt + audita `message.sent` (`OUT-2`);
  error permanente de Meta → `failed` (`OUT-3`); error retryable → rethrow y el mensaje queda
  `sending` (`OUT-4`); CAS: job duplicado no re-envía (`OUT-5`); sin cuenta conectada →
  `failed` `whatsapp_not_connected` (`OUT-6`); tipo no-text → `failed` (`OUT-7`).
- Outbox (`OUTBOX-1..4`): reprocesa eventos `received` viejos (`OUTBOX-1`); ignora recientes y
  `processed` (`OUTBOX-2/3`); límite 100 y exit codes (`OUTBOX-4`).
- Helpers de test compartidos en `tests/Support/helpers.php` (autoload-dev `files`): `make_contact`,
  `make_conversation`, webhook (firma/secreto/payload); `created_at`/`updated_at` de
  `webhook_events` no son `$fillable` → los tests de outbox insertan con `DB::table(...)->insert()`.

### Inbox API + Realtime (FASE 10, 16 tests MSG-API-1..16 en `MessageApiTest`)
- Index (`MSG-API-1/2/3`): historial DESC, recurso completo, un agente lista con `conversations.view`,
  `per_page` acotado a 100 y paginación.
- Aislamiento (`MSG-API-4/5/8/11`): un usuario de A jamás lista/envía a conversaciones de B (404,
  incluido IDOR sobre id inexistente = 404 sin oráculo).
- Store (`MSG-API-6/7/9/10`): POST de texto → mensaje `pending` + job encolado + timestamps
  bumped; valida body (required/string/no vacío); un agente responde desde el inbox (201);
  matriz `messages.send` para owner/admin/agent.
- Realtime (`MSG-API-12..15`): POST emite `MessageCreated`; webhook inbound emite
  `MessageCreated` + `ConversationUpdated` (reabre); status update emite `MessageStatusUpdated`;
  cerrar emite `ConversationUpdated`. Se verifican con `Event::fake([...])` + `assertDispatched`
  (Laravel 12.66 no expone `Broadcast::fake()`); el nombre del canal privado incluye el prefijo
  `private-`.
- Listado (`MSG-API-16`): `ConversationResource` incluye `last_message` (preview del inbox).
- Frontend (Vitest, entorno `jsdom` + `@vitejs/plugin-vue`): `messageUtils.test.ts` (22 tests:
  query, `isNearBottom`, timestamps/días/agrupación, merge con dedupe, update por id, preview,
  labels) y `MessageComposer.test.ts` (6 tests: submit, Enter, Shift+Enter, vacío, disabled,
  botón deshabilitado al enviar).

### Canal Inbox Tenant-Wide Realtime (FASE 15 U4, 21 tests)
- **Channel auth (RT-01..RT-04, RT-15)** en `ReverbChannelAuthTest`: owner accede canal inbox
  de su tenant (RT-01); usuario de A denegado en canal de B (RT-02); membresía inactiva → 403
  (RT-03); auth con permiso inexistente → 403 (RT-04); tenant_id del canal gana sobre
  `current_tenant_id` del usuario (RT-15). 8 tests totales (3 originales + 5 U4).
- **Event emission (RT-05..RT-15)** en `HandoffRealtimeEventTest`: handoff emite
  `InboxConversationChanged` afterCommit con kind `handoff_requested` (RT-05);
  transacción fallida no emite (RT-06); claim/assign/transfer emiten sus kinds (RT-07/08/09);
  resume bot emite `bot_resumed` (RT-10); close/reopen emiten `conversation_updated`
  (RT-11/11b); payload usa `ConversationResource` sin `tenant_id` directo (RT-12); cada
  evento tiene `event_id` único (RT-13); todos los kinds válidos en `broadcastWith`
  (RT-14); `broadcastOn` usa canal `PrivateChannel` (RT-15). 13 tests.
- **Frontend (FRT-01..FRT-10)** en `realtime.test.ts`: `useConversationChannel` usa
  `private()` (FRT-01); cleanup al cambiar conversación/tenant (FRT-02/05); cleanup al
  desmontar (FRT-03); `useInboxChannel` suscribe canal correcto (FRT-04); dedupe por
  `event_id` (FRT-06); callback invocado con payload (FRT-07); kind desconocido ignorado
  (FRT-08); payload malformado no rompe (FRT-09); upsert existente preserva
  `last_message` (FRT-10). 14 tests (incluye inboxChannelTypes).
- Totales U4: 8 + 13 + 14 = 35 tests nuevos.

### Inbox Scope/Buckets + Handoff UX (FASE 15 U5, 42 tests)
- **Backend scope/buckets (INBOX-01..08)** en `InboxScopeTest`: scope `all` retorna todas las
  conversaciones del tenant con counts correctos (INBOX-01); scope `mine` retorna solo las
  asignadas al usuario autenticado (INBOX-02); scope `unassigned` retorna solo conversaciones
  sin agente con `handoff_requested_at` no nulo (INBOX-03); counts reflejan el estado real
  independientemente del scope activo (INBOX-04); tenant A y B tienen counts aislados (INBOX-05);
  `agent_id` filtra correctamente (INBOX-06); membresía inactiva deniega acceso (INBOX-07);
  paginación funciona con scope y counts se mantienen consistentes (INBOX-08). 8 tests.
- **Frontend utility (UI-01..10)** en `conversationUtils.test.ts`: `isUnassignedHandoff` (UI-01..03),
  `isHumanActive` (UI-04..05), `isManualPause` (UI-06..08), `buildConversationQuery` scope
  (UI-09..10). 10 tests.
- **Frontend ChatHeader (UI-11..20)** en `ChatHeader.test.ts`: claim button visibility (UI-11..12),
  claim event emit (UI-13), handoff banner (UI-14), bot/human status labels (UI-15..18),
  assign/transfer dropdown self-exclusion (UI-19), transfer label (UI-20). 10 tests.
- **Frontend ConversationListItem (UI-21..24)** en `ConversationListItem.test.ts`: "Requiere agente"
  label (UI-21), human agent name (UI-22), amber left border (UI-23..24). 4 tests.
- **Frontend ContactPanel (UI-HP-01..05)** en `ContactPanel.test.ts`: attention status labels
  (UI-HP-01..03), handoff_requested_at display (UI-HP-04..05). 5 tests.
- **Frontend MessageComposer (1 test actualizado)**: draft preserve on error (clear only when
  `sending` goes false). 1 test actualizado.
- Totales U5: 8 + 10 + 10 + 4 + 5 = 37 tests nuevos; suite total FASE 15 U5: **660 tests
  backend / 2740 assertions**; frontend **194 tests Vitest**.

### Handoff hardening and closure — FASE 15 UNIDAD 6 (BUG-1 fix, regression suite)

- **BUG-1 fix**: single `InboxConversationChanged` dispatch in `HumanHandoffService`
  (the authoritative emitter); `FlowEngine` no longer dispatches the event. Eliminates
  the double-dispatch that caused duplicate inbox updates.
- **HANDOFF-FINAL-01..10** (10 tests, regression guard):
  - HANDOFF-FINAL-01: regression guard — single InboxConversationChanged per handoff.
  - HANDOFF-FINAL-02: cross-tenant security — assignments/participants with wrong
    tenant_id rejected by FK composite.
  - HANDOFF-FINAL-03: sent_by_user_id rejection — StoreMessageRequest prohibits
    sent_by_user_id in payload.
  - HANDOFF-FINAL-04: inactive membership denied at claim/assign/transfer.
  - HANDOFF-FINAL-05: duplicate HumanNode — engine terminates at first, no double
    handoff.
  - HANDOFF-FINAL-06: inbound during handoff — message persisted, no FlowEngine entry.
  - HANDOFF-FINAL-07: resume-bot then inbound — starts new automation via
    NewMessage trigger.
  - HANDOFF-FINAL-08: ConversationUpdated still dispatched for detail subscribers.
  - HANDOFF-FINAL-09: handoff_requested_at persists through resume.
  - HANDOFF-FINAL-10: manual pause clears handoff_requested_at marker.

- **Security matrix** (12/12):
  - Scope filter (mine/all/unassigned) cannot leak conversations across tenants.
  - Claim button only appears for unassigned handoff conversations.
  - Self-exclusion from assign/transfer dropdown.
  - Draft preserve on error prevents message loss.
  - sent_by_user_id prohibited in StoreMessageRequest.
  - Inactive membership denied at claim/assign/transfer.

- **PostgreSQL concurrency tests** (HCON-01..06, HCON-ROW-01, HC-07-PG,
  HCON-MEMBER-02, HCON-U3-01 — 10 tests total, all pass):
  - Requires Docker with PostgreSQL 16, Redis, and separate test DB
    `whatsapp_saas_handoff_u2_test`.
  - Tests use independent PHP processes + real Redis to verify `FOR UPDATE`,
    concurrent assign/transfer/claim, rollback on late audit failure, and
    UNIQUE partial constraint (`SQLSTATE 23505`).

- **PostgreSQL migration UP/DOWN/UP** verified: all FASE 15 DDL migrates cleanly
  up, rolls back down, and migrates up again on PostgreSQL 16 without errors.
  Backfill of tenant_id in assignments/participants verified with legacy rows.

- Suite final FASE 15: **670 tests backend / 2765 assertions**; frontend
  **194 tests Vitest**. PostgreSQL concurrency: **10 tests / 60 assertions**.

### Chatbot engine (crítico)
- Secuencia lineal, ramas condition, question→variable, delay, human, end.
- Loop detection / límite de pasos.
- Reanudación tras `waiting`.
- Validación de flujo: sin start, nodo huérfano, sin end, config inválida.

### Flujos FASE 11 (30 tests + 23 Vitest)
- **Motor** (`FlowEngineTest`, FLOW-1..15, cola sync): secuencia message→message→end; condition
  con variables → rama; question captura `{{custom.*}}` y continua; delay agenda el siguiente
  paso; tag aplica etiquetas; human pausa el bot; webhook anti-SSRF + retry/backoff; límite de
  pasos (anti-loop); duplicado de webhook no avanza dos veces (idempotencia por
  `last_inbound_message_id`); webhook HTTP falla no rompe la ejecución; aislamiento A/B del
  motor (FLOW-14/15 CRITICO).
- **API** (`FlowApiTest`, FLOW-16..28): CRUD chatbots con matriz de permisos; index/show de
  flujos con nodos/conexiones/triggers; replaceDraft atómico + validación de forma (422) y
  grafo (422 `FLOW_INVALID`); publish valida y deactivate exige published (auditado);
  flujo publicado no se edita/elimina (409 `FLOW_PUBLISHED`); CRUD de triggers solo en flujos no
  publicados; `/validate` → `{valid, errors}` sin mutar; **aislamiento A/B CRITICO (FLOW-24)**
  y matriz de permisos agent solo lectura (FLOW-25); ejecuciones index/show con filtros;
  pause/resume/cancel + 409 sobre terminales (FLOW-27); auditoría de mutaciones (FLOW-28).
- **Permisos** (`FlowsPermissionTest`, FLOW-20/21): `flows.view` (owner/admin/agent) y
  `flows.manage` (owner/admin) con seeder sincronizado.
- **Frontend (Vitest)** `flowUtils.test.ts` (23): labels espejo del backend, `build*Query`,
  `nodeConfigSummary` (sin exponer secrets), `findStartNode`, `isImplementedNodeType`,
  `extractErrorMessage`.
- Suite total FASE 11: **294 tests backend / 1229 assertions**; frontend **71 tests Vitest**.

### Editor visual (FASE 12, ADR-040..044)
- **Backend** (`FlowEditorTest`, FLOW-29..43, 13 tests): secrets del nodo webhook se preservan y
  jamás se exponen por API (FLOW-29); lock optimista `base_updated_at` (acepta actual, rechaza
  obsoleto con 409 `FLOW_CONFLICT`, opcional en FLOW-30); página del editor se renderiza por
  tenant (FLOW-31); carga completa del flujo (FLOW-32); borrador atómico (FLOW-33/34); solo
  borrador editable + estados (FLOW-35/36); `FLOW_INVALID` con errores del validador (FLOW-37);
  guardado concurrente → 409 (FLOW-38); **aislamiento A/B 404 cross-tenant (FLOW-39, CRITICO)**;
  `tenant_id` del payload nunca se confía (FLOW-40); matriz `flows.view`/`flows.manage` agent
  read-only (FLOW-41); publish tras modificación guarda y valida (FLOW-42); dos editores
  concurrentes solo el primero persiste (FLOW-43).
- **Frontend (Vitest, 46 nuevos → 117 total)**:
  - `flowAdapter.test.ts` (13): roundtrip API↔draft sin pérdida, edge ids deterministas, ramas
    `true`/`false`, posiciones redondeadas, `base_updated_at` opcional, `graphSignature`
    independiente del orden, `canCreateConnection` (self-loop, entrada al inicio, terminales,
    rama de condición única, salida única).
  - `flowValidation.test.ts` (16): config por tipo (message/buttons/question/condition/delay/
    tag/webhook), operadores con `needsValue`, `localGraphIssues` (start único, terminales,
    ramas, end faltante), `mapBackendErrors` resuelve por nombre.
  - `useEditorHistory.test.ts` (6): límite 50, undo/redo, descarte de rama redo, clonado.
  - `useFlowEditor.test.ts` (11): load/save con `base_updated_at`, 409 `FLOW_CONFLICT` →
    `conflict`, `saveOverriding` sin lock, publish `FLOW_INVALID` → issues mapeados, onConnect
    válido/inválido, undo/redo, read-only ignora mutaciones (`window.axios` mockeado).
- Suite total FASE 12: **307 tests backend / 1319 assertions**; frontend **117 tests Vitest**.

### Variables, validación y seguridad (FASE 13, UNIDAD 5)
- **Concurrencia** (`FlowConcurrencyTest`, VAR-24/25/26, 3 tests): dos mensajes secuenciales
  acumulan variables en `execution.variables` y producen una sola ejecución + un solo outbound;
  mismo `provider_message_id` deduplica (una fila) y re-entregas del mismo mensaje mientras la
  ejecución está `waiting` son no-op (barrera `last_inbound_message_id`); el valor capturado
  persiste waiting→delay→resume, el `ContinueFlowExecution` en modo `delay` se empuja, el continue
  duplicado es no-op y el lock Redis de la conversación se libera.
- **Aislamiento tenant** (`FlowTenantIsolationTest`, VAR-29/30, 5 tests): el motor del tenant A
  jamás resuelve `contact.*`/`business.*`/`custom.*` del tenant B; el catálogo de A excluye los
  `custom.*` de B; pedir el flow de B desde A → 404; con `TenantContext` de B no se leen nodos de
  A (`VariableCatalogService::forFlow` bajo scope equivocado); el webhook de A recibe payload solo
  con datos de A.
- **Validación** (`FlowValidatorTest`, 17 tests): pregunta con `type`/`default` válido e
  incompatible; `type` desconocido; límites de longitud (4096/128/2048); referencias peligrosas
  (`__proto__`/`constructor`/`prototype`) en textos/headers/payload → error; `node.*`/namespaces
  desconocidos → warnings; campo de condition con namespaces permitidos y segmentos seguros;
  webhook sin credenciales, host literal (path `{{custom.plan}}` permitido, host no).
- **Webhook guard + logs** (`WebhookUrlGuardTest` nuevo, 6 tests): localhost/IPs privadas/
  reservadas bloqueadas, IP pública literal permitida, URLs inválidas bloqueadas, `sanitizeForLog`
  limpia credenciales/query/fragment; en `FlowEngineTest`: la auditoría `flow.webhook_called` y el
  error de reintento jamás contienen la query con secretos.
- **Resolver** (`VariableResolverTest`): `{{contact.<campo>}}` se expone como
  `contact.metadata[<campo>]` (VAR-1 UNIDAD 5).
- **Frontend (Vitest)**: `QuestionNodeConfig.test.ts` (2): conserva y edita `type`/`default` sin
  perderlos al guardar, default vacío → `null`. `useVariableCatalog.test.ts` (7): agrupa con
  `Map` y es inmune a `custom.__proto__`/`constructor`/`prototype` (sin prototype pollution).
- Suite total UNIDAD 5 (backend): **425 tests / 2001 assertions**; frontend **147 tests Vitest**.

### Contrato runtime de variables (FASE 13, UNIDAD 6, ADR-046)
- **Runtime default** (`FlowVariablesTest`, VAR-35, 6 tests): una respuesta **vacía** a una
  pregunta con `question.config.default` usable persiste el default coerceado al tipo declarado
  (integer `'42'` → `42` int y se interpola `Edad 42`; boolean `'true'` → `true`; date
  `'2024-01-01'` → `'2024-01-01'`; string `'invitado'` → `'invitado'`). Sin default (`''`/ausente)
  la respuesta vacía conserva el comportamiento previo (`''`). Una respuesta **no vacía** siempre
  gana al default aunque falle la coerción (`'abc'` con default integer → raw `'abc'`, contrato
  VAR-2 intacto).
- **Inline default end-to-end** (`FlowVariablesTest`, VAR-36, 3 tests): `{{custom.a|default:'A'}}
  {{custom.b|default:'B'}}` se resuelve en el motor con múltiples variables (`A B`); el valor
  capturado gana al default inline y el default del nodo llena el hueco (`X B`); los caracteres de
  control del default inline se eliminan en runtime (`Hola ab`).
- **Concurrencia/aislamiento**: no se tocó el modelo de captura (VAR-24/25/26) ni los accesos
  multi-tenant (VAR-29/30) — siguen verdes sin cambios.
- Suite total UNIDAD 6 (backend): **434 tests / 2013 assertions**; frontend **147 tests Vitest**
  (sin cambios de frontend).

### Validación y endurecimiento de triggers (FASE 14, UNIDAD 1, ADR-047)

- **Unit `TriggerValidatorTest` (13 tests, dominio puro sin BD)**: keyword válido/inválido
  (vacío/espacios/>255/con config); `new_message`/`start` sin config; tag válida (1..10 únicas)
  e inválida (ausente/vacía/duplicadas/>100/11 items/no-string); schedule válido (cron
  determinista + UUID) e inválido (cron mal formado, conversation_id ausente/no-UUID); cron
  determinista acepta solo sintaxis soportada y nunca evalúa código (`@daily`, `* * * *`,
  `1; rm -rf /` → false); webhook del cliente con `conversation_by` válido y rechazo de
  `token`/`token_hash`/campos extra; config final de webhook exige `token_hash` sha256; límites
  de config (4096) y cron (255); token CSPRNG 64 hex cuyo hash nunca revierte.
- **Unit `TriggerMatcherTest` (7 tests)**: keyword solo en primer mensaje y case-insensitive;
  `new_message`/`start`; precedencia keyword > new_message > start; `priority` desempata;
  triggers inactivos nunca se evalúan; `tag`/`schedule`/`webhook` jamás matchean un mensaje; el
  orden de tipo preserva específicos antes que genéricos.
- **API `TriggerApiValidationTest` (12 tests)**: el `webhook_token` se devuelve una única vez y
  el recurso redacta `token_hash` (persistido solo en BD); el token no reaparece en index ni en
  auditoría; el cliente no envía `token`/`token_hash`; webhook con `conversation_by` ausente/
  incorrecto; schedule válido/inválido; **CRÍTICO** schedule con conversación de otro tenant o
  inexistente → 404 (sin filtrar existencia); tag válida/inválida; triggers de mensaje no admiten
  config; actualizar webhook preserva `token_hash`; cambiar webhook a keyword sin palabra → 422;
  el tenant B nunca lee/referencia triggers del tenant A (404).
- **Publish `TriggerPublishTest` (10 tests)**: **CRÍTICO** a lo sumo un flujo publicado por
  tenant con trigger genérico activo del mismo tipo → 409 `FLOW_ALREADY_PUBLISHED`; genéricos de
  distinto tipo coexisten; `keyword` (incluso idéntica) coexiste; trigger genérico inactivo no
  bloquea; deactivar libera el genérico; flujo sin triggers convive; **CRÍTICO** el conflicto es
  por tenant (B publica con A publicado); publish valida la config de los triggers (keyword vacío
  → 422 `FLOW_INVALID`); schedule con config inválida bloquea la publicación; flujos publicados
  existentes no se ven afectados.
- Suite total UNIDAD 1 (backend): **476 tests / 2184 assertions**; frontend **147 tests Vitest**
  (sin cambios de frontend).

### Disparo de triggers schedule (FASE 14, UNIDAD 2, ADR-048)

- **`ScheduleTriggerTest` (17 tests, SCHED-01..17)**: schedule válido dispara el flujo
  (SCHED-01); cron fuera de ventana no dispara (SCHED-02); trigger inactivo no dispara
  (SCHED-03); flow no publicado no dispara (SCHED-04); bot pausado no dispara (SCHED-05);
  ejecución activa no duplica (SCHED-06); dos ticks simultáneos no duplican (SCHED-07); lock
  del trigger se libera correctamente (SCHED-08); lock de conversación se libera tras
  ejecución (SCHED-09); conversación inexistente no ejecuta (SCHED-10); conversación de otro
  tenant no ejecuta (SCHED-11); **CRÍTICO** aislamiento completo tenant A/B (SCHED-12);
  múltiples triggers schedule independientes (SCHED-13); keyword y start siguen funcionando
  tras schedule (SCHED-14); command despacha jobs correctos (SCHED-15); command no despacha
  cuando cron no matchea (SCHED-16); audit log registra `schedule_triggered` (SCHED-17).
- **TenantContextJobTest — save/restore (1 test actualizado)**: un job usa su tenant_id propio
  y restaura el contexto previo al encolarse (verifica que TenantAwareJob save/restore funciona
  correctamente: contexto previo se restaura, sin contexto previo se limpia).
- **TenantContextJobTest — excepción**: un job que lanza excepción libera el contexto en finally
  (verifica save/restore incluso ante errores).
- Suite total FASE 14 U2 (backend): **508 tests / 2250 assertions**; frontend **147 tests Vitest**
  (sin cambios de frontend).

### Webhook público de flujos (FASE 14, UNIDAD 3, ADR-049)

- **`FlowWebhookTest` (37 tests, WEBHOOK-01..20 + 17 extensiones)**: token válido dispara el
  flujo y crea ejecución (WEBHOOK-01); token inválido → 401 (WEBHOOK-02); trigger inexistente
  → 401 sin revelar existencia (WEBHOOK-03); trigger inactivo → 401 (WEBHOOK-04); flow no
  publicado → 401 (WEBHOOK-05); conversación válida via conversation_id (WEBHOOK-06);
  conversación inexistente → 400 (WEBHOOK-07); conversación de otro tenant → 400 (WEBHOOK-08);
  tenant_id del payload es ignorado (WEBHOOK-09); Idempotency-Key evita doble ejecución
  (WEBHOOK-10); concurrencia con misma Idempotency-Key (WEBHOOK-11); bot_paused evita ejecución
  (WEBHOOK-12); ejecución activa no duplica (WEBHOOK-13); token nunca aparece en response
  (WEBHOOK-14); token/token_hash jamás en logs/auditoría (WEBHOOK-15); rate limit 60/min
  (WEBHOOK-16); payload excediendo 64KB → 400 (WEBHOOK-17); headers sensibles no registrados
  (WEBHOOK-18); aislamiento tenant A/B (WEBHOOK-19, CRÍTICO); pipeline existente utilizado
  (WEBHOOK-20). Extensiones: sin Authorization → 401; sin Bearer → 401; token formato inválido
  → 401; conversation_by contact_id/phone resuelve; conversation_id faltante/no-UUID → 400;
  contact_id de otro tenant → 400; sin Idempotency-Key genera automática; payload no JSON →
  400; array no asociativo → 400; tenant suspendido → 401; chatbot null → 401; audit log
  registra `webhook_triggered`; extra fields stripped; Idempotency-Key vacío como ausente;
  segundo request sin key genera nueva ejecución.
- Suite total FASE 14 U3 (backend): **545 tests / 2325 assertions**; frontend **147 tests Vitest**
  (sin cambios de frontend).

### Cierre de FASE 14 (ADR-050)

- U1/U2/U3 permanecen cubiertas por las suites anteriores. `tag` conserva cobertura de contrato:
  validación válida/inválida en `TriggerValidatorTest` y `TriggerApiValidationTest`, y exclusión
  explícita del matcher de mensajes en `TriggerMatcherTest`.
- U4 no agrega tests de ejecución porque la ejecución automática por tag no existe y está
  diferida a FASE 20. No se simula con mocks ni con un punto de entrada temporal.
- Suite final FASE 14: **545 tests backend / 2325 assertions**; frontend **147 tests Vitest**.

### Human Handoff — FASE 15 UNIDAD 1 (ADR-051..053)

- `HandoffDataInvariantTest`: assignment abierta única protegida por DB, cierre/reapertura,
  conversaciones independientes, rollback/up con backfill, scopes A/B de assignments y
  participants, rechazo DB de referencias conversación/tenant cruzadas, fallo seguro sin
  TenantContext, cast de `handoff_requested_at`, actor de mensaje nullable/FK válida/FK inválida,
  `nullOnDelete` y protección contra mass assignment.
- `FlowValidatorTest` HANDOFF-CONTRACT: `human` terminal sin `end`, `handoff_message` ausente/null/
  vacío válido, tipos/longitud inválidos, sin salida, ramas condition→human y clasificación no
  waiting.
- Vitest `flowValidation.test.ts`: contrato opcional de Human y terminal local alternativo a end.
- PostgreSQL real: migrate UP, rollback DOWN y segundo UP sobre DB aislada con filas legacy;
  backfill, NOT NULL, FKs, índices y UNIQUE parcial verificados.
- Suite total FASE 15 U1: **565 tests backend / 2378 assertions**; frontend **149 tests Vitest**.
  Pint, PHPStan (0 errores, 1G), `vue-tsc` y build Vite también verdes.

### Assignment, claim y transfer — FASE 15 UNIDAD 2 (ADR-052)

- `HandoffAssignmentTest` (HA/HT/HC/HMT): assign libre e idempotente, transfer con cierre/
  reactivación, claim propio sin IDs confiados, membership activa, permisos, payload de audit,
  aislamiento A/B, inconsistencias controladas y `ConversationUpdated.afterCommit`.
- `MemberUserIdContract.test.ts`: selectors de assign/transfer/filtro usan `member.user.id`
  (`users.id`), nunca `tenant_users.id`.
- Suite PostgreSQL aislada `phpunit.pgsql.xml`: procesos PHP independientes y Redis real prueban
  `FOR UPDATE`, dos assigns, dos transfers, claim vs assign, transfer vs claim, membership
  desactivada mientras espera lock, rollback por fallo tardío de audit y UNIQUE parcial con
  SQLSTATE `23505`. La DB permitida es únicamente `whatsapp_saas_handoff_u2_test`.
- Suite total FASE 15 U2: **601 tests backend / 2490 assertions**; frontend **151 tests Vitest**.
  Suite PostgreSQL adicional: **9 tests / 50 assertions**. Pint, PHPStan (0 errores, 1G),
  `vue-tsc`, build Vite, Docker y healthcheck verdes.

### Human handoff runtime — FASE 15 UNIDAD 3 (ADR-051/052)

- `HandoffRuntimeTest` (HANDOFF-RUNTIME): conserva `open|pending`, conflicto controlado en estados
  cerrados, aviso opcional antes del terminal, timestamp, audits/log, idempotencia, rollback tardío,
  resume sin revive/replay/release y distinción de pausa manual.
- `MessageApiTest` U3: actor autenticado/origen human, agent limitado a assignment vigente,
  override owner/admin, estados cerrados y rechazo de campos manipulados.
- `OutboundTest` HANDOFF-OUT: automation y legacy bloqueados con `BOT_PAUSED_HANDOFF`, sin request ni
  attempt Meta; human y handoff permitidos con bot pausado.
- Suite PostgreSQL/Redis acumulativa: `HCON-U3-01` mantiene un worker real esperando el
  `conversationLock`, confirma el handoff y verifica cancelación determinista sin side effect.
- Suite total FASE 15 U3: **621 tests backend / 2615 assertions**; frontend **151 tests Vitest**.
  Suite PostgreSQL adicional: **10 tests / 60 assertions**. Pint, PHPStan (0 errores, 1G),
  migraciones PostgreSQL desde cero, `vue-tsc` y build Vite verdes.

### FASE 16 — AI Provider Infrastructure (U1)
- `OpenAIProviderTest` (AI-P01..P15): VO inmutabilidad (AIRequest/AIResponse), resolución
  desde contenedor, generateResponse con respuesta 200 (tokens correctos), system prompt
  incluido/omitido, API key vacía → AIAuthFailedException sin HTTP, HTTP 401 → AIAuthFailedException,
  HTTP 429 → AIRateLimitException, HTTP 400 → AIInvalidRequestException, HTTP 500 → AIProviderException
  retryable, timeout conexión retryable, respuesta sin choices → RuntimeException, token usage
  mapeado correctamente. Todos con `Http::fake` (sin llamadas reales).
- Suite FASE 16 U1: **15 tests / 43 assertions**. Suite total: **685 tests / 2808 assertions**.

### FASE 16 — AI Node Runtime (U2)
- `AiNodeExecutorTest` (AI-01..15): provider invocation, output persistence in custom.*,
  prompt variable resolution, invalid output_variable → fallback, empty/timeout/rate-limit/auth
  errors → fallback, no message sending, idempotency (second execution reuses), bot_paused
  defense-in-depth, control character sanitization, MAX_VALUE_LENGTH truncation, AIRequest
  security (no secrets), config abstraction.
- `AiFlowTest` (AI-F01..F10): publish, end-to-end execution, AI→condition branching,
  AI→message interpolation, provider failure→fallback→continue, bot_paused prevents execution,
  idempotency, full completion, handoff preserves invariants, AI cannot be start node.
- `AiSecurityTest` (AI-S01..10): cross-tenant output isolation, API key not in execution/audit
  logs, prompt/response not logged, output as plain text, injection via contact/custom/config,
  business secrets excluded.
- `AiTenantIsolationTest` (AI-MT-01..6): correct tenant context, data isolation, output
  isolation, template isolation, wrong context cleanup, sequential A→B execution.
- Suite FASE 16 U2: **41 tests / 86 assertions**. Suite total: **726 tests / 2894 assertions**.

### FASE 16 — Flow Builder AI UX (U3)
- `aiFlowBuilder.test.ts` (AI-V01..V20): palette visibility, canvas creation, start node prevention,
  config panel rendering, prompt required, output_variable required, dangerous keys rejected,
  VariablePicker in prompt, system_prompt persistence, fallback_message persistence, adapter roundtrip,
  DEFAULT_NODE_CONFIG correctness, published read-only, agent read-only, handles correct,
  badge eliminated, summary doesn't expose system_prompt, save via existing draft endpoint,
  FLOW_CONFLICT unchanged, no model/provider/api_key in config.
- Suite FASE 16 U3: **49 tests**. Suite frontend total: **244 tests**.

### FASE 16 — AI Usage Telemetry (U4)
- `TelemetryPayloadTest` (AI-U01..U08): VO fromResponse/fromError, negative token clamping,
  zero token preservation, toArray safe schema keys, PII exclusion (success + error paths).
- `AiTelemetryTest` (AI-U09..U25): latency_ms in ai_completed/ai_failed, success field,
  provider/model/token counts, output_variable, error_code from AIException, fallback_used
  true/false, error message, idempotency (no duplicate logs), empty response → ai_failed,
  PII never in any payload, monotonic clock reasonableness, bot_paused → no logs,
  invalid output_variable → no logs, safe schema keys only.
- Suite FASE 16 U4: **25 tests / 120 assertions**. Suite total: **751 tests / 3014 assertions**.

### Auth
- registro, login ok/ko, logout, forgot/reset, email verify, tokens.

### Billing / límites
- cuota superada → `TENANT_QUOTA_EXCEEDED` antes de enviar/IA/crear contacto.
- usage_records incrementan.

### Seguridad
- 401 sin token, 403 sin permiso, 404 en datos ajenos, throttle en login/envío.

## 5. Comandos

```bash
php artisan test                      # todo backend
php artisan test --testsuite=Feature  # API/feature
./vendor/bin/pint --test              # lint
./vendor/bin/phpstan analyze          # estático
npm run test                          # Vitest
npm run typecheck                     # vue-tsc
# Suite PostgreSQL U2/U3/U6: la creación explícita evita tocar la DB principal.
docker compose exec -T postgres dropdb -U saas --if-exists --force whatsapp_saas_handoff_u2_test
docker compose exec -T postgres createdb -U saas -O saas whatsapp_saas_handoff_u2_test
docker compose exec -T app ./vendor/bin/pest --configuration=phpunit.pgsql.xml --testsuite=PostgresConcurrency --do-not-cache-result
docker compose exec -T postgres dropdb -U saas --if-exists --force whatsapp_saas_handoff_u2_test
npx playwright test                   # E2E
```

En CI: secuencia lint → phpstan → test → build → typecheck → (E2E opcional en PRs grandes).

## 6. Cobertura

- `php artisan test --coverage` en CI.
- Umbrales: código crítico (webhooks, engine, tenant, billing) >= 90%; global >= 80%.
- Se registran excepciones justificadas en `docs/decisions.md`.

## 7. Estado de pruebas por fase

Cada fase declara su estado en `docs/roadmap.md` (PASS/FAIL) usando el formato de reporte
definido por el usuario (ver final de `roadmap.md`).
