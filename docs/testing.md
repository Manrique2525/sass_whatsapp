# Testing

## FASE 31 U1 - Meta provider and configuration hardening

U1 keeps Meta calls behind `WhatsAppProviderInterface` and tests the real
`MetaWhatsAppProvider` only with Laravel `Http::fake()`. No test contacts
`graph.facebook.com`. The provider requires the official HTTPS Graph host,
validates a pinned `v<integer>.0` API version, and uses bounded connection and
total request timeouts (`WHATSAPP_CONNECT_TIMEOUT < WHATSAPP_TIMEOUT`).

Missing or blank App Secret/verify token values fail closed for signature and
GET verification. Tenant access tokens and phone/WABA identifiers are rejected
when blank, without logging their values. E2E continues to bind
`FakeWhatsAppProvider` and uses `Http::preventStrayRequests()`.

The API version is never inferred from `latest` or changed automatically. A
future version bump requires reviewing Meta's changelog and passing the provider
HTTP contract suite. Access-token, App Secret and verify-token rotation remain
operational procedures; U1 documents them but does not execute production
rotation or implement secret overlap.

## FASE 31 U2 - Webhook authenticity and durable ingestion

U2 validates GET verification fail-closed and validates the POST signature over the exact raw body
before JSON parsing. Missing or invalid signatures return `401`; signed malformed JSON/envelopes,
unknown objects and oversized bodies are rejected without persistence or queue dispatch and return
the safe `200` ACK contract. Only `object=whatsapp_business_account` with `entry[].changes[]` is
accepted for parsing.

Webhook ownership is resolved only through `metadata.phone_number_id` to the globally unique
`whatsapp_phone_numbers.phone_id`. Payload `tenant_id` and `waba_id` are ignored. `provider_event_id`
remains the database idempotency barrier; messages use Meta message IDs and statuses use
`id|status|timestamp`. Multiple entries, messages and statuses are ingested independently.

Persistence precedes dispatch. Dispatch failures atomically return an event to `received` with a
safe `dispatch_failed` code; the minute sweeper retries it, while an atomic `received` to `enqueued`
transition prevents initial-ingest/replay races. Terminal-only pruning is scheduled daily; processed
events default to 7 days and failed events to 30 days, while replayable `received`/`enqueued` events
are never pruned. Persisted payloads contain only `phone_number_id`, type and the data required by
the existing jobs. Raw bodies and message contents are not logged.

## CI Foundation (FASE 30 U5-A)

GitHub Actions is the CI provider. The foundation workflow is
`.github/workflows/ci.yml` and runs on pull requests, pushes to `master`, and
manual `workflow_dispatch` executions.

- Permissions are least-privilege: `contents: read`; no deployment or write permissions.
- Pull request runs may be cancelled when superseded; `master` verification runs are not cancelled.
- CI uses PHP `8.3` and Node `22`, matching the PHP runtime and Node-compatible lockfile dependencies.
- Composer uses `composer validate --strict`, `composer install --no-interaction --prefer-dist --no-progress`,
  and caches downloaded packages by `composer.lock`; mutable `vendor/` state is not cached.
- npm uses `npm ci` and the `package-lock.json` cache through `actions/setup-node`; `node_modules` is not cached.
- U5-B adds independent `static`, `frontend` and `backend` jobs. Static gates run PHPStan, Pint, npm audit and
  Composer audit; frontend gates run Vitest, typecheck, production build and the deterministic E2E bundle build;
  backend runs the default SQLite/in-memory Pest suite with a 512 MB PHP memory limit.
- U5-C adds an independent `postgres` job using `pgvector/pgvector:pg16` and `redis:7-alpine`. It uses only the
  disposable `whatsapp_saas_handoff_u2_test` database, Redis DB `14`, explicit pre-migration safety checks and the
  serialized canonical `tests/Postgres` suite. Because the job runs directly on the GitHub runner, ports are mapped
  to localhost and the canonical `postgres`/`redis` names are mapped locally. The job requires no repository secrets.
- No repository secrets are required. U5-C is validated locally against disposable PostgreSQL/Redis services;
  Docker/Playwright E2E is covered by U5-D and the final release gate belongs to a later U5 stage.

## FASE 30 U5-D - Self-contained E2E integration gate

U5-D adds the complete Playwright and Knowledge integration gate to CI. The E2E Compose project is
self-contained and never attaches to the development stack:

- PostgreSQL uses `pgvector/pgvector:pg16` with the exact disposable database `whatsapp_saas_e2e_test`.
- Redis uses logical database `15`, the worker consumes `default,knowledge`, and `--tries=1` is enforced.
- The app, worker and Reverb run with `APP_ENV=e2e`; external WhatsApp, OpenAI, Stripe and Sentry calls are
  blocked by the E2E-only providers and empty DSNs.
- Storage uses the named `e2e_storage` volume. PostgreSQL and Redis persistence are disabled or relaxed because
  all state is disposable; this avoids Docker Desktop checkpoint stalls without changing development services.
- CI starts the stack with health-based dependencies, runs setup, the deterministic E2E build, browser plus
  Knowledge integration tests, and then asserts failed/pending/reserved/delayed jobs are all zero.
- Playwright remains serial with `workers=1`, `retries=0`; failure diagnostics are limited to Compose logs and
  `test-results/e2e` / `playwright-report` artifacts.

## FASE 30 U5-E - Security and release closure

The workflow now has five mandatory verification jobs: `static`, `frontend`, `backend`, `postgres` and `e2e`.
`release-gate` depends on all five and is only scheduled after they succeed. It confirms verification completion;
it does not deploy, approve deployment, run production migrations or require an environment.

Failure diagnostics are failure-only. CI uploads only the short-retention (5 days) sanitized Compose status,
queue-status report and Playwright result/report paths. Raw app, worker and Reverb logs are not uploaded. The
following are never artifact paths: `tests/e2e/.auth`, storageState, cookies, `.env*`, private Knowledge files,
database dumps, tokens, provider credentials and production data.

The E2E boundary command verifies `FakeWhatsAppProvider`, `FakeAIProvider`, `FakeEmbeddingProvider` and
`E2EBillingProvider`, empty Sentry/OpenAI/Stripe/Meta secret variables, and Laravel HTTP fail-closed protection.
The queue assertion checks `failed_jobs` plus `pending`, `reserved` and `delayed` entries for both `default` and
`knowledge`; non-zero residue fails the E2E job.

The workflow runs on `pull_request`, pushes to `master`, and `workflow_dispatch`. It uses `contents: read`, no
repository secrets, no `pull_request_target`, and no write permissions, so fork pull requests do not receive
privileged credentials. To rerun manually, use GitHub Actions `workflow_dispatch`; local reruns use the commands
in this file and the isolated Compose project. Recommended required checks for `master` are the five mandatory
jobs plus `release-gate`.

Release-candidate eligibility means all mandatory jobs and `release-gate` are green, both audits are clean,
provider boundaries are enforced, and no queue residue remains. This status is verification only, not deployment.
FASE 30 completion does not execute pending production migrations, Sanctum expiry rollout, TrustProxies/TLS
operations, Sentry production DSNs or alerts, production deployment, or FASE31.

## FASE 30 U4 — E2E closure

U4 queda completada con journeys Playwright de Flow Builder y Billing, y con integracion de sistema para Knowledge.
Knowledge no tiene UI de upload/search, por lo que no se presenta como browser E2E.

- Flow Builder: `3/3` repetido, incluyendo edicion de nodos, persistencia de mensaje y contrato de etiquetas.
  La arista normal permanece sin etiqueta; no se usan sleeps ni retries.
- Billing: `4/4` en dos browser runs, cubriendo checkout, portal y permisos owner/admin/agent con `E2EBillingProvider`
  HTTPS. Stripe real: `0`.
- Knowledge: tres ciclos completos por corrida en tres corridas frescas. Cada ciclo valida upload por API, estado
  `ready`, storage compartido, worker Redis `knowledge`, extraccion, chunks, embeddings fake de dimension 1536,
  busqueda pgvector, wrong-KB 404, aislamiento cross-tenant y delete cleanup.
- U4 focused: `7/7` PASS. Regresion U1-U3: `20/20` PASS.
- Full browser E2E: `27/27` PASS en ambas corridas, con `workers=1` y `retries=0`; duraciones `12.6m` y `11.8m`.
- Worker final: queues `default,knowledge`; failed jobs `0`; pending/reserved/delayed `0`.
- Llamadas externas reales: Meta `0`, OpenAI `0`, Stripe `0`, Sentry `0`.
- Billing fixture remediation: commit `dd31589` (`test(billing): make usage period fixtures deterministic`).

## FASE 30 U4-R4 — E2E infrastructure readiness

R4 prepara la infraestructura para los journeys de Flow Builder, Billing y Knowledge, pero no implementa
los journeys U4 ni marca la unidad funcional como completada.

La recuperación de Docker Desktop y del stack compartido fue completada; `docker compose config` y el
arranque clean de la infraestructura E2E quedan verificados. El smoke sintético de `default` mediante una
closure ejecutada con `php -r` no se ejecutó porque Laravel no puede serializar ese origen de closure; no es
un bloqueo: el worker declara `default,knowledge`, U1-U3 ya ejercitan jobs reales en `default` y el runtime
E2E no dejó jobs fallidos ni pendientes.

- `docker-compose.e2e.yml` usa `APP_ENV=e2e`, PostgreSQL `whatsapp_saas_e2e_test`, Redis DB 15 y el worker
  consume `default,knowledge`; el orden de producción es `default,analytics,knowledge`.
- `e2e-app` y `e2e-worker` montan el mismo volumen nombrado `e2e_storage` en `storage/app`. No es el storage
  del desarrollador ni MinIO; el setup lo recrea y limpia de forma determinista.
- Embeddings usan el `FakeEmbeddingProvider` existente, determinístico y compatible con `vector(1536)`.
  AI usa el fake existente. Bajo `APP_ENV` distinto de `e2e` permanecen los bindings reales.
- Billing usa `E2EBillingProvider` únicamente bajo `APP_ENV=e2e`; checkout y portal devuelven URLs sintéticas
  `https://stripe-e2e.local/...` y no realizan HTTP a Stripe.
- `E2ETenantSeeder` conserva Tenant B en `free`, Tenant A en el plan sintético `e2e-paid`, crea el customer
  sintético y deja disponible también un plan inactivo. Los roles owner/admin/agent permanecen sujetos a
  las policies reales.
- No existe UI Knowledge. Su cobertura U4 será **E2E integration/system integration** contra la API real:
  upload → Redis worker `knowledge` → storage compartido → extracción/chunks → fake embeddings → pgvector/search
  → delete/cleanup. No se presentará como browser E2E.
- La protección de red E2E mantiene OpenAI, Stripe, Meta y Sentry sin credenciales; cualquier llamada real a
  OpenAI/Stripe se considera un fallo de infraestructura.

## FASE 30 U2 — Inbox + Human Handoff E2E

La suite U2/U3 usa el entorno aislado `APP_ENV=e2e`, base `whatsapp_saas_e2e_test`, Redis lógico 15,
Chromium, `workers=1`, `retries=0` y `QUEUE_CONNECTION=redis` con un worker real. El reset seguro se ejecuta con
`docker compose -f docker-compose.e2e.yml exec e2e-app php artisan e2e:setup`.

### Boundary de handoff

El fixture inicial crea una conversación abierta, un chatbot, un flujo publicado Start -> Human y un
trigger Start. El setup inyecta el primer inbound y ejecuta el código real:

`FlowEngine -> HumanNodeExecutor -> HumanHandoffService`.

No se fuerza directamente `bot_paused` ni `handoff_requested_at` para simular la transición. Después,
Playwright valida el comportamiento agent-facing: visibilidad en Sin asignar, claim, respuesta y resume.
U3 valida Reverb/WebSocket real; webhooks Meta quedan diferidos a FASE31.

### Provider y estados

Tenant A tiene una cuenta y teléfono WhatsApp conectados con identificadores, token y teléfono
obviamente sintéticos. `FakeWhatsAppProvider` implementa `WhatsAppProviderInterface`, no hace HTTP,
no almacena destinatarios ni contenido, y sólo se enlaza en `APP_ENV=e2e`. El pipeline real persiste
los outbound como `sent` con `provider_message_id` sintético. Las llamadas reales a Meta son 0.
Tenant B no tiene cuenta conectada ni puede leer recursos de Tenant A.

### Catálogo U2

- Inbox: carga el listado, abre una conversación, muestra historial y valida el último mensaje en una conversación con múltiples UUID.
- Filtros: búsqueda y scopes `Todas`, `Mias` y `Sin asignar` con visibilidad acorde al agente/asignación/handoff.
- Claim: agente reclama una conversación handoff no asignada; la asignación persiste tras reload.
- Reply: agente responde desde el composer; endpoint, servicio, job Redis, persistencia y provider fake completan el pipeline.
- Handoff: el estado inicial es bot activo; Human node produce handoff real, `bot_paused=true` y `handoff_requested_at`.
- Resume: admin usa la ruta canónica `resume-bot`; `bot_paused=false` persiste tras reload.
- Multi-tenancy: conversación propia accesible; conversación de Tenant B responde 404 y no hay leakage de mensajes/provider.

### Repetibilidad y resultados

No se usa `waitForTimeout`; los helpers esperan requests, estados persistidos y estados visibles. El
pipeline de reply es eventual bajo Redis/worker y el claim respeta la eliminación realtime del filtro
`Sin asignar`. U2 focused pasó 5/5 y U1+U2+U3 pasó 20/20 en dos ejecuciones completas. El hotfix
`db17bb7` corrigió la discrepancia `resume_bot`/`resume-bot` y permanece separado del commit U2.

## FASE 30 U3 — Realtime/Reverb E2E

U3 ejecuta U2 y realtime contra el worker Redis real y Reverb real. El stack E2E usa la red interna
`whatsapp-saas-e2e-realtime` y el hostname único `reverb-e2e`; no comparte el alias `reverb` con el stack
de desarrollo. El healthcheck consulta el endpoint Pusher del app ID sintético y acepta únicamente la
respuesta `401` de firma inválida, demostrando que la aplicación está registrada.

El fallo inicial `No matching application for ID [whatsapp-saas-e2e]` era un defecto de infraestructura:
la red externa compartida publicaba simultáneamente Reverb dev y Reverb E2E bajo `reverb`, produciendo
resolución DNS alternada. No era un bug productivo ni un problema de config cache.

Los tests esperan el estado persistido eventual (`sent` y `provider_message_id`) y, tras un claim, reabren
la conversación desde `Mias` porque correctamente deja `Sin asignar`.

U3 focused pasó 5/5 (10/10 tests), U2 focused pasó 5/5 (10/10 tests), U2 completo pasó 5/5 y la suite
completa pasó 20/20 en dos ejecuciones consecutivas.

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

### FASE 16 — Security Matrix + Hardening (U5)
- `AiSecurityMatrixTest` (AI-SEC-F01..F12): API key not in logs/frontend/audit,
  prompt/response not in telemetry, contact PII not in telemetry, tenant A/B isolation,
  malicious output as plain text, bot_paused blocks provider, provider dependency injection,
  tenant_id injection ignored, exceptions sanitized (no stack traces).
- `OpenAIProviderTest` AI-P14 updated: malformed response now throws `AIProviderException`
  (was `RuntimeException`).
- Suite FASE 16 U5: **13 tests / 44 assertions**. Suite total: **763 tests / 3055 assertions**.

### FASE 19 — Leads

#### U1 — Data Model + Normalization (backend)
- `LeadStatusTest` (LEAD-STATUS-01..06): 5 cases, values, labels, fromValue, tryFrom, serialize.
- `LeadPhoneNormalizerTest` (LEAD-PHONE-01..10): strip spaces/dashes/parens, + prefix, empty, idempotency.
- `LeadEmailNormalizerTest` (LEAD-EMAIL-01..08): trim, lowercase, plus-addressing, accented, idempotency.
- `LeadModelTest` (LEAD-DB-01..17): factory, UUID, tenant, casts, soft delete, nullable fields, fillable.
- `LeadPostgresTest` (LEAD-PG-01..12): partial indexes, uniqueness on PG (PENDING — Docker).
- Suite U1: **42 tests**.

#### U2 — Application Service + API + Permissions (backend)
- `LeadApiTest` (LEAD-API-01..25): CRUD, normalization, pagination, search, filters, transitions, dedup, resource.
- `LeadMultiTenancyTest` (LEAD-MT-01..10): cross-tenant 403/404, independent data, scope isolation.
- `LeadPermissionTest` (LEAD-PERM-01..06): owner/agent/non-member/auth checks.
- `LeadStatusTransitionTest` (LEAD-TRANS-01..13): full transition matrix.
- Suite U2: **54 tests**. Total Lead backend: **96 tests / 206 assertions**.

#### U3 — Lead Management Interface (frontend)
- `leadUtils.test.ts` (LEAD-V01..V13, V17..V19): statusLabel, sourceLabel, buildLeadQuery, buildLeadPayload, allowedLeadTransitions, statusColor, extractErrorMessage.
- `leadApi.test.ts` (LEAD-V14..V18, V19): fetchLeads, createLead, updateLead, deleteLead, tenant URL construction, filter serialization.
- Suite U3: **36 tests**. Total Vitest: **338/338**.

#### U4 — Hardening + Security Matrix (backend)
- `LeadSecurityTest` (LEAD-SEC-F01..F12): IDOR, tenant injection, mass assignment, SQL injection, XSS, PII audit, duplicate no-PII, invalid transitions → 422, agent permissions, inactive membership (PG-only skip), soft delete, cross-tenant.
- E2E CRUD (LEAD-E2E-01..07): full lifecycle, phone normalization, email normalization, status transitions, duplicate 409, cross-tenant 404, agent read-only.
- Suite U4: **19 tests / 44 assertions** (1 skipped: PG-only). Total Lead backend: **114 tests / 250 assertions**.

### FASE 20 — Tags

#### U1 — TagService + TagNodeExecutor (backend)
- `TagServiceTest` (TAG-U1-01..15, 15 tests): findOrCreateByName, assignToContact, removeFromContact, idempotencia, cross-tenant fail-closed, TagFactory.
- `TagNodeExecutorTest` (TAG-REG-01..05, 5 tests): asignación via TagService, duplicados, config vacía, idempotencia, integración completa con FlowEngine.
- `TagMultiTenancyTest` (TAG-MT-01..06, 6 tests): aislamiento cross-tenant, tenant-scoped queries, unique constraint por tenant.

#### U2 — Tag Management API + Permissions (backend)
- `TagApiTest` (TAG-API-01..15, 15 tests): CRUD API, search, paginación, validación, resource response.
- `TagPermissionTest` (TAG-PERM-01..06, 6 tests): permission matrix tags.view / tags.manage.
- `TagSecurityTest` (TAG-SEC-U2-01..10, 10 tests): IDOR, tenant injection, mass assignment, audit payload.
- `TagMultiTenancyApiTest` (TAG-MT-U2-01..10, 10 tests): aislamiento A/B en API, unique constraint cross-tenant.
- `TagPostgresTest` (10 tests): duplicate pivot PG, FK constraints, transaction rollback, cascade, tenant safety PG.
- Total U1+U2 backend: **72 tests**.

#### U3 — Tag Assignment/Removal + Domain Events (backend)
- `TagAssignmentServiceTest` (TAG-U3-01..10, 10 tests): batch assign emite `TagAssigned` por cada tag nuevo; idempotente (re-asignación no emite evento); atomicidad (un tag inválido bloquea TODO el batch sin mutar); `TagRemoved` solo en remoción real; auditoría `tag.assigned`/`tag.removed`; contacto devuelto con tags cargados.
- `TagAssignmentApiTest` (TAG-ASG-01..13, 13 tests): 200 batch/multi/idempotente; remove de tag no asignado → 200 no-op; validación 422 (array vacío, UUID inválido, duplicados, >20); cross-tenant assign → 403, remove → 404.
- `TagAssignmentPermissionTest` (TAG-ASG-PERM-01..06, 6 tests): owner/admin asignan y remueven; agent → 403 `PERMISSION_DENIED` en ambas operaciones.
- `TagAssignmentMultiTenancyTest` (TAG-ASG-MT-01..10, 10 tests): aislamiento A/B completo (assign/remove/contacto cross-tenant → 403/404, B intacto), sin fuga de datos entre tenants, unicidad de nombre de tag por tenant.
- `TagEventsTest` (TAG-EVT-01..08, 8 tests): payload de `TagAssigned`/`TagRemoved`, enum `TagAssignmentOrigin` (manual|flow), origen flow incluye `originExecutionId`, Dispatchable, propiedades readonly públicas.
- `ContactConversationResolverTest` (TAG-CONV-01..08, 8 tests): conversación más reciente por `updated_at` con desempates deterministas (`created_at` ASC, `id` ASC); null sin conversaciones; tenant-scoped (ignora conversaciones de otro tenant); sin filtro `bot_paused`/status.
- Suite U3: **55 tests nuevos** (backend). Total Tag backend: **127 tests / 271 assertions**.

#### U4 — Tag Trigger Execution (StartFlowFromTag) (backend)
- `TagTriggerExecutionTest` (TAG-U4-01..16, 16 tests): flujo válido dispara y genera outbound; trigger inactivo → skip; flow no publicado → skip; bot_paused → skip; ejecución activa → skip; case-sensitive mismatch → skip; anti-recursión origin=Flow descarta; contacto sin conversación → skip; múltiples triggers matching disparan independientemente; audit log flow.tag_triggered verificado; tag no coincidente → skip; cross-tenant trigger → skip; listener despacha 1 job por trigger matching (Queue::fake); listener no despacha sin match; listener no despacha triggers inactivos; listener no despacha triggers de otro tenant.
- Suite U4: **16 tests nuevos** (backend). Total Tag backend: **143 tests / 290 assertions**.

### Auth
- registro, login ok/ko, logout, forgot/reset, email verify, tokens.

### Billing / límites
- cuota superada → `TENANT_QUOTA_EXCEEDED` antes de enviar mensaje o ejecutar flow. AI tokens, contacts, users, knowledge documents: pendientes (U3/U4).
- usage_records incrementan.

### UsageGuard (FASE 25 U1+U2+HOTFIX, 48 U1 SQLite + 7 U1 PG + 43 U2 + 16 HOTFIX tests)

#### SQLite — Unit, Commit, Release (UsageGuardTest, 26 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardTest.php` | USG-U1-UNIT-01..19, USG-U1-COMMIT-01..04, USG-U1-RELEASE-01..03 | remaining(): unlimited/null, blocked/0, limit-usage, accounts for reservations, SubscriptionNotFoundException for missing subscription (fail-closed), full limit. reserve(): active/past-due allowed, pending/cancelled/missing subscription throws SubscriptionNotFoundException (fail-closed), under/at limit, unlimited, blocked, zero/negative quantity, period boundaries, TTL. commit(): creates usage record, updates status, rejects committed/expired. release(): updates status, no usage created, rejects committed. |

#### SQLite — Idempotency (UsageGuardIdempotencyTest, 6 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardIdempotencyTest.php` | USG-U1-IDEM-01..06 | Same key → same reservation, committed is idempotent, released allows new, expired allows new, null key creates new, different keys create different. |

#### SQLite — Multi-Tenancy (UsageGuardMultiTenancyTest, 6 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardMultiTenancyTest.php` | USG-U1-MT-01..06 | Tenant A usage doesn't affect B remaining, same key independent per tenant, commit doesn't affect B, release doesn't affect B, concurrent reserves from different tenants, reservation scoped to correct subscription. |

#### SQLite — Security (UsageGuardSecurityTest, 10 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardSecurityTest.php` | USG-U1-SEC-01..10 | tenant_id server-derived, category enum only, exception exposes safe fields only, negative/zero quantity rejected, no PII in exception messages, no API keys in source, exception code 429, reservation doesn't expose subscription internals, no hardcoded secrets. |

#### PostgreSQL — Concurrency (UsageGuardConcurrencyTest, 7 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardConcurrencyTest.php` | USG-U1-PG-CONC-01..07 | Two concurrent reserves at limit boundary — only one succeeds, idempotent concurrent reserve returns same, status transitions atomic, bulk reserves consume quota, TTL expiry enforced, different categories independent, cross-tenant concurrent independent. |

#### U2 — Message Quota Enforcement (UsageGuardMessageQuotaTest, 26 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardMessageQuotaTest.php` | USG-U2-MSG-01..15, MSG-17..26 | Reserve before dispatch (UUID pre-generated, forceFill for ID), at limit blocks, committed on success, released on provider failure, transient failure doesn't release (U1 holds slot), idempotent retry returns same reservation, tenant isolation, no double-charge, Queue::fake unit tests for reserve-commit-release lifecycle, exception renderer 409. Pending/Cancelled/Missing subscription throws SubscriptionNotFoundException (fail-closed). |

#### U2 — Flow Quota Enforcement (UsageGuardFlowQuotaTest, 12 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardFlowQuotaTest.php` | USG-U2-FLOW-01..12 | Under limit starts, at limit blocks, unlimited plan, zero limit blocked, reserve before creating execution, commit after start, later error doesn't release, duplicate start no double count, tenant isolation, Pending/Cancelled/Missing subscription throws SubscriptionNotFoundException (fail-closed), PastDue allowed, plan downgrade re-check. |

#### U2 — Message Concurrency (UsageGuardMessageConcurrencyTest, 5 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardMessageConcurrencyTest.php` | USG-U2-MSG-CONC-01..05 | Reserve-commit-release lifecycle, idempotent retry same reservation, cross-tenant independence, category independence, missing subscription throws SubscriptionNotFoundException (fail-closed). |

#### U2-HOTFIX — Fail-closed Regression (UsageGuardHotfixTest, 16 tests)

| Suite | Tests | Cobertura |
|---|---|---|
| `UsageGuardHotfixTest.php` | HF-MSG-01..07, HF-JOB-01..03, HF-FLOW-01..05, HF-SEC-01 | Message: missing/active/past-due subscription, unlimited plan, pending/cancelled throws. Job: worker reserve+commit on success, release on permanent failure, SubscriptionNotFoundException propagates. Flow: under/at limit, missing subscription throws, unlimited, pending/cancelled throws. Security: SubscriptionNotFoundException HTTP 409 with code SUBSCRIPTION_NOT_FOUND. |

Ejecución: `vendor/bin/pest --filter="UsageGuardHotfix" --no-coverage`

Ejecución SQLite: `vendor/bin/pest --filter="UsageGuard" --no-coverage`
Ejecución PG: `docker compose exec -T app vendor/bin/pest --configuration=phpunit.pgsql.xml --filter="UsageGuardConcurrency" --no-coverage`

### AI Token Quota Enforcement (FASE 25 U3, 9 PG tests + UsageGuardInterface + FakeUsageGuard)

#### U3 — AI Token Quota Enforcement (backend)

- **UsageGuardInterface extracted** (6 methods): `reserve()`, `commit()`, `release()`, `remaining()`, `recordDirect()`, `canPerformAction()`. `UsageGuard` implements the interface. AppServiceProvider binds `UsageGuardInterface → UsageGuard`.
- **FakeUsageGuard**: defaults to unlimited (reserve returns null), injectable in tests.
- **Token semantics** (all three consumers verified correct):
  - `AiNodeExecutor`: reserves `ceil(mb_strlen(prompt+systemPrompt)/3) + maxTokens` (token estimate), commits `$response->totalTokens` (actual tokens from provider).
  - `KnowledgeSearchService`: reserves `max(1, ceil(mb_strlen($query)/3))` (token estimate), commits `$response->totalInputTokens` (actual tokens from provider).
  - `EmbeddingMaterializationService`: reserves `max(1, ceil(mb_strlen(implode('', $inputTexts))/3))` (token estimate), commits `$response->totalInputTokens` (actual tokens from provider).
- All consumers updated to use `UsageGuardInterface` instead of concrete `UsageGuard`.
- `FakeUsageGuard` injected in all billing test suites and flow execution tests.

#### PostgreSQL — AI Quota Tests (AiQuotaPostgresTest, UA-PG-01..09)

| Test | Description | Status |
|---|---|---|
| UA-PG-01 | reserve→commit→ledger round-trip on real PG | PASS |
| UA-PG-02 | concurrent reserve at limit — second gets TenantQuotaExceededException | PASS |
| UA-PG-03 | idempotent same key — returns same reservation, no double charge | PASS |
| UA-PG-04 | second commit on committed reservation — throws InvalidArgumentException | PASS |
| UA-PG-05 | actual > reserved (reserve 80, commit 120) — ledger records 120 | PASS |
| UA-PG-06 | provider failure releases reservation — no usage recorded | PASS |
| UA-PG-07 | reserve→commit→remaining decremented correctly | PASS |
| UA-PG-08 | cumulative consumption — two commits accumulate, remaining correct | PASS |
| UA-PG-09 | unlimited plan — null reservation, recordDirect works, no enforcement | PASS |

Ejecución PG:
```bash
docker compose exec -T postgres dropdb -U saas --if-exists --force whatsapp_saas_handoff_u2_test
docker compose exec -T postgres createdb -U saas -O saas whatsapp_saas_handoff_u2_test
docker compose exec -T app vendor/bin/pest --configuration=phpunit.pgsql.xml --filter="AiQuotaPostgresTest" --no-coverage
```

Ejecución PG completa (incluye U3 + baseline):
```bash
docker compose exec -T postgres dropdb -U saas --if-exists --force whatsapp_saas_handoff_u2_test
docker compose exec -T postgres createdb -U saas -O saas whatsapp_saas_handoff_u2_test
docker compose exec -T app vendor/bin/pest --configuration=phpunit.pgsql.xml --testsuite=PostgresConcurrency --do-not-cache-result
```

#### PG Regression Analysis (U3)

- **PgvectorTestCase fix**: added `FakeUsageGuard` binding in `setUp()` to prevent `SubscriptionNotFoundException` in KnowledgeSearch/Embedding tests.
- **U3-caused PG regressions: 0** — all 22 failures in full PG suite are pre-existing (FaqPostgresTest FK, KnowledgeSearchPostgresTest stdClass vs array, EmbeddingMaterializationPostgresTest dimension mismatch, EmbeddingNullableMigrationTest stale column, AnalyticsPostgresTest migration rollback).
- **PG baseline (post-U3)**: 142 passed, 22 failed (all pre-existing), 0 U3-caused.

### Seguridad
- 401 sin token, 403 sin permiso, 404 en datos ajenos, throttle en login/envío.

### Rate Limiting (FASE 26 U1, 12 tests en `tests/Feature/Security/RateLimitTest.php`)

Tests de rate limiting para endpoints públicos:

| Suite | Tests | Cobertura |
|---|---|---|
| WhatsApp Webhook RL (F26-U1-WA-RL-01..06) | 6 | Under limit succeed, boundary (121st rejected), 429 shape, invalid sig still limited, verify endpoint limited, no internal config leak |
| Invitation RL (F26-U1-INV-RL-01..06) | 6 | Under limit succeed, boundary (31st rejected), 429 on over-limit, brute-force blocked, web route limited, independent buckets from WhatsApp |

Ejecución: `vendor/bin/pest --filter="RateLimitTest"`

### Commit Atomicity Tests (FASE 26 U2, 23 tests)

#### PostgreSQL — Concurrent Commit Atomicity (UsageGuardCommitAtomicityTest, UA-COMMIT-01..08)

Tests de concurrencia real con procesos PHP independientes (`proc_open`) contra PostgreSQL:

| Test | Description | Status |
|---|---|---|
| UA-COMMIT-01 | 10 concurrent commit() on same reservation — exactly 1 UsageRecord | PASS |
| UA-COMMIT-02 | 10 concurrent commitWithActual() — exactly 1 UsageRecord | PASS |
| UA-COMMIT-03 | release vs commit race — exactly one winner per round | PASS |
| UA-COMMIT-04 | Two concurrent commitWithActual with different values — one wins | PASS |
| UA-COMMIT-05 | remaining() snapshot with mixed reservation states | PASS |
| UA-COMMIT-06 | reserve during commit — total never exceeds limit | PASS |
| UA-COMMIT-07 | commit on deleted reservation throws InvalidArgumentException | PASS |
| UA-COMMIT-08 | release on deleted reservation throws InvalidArgumentException | PASS |

#### SQLite — Edge-Case Lifecycle Tests (UsageGuardEdgeCaseTest, UA-EDGE-01..15)

Tests de lifecycle completo de commit/release en SQLite:

| Test | Description | Status |
|---|---|---|
| UA-EDGE-01 | commit on reserved succeeds + creates UsageRecord | PASS |
| UA-EDGE-02 | second commit throws InvalidArgumentException | PASS |
| UA-EDGE-03 | release on reserved succeeds | PASS |
| UA-EDGE-04 | second release throws InvalidArgumentException | PASS |
| UA-EDGE-05 | release after commit throws | PASS |
| UA-EDGE-06 | commit after release throws | PASS |
| UA-EDGE-07 | commitWithActual with actual < reserved | PASS |
| UA-EDGE-08 | commitWithActual with actual > reserved | PASS |
| UA-EDGE-09 | commitWithActual with actual = reserved | PASS |
| UA-EDGE-10 | commitWithActual with actualQuantity=0 throws | PASS |
| UA-EDGE-11 | recordDirect creates usage record without reservation | PASS |
| UA-EDGE-12 | recordDirect with quantity=0 throws | PASS |
| UA-EDGE-13 | remaining decreases after commit | PASS |
| UA-EDGE-14 | remaining recovers after release | PASS |
| UA-EDGE-15 | multiple active reservations reduce remaining cumulatively | PASS |

Ejecución PG:
```bash
docker compose exec -T postgres dropdb -U saas --if-exists --force whatsapp_saas_handoff_u2_test
docker compose exec -T postgres createdb -U saas -O saas whatsapp_saas_handoff_u2_test
docker compose exec -T app vendor/bin/pest --configuration=phpunit.pgsql.xml --filter="UsageGuardCommitAtomicityTest" --no-coverage
```

Ejecución SQLite:
```bash
vendor/bin/pest --filter="UsageGuardEdgeCaseTest"
```

### WhatsApp Job Hardening (FASE 26 U3, 15 tests en `tests/Feature/Jobs/WhatsAppJobHardeningTest.php`)

#### ProcessWhatsAppStatusUpdate — failed() + Timeout (F26-U3-STAT-01..08, LIFECYCLE-01, ORDER-01)

| Test | Description | Status |
|---|---|---|
| F26-U3-STAT-01 | ProcessWhatsAppStatusUpdate has explicit timeout = 60 | PASS |
| F26-U3-STAT-02 | ProcessWhatsAppStatusUpdate has tries = 3 | PASS |
| F26-U3-STAT-03 | ProcessWhatsAppStatusUpdate has backoff [5, 15, 60] | PASS |
| F26-U3-STAT-04 | ProcessWhatsAppStatusUpdate implements failed() method | PASS |
| F26-U3-STAT-05 | failed() marks Enqueued event as failed with job_exhausted | PASS |
| F26-U3-STAT-06 | failed() is idempotent for already Processed event | PASS |
| F26-U3-STAT-07 | failed() is idempotent for already Failed event | PASS |
| F26-U3-STAT-08 | failed() handles null event gracefully | PASS |
| F26-U3-LIFECYCLE-01 | Full failed lifecycle marks event as job_exhausted | PASS |
| F26-U3-ORDER-01 | Status ordering — delivered then read preserves correct state | PASS |

#### ProcessIncomingWhatsAppMessage — Explicit Timeout (F26-U3-IN-01..02, QUOTA-01)

| Test | Description | Status |
|---|---|---|
| F26-U3-IN-01 | ProcessIncomingWhatsAppMessage has explicit timeout = 60 | PASS |
| F26-U3-IN-01b | ProcessIncomingWhatsAppMessage has tries = 3 | PASS |
| F26-U3-IN-01c | ProcessIncomingWhatsAppMessage has backoff [5, 15, 60] | PASS |
| F26-U3-IN-02 | Inbound processing regression — basic inbound still works | PASS |
| F26-U3-QUOTA-01 | Contact quota exceeded marks inbound event as failed | PASS |

#### Ejecución
```bash
vendor/bin/pest --filter="F26-U3"
```

### LIKE Wildcard Escaping (FASE 26 U4, 8 tests en `tests/Feature/Flows/FlowWebhookLikeEscapingTest.php`)

| Test | Description | Status |
|---|---|---|
| LIKE-01 | Número normal resuelve conversación | PASS |
| LIKE-02 | Phone con % no expande wildcards | PASS |
| LIKE-03 | Phone con _ no matchea un solo carácter | PASS |
| LIKE-04 | Phone con \% no actúa como wildcard | PASS |
| LIKE-05 | Phone con backslash se escapa correctamente | PASS |
| LIKE-06 | Phone vacío retorna 400 | PASS |
| LIKE-07 | Phone con + normalizado se resuelve | PASS |
| LIKE-08 | Phone con espacios/guiones se normaliza y escapa | PASS |

#### Ejecución
```bash
vendor/bin/pest tests/Feature/Flows/FlowWebhookLikeEscapingTest.php
```

### Provider Error Sanitization (FASE 26 U4, 10 tests en `tests/Feature/Security/ProviderErrorSanitizationTest.php`)

| Test | Description | Status |
|---|---|---|
| ERR-01 | Meta connection error no expone raw message | PASS |
| ERR-02 | Meta API error no expone raw Meta message | PASS |
| ERR-03 | Meta API auth error no expone token detail | PASS |
| ERR-04 | OpenAI auth error no expone raw detail | PASS |
| ERR-05 | OpenAI rate limit error no expone raw detail | PASS |
| ERR-06 | OpenAI server error no expone raw detail | PASS |
| ERR-07 | OpenAI invalid request error no expone raw detail | PASS |
| ERR-08 | Meta raw error aparece en log | PASS |
| ERR-09 | OpenAI raw error aparece en log | PASS |
| ERR-10 | WhatsApp exception errorCode y status se preservan | PASS |

#### Ejecución
```bash
vendor/bin/pest tests/Feature/Security/ProviderErrorSanitizationTest.php
```

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

## 7. Analytics (FASE 21 U1 + U2)

### Suite SQLite (Feature/Analytics/)

Tests de invariantes de dominio + servicio de agregación que corren en SQLite:

| Suite | Tests | Cobertura |
|---|---|---|
| `AnalyticsDailyTest.php` | 10 (AN-DOM-01..10) | Schema, defaults, unique, factory, fillable, timestamps |
| `ConversationMetricTest.php` | 10 (AN-DOM-11..20) | Schema, defaults, composite FK, unique, factory, fillable |
| `AggregationServiceTest.php` | 34 (AN-AGG-U01..16, AN-CM-01..10, AN-MT-U2-01..08) | AggregationService: window computation, message/conversation/flow/lead/AI token metrics, UPSERT idempotency, ConversationMetric materialization, multi-tenancy isolation |
| `AggregateDailyAnalyticsJobTest.php` | 13 (AN-JOB-01..08, AN-CMD-01..05) | Job dispatch, uniqueId, uniqueFor, tries, backoff, timeout, nonexistent tenant, end-to-end integration. Command dispatches, per-tenant dates, timezone default, empty tenants, queue routing |

Ejecución: `vendor/bin/pest --filter="AN-"`

### Suite PostgreSQL (Postgres/Analytics/)

Tests de migración real, constraints PG, y AggregationService contra PG real:

| Suite | Tests | Cobertura |
|---|---|---|
| `AnalyticsPostgresTest.php` | 12 (AN-PG-01..12) | Migración UP, FKs, UNIQUEs, composite FK cross-tenant block, índices, defaults, rollback |
| `AnalyticsAggregationPostgresTest.php` | 10 (AN-PG-U2-01..10) | AggregationService en PG real: insert, composite FK, UPSERT idempotency, cross-tenant isolation, conversation_metric UPSERT, flow executions, AI tokens, leads, timezone, JSONB columns |

Ejecución:
```bash
docker compose exec -T app vendor/bin/pest \
  --configuration=phpunit.pgsql.xml \
  --filter="AnalyticsPostgresTest|AnalyticsAggregationPostgresTest"
```

### Suite PostgreSQL U2 — Bugs descubiertos y corregidos

1. **Parámetros mixtos en SQL** (`computeConversationMetrics`): la query mezclaba `:tid` (named)
   con `?` (positional) para el `IN` clause → PostgreSQL rechaza `HY093: mixed named and positional`.
   Solución: cambiar `:tid` a `?`.
2. **TenantContext save/restore** (`aggregateForDate`): `ConversationMetric::updateOrCreate` usa
   `BelongsToTenant::creating` que auto-sobrescribe `tenant_id` con `TenantContext::id()`.
   Solución: `aggregateForDate` setea TenantContext antes del transaction y restaura en `finally`.

### Suite SQLite — API/Cache/Permission (Feature/Analytics/)

Tests del endpoint overview, cache behavior, y permisos (FASE 21 U3):

| Suite | Tests | Cobertura |
|---|---|---|
| `AnalyticsOverviewApiTest.php` | 25 (AN-API-01..13, AN-PERM-01..04, AN-MT-U3-01..08) | Default range, explicit range, max 365, 366 rejected, from>to, invalid dates, empty data, response shape, sums, avg response time, daily series, missing dates fill, owner/admin access, agent 403, unauth 401, cross-tenant 404, tenant isolation, cache scoped, inactive membership |
| `AnalyticsCacheTest.php` | 8 (AN-CACHE-01..08) | First compute, second hit, tenant-scoped, date-range-scoped, TTL, expired recompute, cached value type, no wildcard invalidation |

Ejecución: `vendor/bin/pest --filter="AN-"`

### Suite Frontend Analytics (resources/js/)

Tests del dashboard visualization (FASE 21 U4):

| Suite | Tests | Cobertura |
|---|---|---|
| `analyticsUtils.test.ts` | 34 (AN-V01..V14 + extras) | safeRate, formatDuration, formatNumber, date presets (today/daysAgo, getPresetRange), isValidRange, maxRangeDays, dateLabel, presetLabel, extractErrorMessage |
| `analyticsApi.test.ts` | 7 (AN-V15..V20 + typed data) | fetchAnalyticsOverview call shape, from/to params, default empty range |
| `StatCard.test.ts` | 3 (AN-V21, AN-V22, AN-V22b) | renders value, subtitle, zero rate |
| `analyticsDashboard.test.ts` | 20 (AN-UI-01..AN-UI-20) | page render, API load, presets, cards, charts, zero denominators, loading, error, retry, empty data, refresh, agent denied, no PII, no v-html, no polling |

Ejecución: `npm run test`

### Suite Security Matrix (tests/Feature/Analytics/AnalyticsSecurityTest.php)

Tests de hardening y cierre de FASE 21 U5:

| Suite | Tests | Cobertura |
|---|---|---|
| `AnalyticsSecurityTest.php` | 8 (AN-SEC-F05, F07a, F07b, F07c, F08a, F08b, F09, F12) | Auth-before-cache (agent denied despite owner cache), response no PII (no IDs/phone/email), response no internals (no cache/lock keys), daily series shape, aggregation numeric columns only, conversation_metrics no PII in stored data, AI telemetry only reads total_tokens, concurrent aggregation idempotent |

Ejecución: `vendor/bin/pest --filter="AnalyticsSecurityTest"`

## 8. FASE 22 — Notifications Test Suite (U1)

### Suite SQLite — Model + Enum (Feature/Notifications/)

Tests del data model y enums (FASE 22 U1):

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationModelTest.php` | 15 (NOTIF-DB-01..15) | Factory create, UUID PK, tenant_id auto-assign, tenant_id NOT mass-assignable, type cast, priority cast, data cast, read_at null=unread, read_at datetime cast, user relation, tenant-wide (null user), title/body persist, safe metadata, soft delete preserves, repeated type allowed |
| `NotificationEnumTest.php` | 4 (NOTIF-ENUM-01..04) | NotificationType cases exact, NotificationPriority cases exact, type values stable strings, both enums have labels |

Ejecución: `vendor/bin/pest --filter="Notification"`

### Suite PostgreSQL (tests/Postgres/Notifications/)

Tests de migración y constraints en PostgreSQL 16:

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationPostgresTest.php` | 12 (NOTIF-PG-01..12) | Migration up, tenant FK exists, user FK exists, FK violation rejected, null user allowed, indexes exist, read_at nullable, JSONB persistence, repeated notifications allowed, tenant cascade delete, user delete SET NULL, UP/DOWN/UP cycle |

Ejecución:
```bash
docker compose exec -T -e HANDOFF_U2_PG_TEST=1 -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=whatsapp_saas_handoff_u2_test -e DB_USERNAME=saas -e DB_PASSWORD=saas_secret app vendor/bin/pest --configuration=phpunit.pgsql.xml --no-coverage --filter="NotificationPostgresTest"
```

## 8B. FASE 22 — Notifications Test Suite (U2: Event Listeners + Dispatch)

### Suite SQLite — Event Listeners + Dispatch (Feature/Notifications/)

Tests de integración: servicios de negocio → listener → notificaciones:

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationServiceTest.php` | 10 (NOTIF-SVC-01..10) | handleHandoffRequested fan-out por miembro activo, tenant-scoped, inactivo excluido, tipo/prioridad correctos, metadata segura en data JSON, audit record, handoff repetido crea independientes, handleConversationAssigned dirigido, null para inactivo |
| `NotificationHandoffTest.php` | 10 (NOTIF-HO-01..10) | HandoffService → listener → notificaciones: fan-out 3 miembros, owner/admin/agent reciben, inactivo excluido, otro tenant no notificado, título/body genérico sin PII, prioridad high, data segura (conversation_id), idempotencia de handoff (audit log no duplicado) |
| `NotificationAssignmentTest.php` | 10 (NOTIF-ASG-01..10) | ConversationService → listener: assign crea notificación al target, otros no reciben, owner no auto-targeted, mismo assign no-op, transfer crea para nuevo agente, previous no recibe, inactivo bloqueado, cross-tenant no notificado, payload seguro, afterCommit persiste |
| `NotificationMultiTenancyTest.php` | 6 (NOTIF-MT-U2-01..06) | Tenant A targets permitidos dentro de A, A no crea para B, tenant-wide A no visible como B, inactivo en A salteado, event A no crea fila B, secuencial A→B seguro |
| `NotificationSecurityTest.php` | 8 (NOTIF-SEC-01..08) | Sin PII en title/body, sin PII en audit, sin HTML, sin tenant_id injection via data JSON, sin serialización de user model, sin SQL injection, sin API keys/secrets/tokens |

Ejecución: `vendor/bin/pest --filter="Notification"`

### Suite PostgreSQL U2 (tests/Postgres/Notifications/)

Tests de integración contra PostgreSQL 16 real:

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationPostgresU2Test.php` | 6 (NOTIF-PG-U2-01..06) | Handoff fan-out correcto en PG, assignment targeted en PG, FK constraint tenant_id, FK constraint user_id, concurrent handoff no viola constraints, cascade delete elimina notificaciones |

Ejecución:
```bash
docker compose exec -T -e HANDOFF_U2_PG_TEST=1 -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=whatsapp_saas_handoff_u2_test -e DB_USERNAME=saas -e DB_PASSWORD=saas_secret app vendor/bin/pest --configuration=phpunit.pgsql.xml --no-coverage --filter="NotificationPostgresU2Test"
```

### Suite PostgreSQL — Concurrency (tests/Postgres/Notifications/)

Tests CAS semantics under PostgreSQL (FASE 22 U3):

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationConcurrencyTest.php` | 3 (NOTIF-CON-01..03) | CAS markRead second call affects 0 rows, CAS markAllRead second call affects 0 rows, markAllRead then new notification reflects latest state |

Ejecución:
```bash
docker compose exec -T -e HANDOFF_U2_PG_TEST=1 -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=whatsapp_saas_handoff_u2_test -e DB_USERNAME=saas -e DB_PASSWORD=saas_secret app vendor/bin/pest --configuration=phpunit.pgsql.xml --no-coverage --filter="NotificationConcurrencyTest"
```

## 8C. FASE 22 — Notifications Test Suite (U3: Notification API + Permissions + Read State)

### Suite SQLite — API + Permissions + Multi-Tenancy + Security (Feature/Notifications/)

Tests del endpoint, permisos, aislamiento multi-tenant y seguridad:

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationApiTest.php` | 15 (NOTIF-API-01..15) | List paginated, ordering DESC, empty state, unread filter, read filter, counts, mark-read single, mark-read idempotent, mark-all-read, mark-all-read count, 404 on non-existent, 404 cross-user, resource safety (no tenant_id/user_id), per_page bounds |
| `NotificationPermissionTest.php` | 6 (NOTIF-PERM-01..06) | Owner views, admin views, agent views, unauthenticated 401, non-member 404, no cross-user read |
| `NotificationMultiTenancyU3Test.php` | 10 (NOTIF-MT-U3-01..10) | Tenant A isolation, user A isolation, IDOR cross-user, IDOR cross-tenant, tenant-wide excluded from personal inbox, soft-delete excluded, injection attempt, inactive membership, concurrent tenant-scoped queries, boundary notification counts |
| `NotificationSecurityU3Test.php` | 10 (NOTIF-SEC-U3-01..10) | IDOR UUID cross-user, tenant_id injection ignored, SQL injection in filter, no PII in response, no internal IDs exposed, soft-delete hidden, no mass assignment, read_status validation, data JSON safety, no verbose errors |

Ejecución: `vendor/bin/pest --filter="Notification"`

## 8E. FASE 22 — Notifications Test Suite (U4: Email Preferences + Handoff Email)

### Suite SQLite — Preference API + Multi-Tenancy + Mail (Feature/Notifications/)

Tests de preferencias de notificación y envío de email (FASE 22 U4):

| Suite | Tests | Cobertura |
|---|---|---|
| `NotificationPreferenceApiTest.php` | 8 (NOTIF-PREF-API-01..08) | Default false, enable, disable, invalid type 422, unauth 401, cross-tenant 404, response safe (no IDs), persists per tenant |
| `NotificationPreferenceMultiTenancyTest.php` | 6 (NOTIF-PREF-MT-01..06) | User A tenant A, same user tenant B independent, cannot edit other user, injection blocked, inactive denied, TenantContext no leak |
| `NotificationMailTest.php` | 10 (NOTIF-MAIL-01..10) | Owner emailed, admin emailed, agent not emailed, disabled owner not emailed, inactive admin not emailed, cross-tenant not emailed, generic content no PII, no email in body, ShouldQueue, no email on non-handoff |

Ejecución: `vendor/bin/pest --filter="NotificationPreference|NotificationMail"`

## 8D. FASE 22 — Notifications Full Test Summary

| Category | Tests |
|---|---|
| Model + Enums (U1) | 19 (15 model + 4 enum) |
| PostgreSQL Migrations (U1) | 12 |
| Event Listeners + Dispatch (U2) | 54 (10 service + 10 handoff + 10 assignment + 6 multi-tenancy + 8 security) |
| PostgreSQL U2 Integration | 6 |
| Notification API (U3) | 15 |
| Permissions (U3) | 6 |
| Multi-Tenancy U3 | 10 |
| Security U3 | 10 |
| Concurrency CAS (U3) | 3 |
| Email Preferences API (U4) | 8 |
| Preference Multi-Tenancy (U4) | 6 |
| Handoff Email (U4) | 10 |
| **Total SQLite** | **152** |
| **Total PostgreSQL** | **21** |
| **Total** | **173** |

## 9. Estado de pruebas por fase

Cada fase declara su estado en `docs/roadmap.md` (PASS/FAIL) usando el formato de reporte
definido por el usuario (ver final de `roadmap.md`).

## 10. FASE 23 — Billing/Plans Test Suite (U1)

Tests del data model, enums, multi-tenancy y seguridad (FASE 23 U1):

| Category | Tests |
|---|---|
| Enums (BILL-ENUM) | 9 (SubscriptionStatus, PlanInterval, UsageCategory: cases, values, labels) |
| Model Invariants (BILL-DOM) | 25 (Plan, Subscription, SubscriptionItem, UsageRecord: factory, UUID, casts, relations, helpers) |
| Multi-Tenancy (BILL-MT) | 8 (cross-tenant visibility, TenantContext, Plans global) |
| Security (BILL-SEC) | 10 (PII, HTML, API keys, mass assignment, integer casts) |
| **Total** | **52** |

## 11. FASE 23 — Billing/Plans Test Suite (U2)

Usage metering service tests (FASE 23 U2):

| Category | Tests |
|---|---|
| UsageTrackingService (BILL-USG) | 12 (record, quantity, category, metadata, subscription resolution, unlimited, no update/delete) |
| Period Semantics (BILL-PERIOD) | 6 (start inclusive, end exclusive, mixed records, null-period fallback, no off-by-one) |
| Summary & History (BILL-USG-14..18) | 4 (all categories, history ordering, category filter, date filter) |
| Multi-Tenancy (BILL-MT-U2) | 6 (cross-tenant visibility, sequential A→B, metadata injection, subscription scope) |
| Security (BILL-USG-SEC) | 7 (server-derived tenant_id, enum-only category, metadata whitelist, no PII, no secrets, no update/delete methods, exception safety) |
| Concurrency (BILL-USG-CONC) | 1 (concurrent inserts produce correct SUM) |
| **Total U2** | **36** |
| **Total FASE 23 (U1+U2)** | **88** |

## 12. FASE 23 — Billing API Layer Test Suite (U3)

Billing API tests (FASE 23 U3):

| Category | Tests |
|---|---|
| Plan API (BILL-API-PLAN) | 5 (list active plans, show plan, 404 nonexistent, 401 unauth, 403 agent) |
| Subscription API (BILL-API-SUB) | 11 (index null, index active, store create, store invalid 404, store no body 422, store replace existing, patch change, patch no sub 404, patch same plan no-op, delete cancel, delete no sub 404) |
| Usage API (BILL-API-USG) | 8 (index summary, index 404 no sub, history paginated, history category filter, history date filter, history per_page, 401 unauth, 403 agent) |
| Permission Matrix (BILL-API-PERM) | 10 (owner plans, admin plans, agent 403 plans, owner manage sub, admin 403 manage, agent 403 manage, owner view usage, admin view usage, agent 403 usage, non-member 403) |
| Multi-Tenancy (BILL-API-MT-U3) | 5 (A→B sub 404, B→A sub 404, A assign B 404, A usage B 404, A plans B 404) |
| Security (BILL-API-SEC-U3) | 6 (non-UUID rejected, empty body 422, invalid tenant UUID, invalid plan UUID, no tenant_id in plan response, no tenant_id in usage response) |
| **Total U3** | **45** |
| **Total FASE 23 (U1+U2+U3)** | **133** |

## 13. FASE 23 — Billing Frontend Test Suite (U4)

Billing frontend tests (FASE 23 U4 — Vitest, jsdom):

| Category | Tests |
|---|---|
| API Wrappers (BILL-FE-U4-01..07) | 10 (fetchPlans URL/return, fetchCurrentSubscription URL/null, assignPlan POST, changePlan PATCH, cancelSubscription DELETE, fetchUsageSummary URL/return, fetchUsageHistory URL/filter/params) |
| Utilities (BILL-FE-U4-17..19) | 20 (categoryLabel Spanish, statusLabel, statusColor, formatCurrency, formatUsageValue, usagePercent, isUnlimited, formatDate, formatDateTime, extractErrorMessage, buildUsageSummary) |
| Dashboard Page (BILL-FE-U4-08..27) | 20 (render, fetch plans/sub/usage on mount, current plan name, history table, owner manage buttons, admin read-only, agent denied, assign plan dialog, cancel dialog, double-submit, loading state, error state, empty states, unlimited usage, NaN safety, tenant switch, no v-html, no hardcoded prices, security no tenant_id) |
| **Total U4** | **50** |
| **Total FASE 23 (U1+U2+U3+U4)** | **183** |

## 14. FASE 24 — Provider Infrastructure + Mappings Test Suite (U1)

Billing provider infrastructure tests (FASE 24 U1):

| Category | Tests |
|---|---|
| BillingCustomer model (BILL-U1-MOD-01..18) | 18 (factory create, tenant-scoped, unique tenant+provider, unique provider+customer_id, different providers per tenant, plan stripe columns nullable, plan stripe mass-assignable, subscription stripe nullable, subscription stripe mass-assignable, cancel_at_period_end default, cancel_at_period_end mass-assignable, SubscriptionStatus Pending case, isActive unchanged Pending not active, BillingCustomerData DTO creation, BillingCustomerData fromProvider, BillingProviderException throwable, BillingProviderException retryable, PlanResource no stripe exposure) |
| Multi-tenancy (BILL-U1-MT-01..06) | 6 (visible to A, invisible to B, cross-tenant provider_customer_id blocked, subscription stripe isolation, FK cascade, index verification) |
| Provider (BILL-U1-PROV-01..08) | 8 (implements interface, providerName, createCustomer empty key, retrieveCustomer empty key, validatePrice empty key, webhook secret empty, webhook invalid sig, constructor webhook secret) |
| PostgreSQL (BILL-U1-PG-01..08) | 8 (table exists, columns, valid insert, FK, unique tenant+provider, unique provider+customer_id, cascade delete, plan stripe columns) |
| **Total U1** | **32** |
| **Total FASE 23+24 (U1+U2+U3+U4+U1)** | **215** |

## 15. FASE 24 — Checkout + Customer Portal Test Suite (U2)

Checkout + portal tests (FASE 24 U2):

| Category | Tests |
|---|---|
| Provider (BILL-U2-PROV-01..05) | 5 (implements interface checkout, implements interface portal, checkout signature, portal signature, providerName) |
| CheckoutService (BILL-U2-SVC-01..10) | 10 (create customer if missing, reuse existing customer, non-existent plan, free plan bypass, invalid interval, no price configured, unauthorized agent, portal URL, portal without customer, portal admin denied) |
| API (BILL-U2-API-01..14) | 14 (owner checkout, response URL, yearly, admin rejected, agent rejected, missing plan_id, missing interval, invalid interval, extra fields stripped, non-existent plan 404, free plan 422, billing customer created, owner portal, admin portal rejected) |
| Multi-Tenancy (BILL-U2-MT-01..06) | 6 (A→B checkout blocked, B→A checkout blocked, A→B portal blocked, B→A portal blocked, no customer leaking, unique customers per tenant) |
| Security (BILL-U2-SEC-01..06) | 6 (billing.manage owner/admin/agent matrix, portal owner/admin/agent, unauthenticated 401, outsider 403/404, no price_id in response, idempotent customer creation) |
| PostgreSQL (BILL-U2-PG-01..04) | 4 (unique tenant+provider, different providers, unique provider_customer_id, plan stripe columns nullable) |
| **Total U2 (Pest)** | **39** |
| **Frontend Vitest (BILL-FE-U2-01..03)** | **7** (createCheckoutSession URL/params, yearly interval, createPortalSession URL/return, checkout payload security) |
| **Total U2** | **46** |
| **Total FASE 23+24 (U1+U2+U3+U4+U1+U2)** | **231** |

## 16. FASE 24 — Stripe Webhook Ingestion + Subscription Sync Test Suite (U3)

Stripe webhook tests (FASE 24 U3):

| Category | Tests |
|---|---|
| Signature Verification (BILL-U3-SIG) | 7 (valid signature, invalid signature, malformed payload, missing header, empty body, replay attack, different event types) |
| Webhook Events (BILL-U3-WH) | 12 (checkout.session.completed creates pending, invoice.paid activates, subscription.updated syncs, subscription.deleted cancels, payment_failed sets past_due, unknown event type skipped, idempotency via unique constraint, customer resolution from BillingCustomer, no tenant middleware, public endpoint, response always received:true, audit log) |
| Event Ordering (BILL-U3-ORD) | 5 (stale event ignored, future event applied, cancelled not resurrected, same timestamp ignored, provider_updated_at monotonic) |
| Security (BILL-U3-SEC) | 6 (no auth required, CSRF not checked, tenant not from middleware, no PII in logs, webhook_events ledger does not use BelongsToTenant, subscription sync validates tenant) |
| Subscription Sync (BILL-U3-SYNC) | 10 (invoice.paid activates pending, invoice.paid updates period, subscription.updated changes plan, subscription.deleted cancels, payment_failed sets past_due, checkout.session.completed creates pending not active, multiple events idempotent, webhook event ledger records all, duplicate event no-op, error in ledger) |
| Multi-Tenancy (BILL-U3-MT) | 6 (tenant A webhook does not affect B, customer resolution scoped, different tenants independent, cross-tenant customer ID blocked, concurrent webhooks isolated, tenant context set correctly) |
| **Total U3 (SQLite)** | **46** |
| **Total FASE 24 (U1+U2+U3)** | **124** |
| **Total FASE 23+24 (U1+U2+U3+U4+U1+U2+U3)** | **277** |

## 17. FASE 24 — Billing Frontend Provider UX Test Suite (U4)

Billing frontend provider UX tests (FASE 24 U4 — Vitest, jsdom):

| Category | Tests |
|---|---|
| Page renders (BILL-FE-U4-08) | 1 (renders Billing heading) |
| Fetch plans/subscription/usage (BILL-FE-U4-09..12) | 6 (calls plans API, calls subscriptions API, shows current plan, calls usage API, calls usage history API, shows history table) |
| Owner manage / admin read-only / agent denied (BILL-FE-U4-13..15) | 4 (shows select plan button for owner, shows cancel link for owner, hides manage buttons for admin, hides content for agent) |
| Paid plan dialog / free plan dialog (BILL-FE-U4-16..16b) | 2 (Ir a pagar for paid, Confirmar for free) |
| Cancel dialog / double-submit (BILL-FE-U4-17..18b) | 3 (cancel confirmation, checkout button not disabled initially, cancel button not disabled initially) |
| Loading / error / empty states (BILL-FE-U4-19..21) | 5 (loading state, error message on API failure, empty subscription, empty plans, empty usage history) |
| Unlimited / NaN safety (BILL-FE-U4-22..23) | 2 (∞ for null limits, no NaN in rendered text) |
| Tenant switch / security (BILL-FE-U4-24..27) | 4 (API calls use current tenant, changePlan sends only plan_id, no v-html, prices from API not template) |
| Checkout redirect (BILL-FE-U4-28) | 1 (paid plan triggers checkout API call) |
| Free plan assigns locally (BILL-FE-U4-29) | 1 (free plan uses local assign API) |
| Checkout return feedback (BILL-FE-U4-30..32) | 3 (success message on checkout=success, cancelled message on checkout=cancelled, success does not set active) |
| Portal redirect / error / admin rejected (BILL-FE-U4-33..35) | 3 (portal button calls API, admin does not see portal button, portal error handling) |
| Cancel at period end (BILL-FE-U4-36) | 1 (shows period end message when cancel_at_period_end is true) |
| Status labels (BILL-FE-U4-37) | 3 (Active, PastDue, Cancelled status labels) |
| Security: no provider data leaks (BILL-FE-U4-38..44) | 7 (no stripe_price_id in types, no customer ID in page, no Stripe secret in page, no subscription status mutation, no raw provider errors, safe URL validation, XSS-safe rendering) |
| billingApi functions (BILL-FE-U4-45..47) | 3 (createCheckoutSession payload, createPortalSession request, cancelSubscription DELETE) |
| Tenant switch / XSS / interval (BILL-FE-U4-48..50) | 3 (tenant switch clears state, no eval/innerHTML, interval pricing visible) |
| **Total U4** | **52** |
| **Total FASE 23+24 (U1+U2+U3+U4+U1+U2+U3+U4)** | **329** |

## 18. FASE 24 — Billing Hardening + Closure (U5)

No new tests added. U5 audited U1–U4 and fixed P1/P2 findings in existing code. All existing tests remain green.

| Category | Notes |
|---|---|
| Backend billing tests (U1+U2+U3) | 252/252 pass after P1-01 (SQLSTATE), P1-02 (transient rethrow), P1-03 (ordering) fixes |
| Frontend billing tests (U4) | 52/52 pass after P1-05 (pending label), P2-02 (URL validation), P2-03 (dialog clear), P2-04 (history error) fixes |
| billingApi tests | 15/15 pass |
| billingUtils tests | 25/25 pass (updated P1-05 pending label test) |
| **Total U5** | **No new tests. Existing 252 backend + 532 frontend = all green.** |
| **Total FASE 23+24** | **329 backend billing + 532 frontend = 861 tests (billing-related subset unchanged)** |

## 19. Pre-U1 Baseline Cleanup — PostgreSQL Harness + PHPStan (FASE 25)

### PostgreSQL Test Harness

**Root cause:** The test database `whatsapp_saas_handoff_u2_test` already existed in Docker PostgreSQL,
but the `phpunit.pgsql.xml` configuration used Docker service names (`postgres`, `redis`) that only
resolve from within the Docker network. Tests could not run from the host.

**Fix:** The config was already correct for Docker-internal execution. The documented command is:

```bash
docker compose exec -T app vendor/bin/pest \
  --configuration=phpunit.pgsql.xml \
  --filter="BillingU1|BillingU2|BillingU3" \
  --no-coverage
```

**Safety guards** (in `PostgresConcurrencyTestCase`):
- `HANDOFF_U2_PG_TEST=1` env var required
- `database.connections.pgsql.host = 'postgres'` (Docker service name)
- `database.connections.pgsql.database = 'whatsapp_saas_handoff_u2_test'`
- `redis.default.database = 14` and `redis.cache.database = 14`
- Runtime check: `SELECT current_database()` must return `whatsapp_saas_handoff_u2_test`

**BILL-U3-PG-04 fix:** Added `TenantContext::setId($tenant->id)` before `Subscription::create()`
(the `BelongsToTenant` trait requires an active TenantContext for writes).

### PG Billing Tests

| Suite | Tests | Assertions | Status |
|---|---|---|---|
| BillingU1PostgresTest | 8 | 13 | PASS |
| BillingU2PostgresTest | 4 | 5 | PASS |
| BillingU3PostgresTest | 6 | 7 | PASS (after fix) |
| **Total PG Billing** | **18** | **25** | **PASS** |

### PHPStan Baseline Cleanup

Reduced from **13 errors to 0**:

| File | Error | Fix |
|---|---|---|
| `PlanNotFoundException.php` | Constructor invoked with 1 param, 0 required | Added optional `?string $message = null` parameter |
| `StripeWebhookService.php` | `$provider` property only written | Removed unused constructor parameter |
| `StripeWebhookService.php` | `&&` always false + `===` always false | Simplified `recordEvent` duplicate check — return existing for handler to decide |
| `StripeWebhookService.php` | `->timestamp` on string | Used `Carbon::parse()->getTimestamp()` for explicit typing |
| `StripeWebhookService.php` | Missing iterable value types (×2) | Added `@param array<string, mixed>` PHPDoc |
| `BillingCustomerData.php` | Missing iterable value type | Added `@var array<string, mixed>` PHPDoc |
| `ProviderWebhookEvent.php` | Missing iterable value type | Added `@var array<string, mixed>` PHPDoc |
| `ProviderWebhookEvent.php` | Redundant `??` (×4) | Removed null coalescing on keys guaranteed by `@param` type |

### Quality Gates

| Gate | Result |
|---|---|
| Unit tests (SQLite) | 444/444 PASS |
| Feature tests (SQLite) | 2,133/2,139 PASS (6 pre-existing) |
| CAP SQLite | 36/36 PASS (76 assertions) |
| CAP PostgreSQL concurrency | 6/6 PASS (29 assertions) |
| PHPStan (level 6) | 0 errors |
| Pint | PASS |
| vue-tsc | PASS |
| Vite build | PASS |
| composer audit | 0 vulnerabilities |
| npm audit | 0 vulnerabilities |

## 20. FASE 25 U5 — Hardening + Closure

### Final Quality Gates (U5)

| Gate | Result |
|---|---|
| Unit tests (SQLite) | 444/444 PASS |
| Billing tests (SQLite) | 395/395 PASS |
| Feature tests (SQLite) | 830/830 PASS |
| PG Capacity concurrency | 6/6 PASS |
| Frontend (Vitest) | 532/532 PASS |
| **Total** | **2,207 tests PASS** |
| PHPStan | 0 errors |
| Pint | PASS |
| vue-tsc | PASS |
| Vite build | PASS |
| composer audit | 0 vulnerabilities |
| npm audit | 0 vulnerabilities |
| Secrets/PII scan | clean |

### Fixes Applied

| Fix | Severity | Description |
|---|---|---|
| commit() atomicity | P0 | UsageGuard::commit()/commitWithActual() wrapped in DB::transaction() |
| Dead code | P0 | Removed unused Tenant::find() from commit()/commitWithActual() |
| Usage API capacity | P0 | computeCurrentCapacityCounts() — /usage reports real counts |
| TagNodeExecutor | P1 | Dispatcher → Contracts\Events\Dispatcher |
| FaqHardeningTest | P1 | FakeCapacityGuard binding (U4 regression) |
| MessageApiTest | P1 | FakeCapacityGuard binding (U4 regression) |
| ReprocessOutboxTest | P1 | FakeCapacityGuard binding (U4 regression) |
| isPostgres() | P1 | config → DB::connection()->getDriverName() in UsageGuard |

### FASE 27 U1+U2+U3 — Security Hardening (88 tests)

#### Security Headers (`tests/Feature/Security/SecurityHeadersTest.php`, 13 tests)

| Test | Description | Status |
|---|---|---|
| HDR-01 | X-Content-Type-Options = nosniff | PASS |
| HDR-02 | X-Frame-Options = DENY | PASS |
| HDR-03 | X-XSS-Protection = 0 | PASS |
| HDR-04 | Referrer-Policy = strict-origin-when-cross-origin | PASS |
| HDR-05 | Content-Security-Policy present | PASS |
| HDR-06 | CSP frame-ancestors 'none' | PASS |
| HDR-07 | CSP no unsafe-eval | PASS |
| HDR-08 | CSP required directives present | PASS |
| HDR-09 | Permissions-Policy restricts camera/microphone/geolocation | PASS |
| HDR-10 | HSTS absent on HTTP | PASS |
| HDR-11 | Headers present on web GET | PASS |
| HDR-12 | Headers present on API POST | PASS |
| HDR-13 | Headers present on WhatsApp webhook verify | PASS |

#### CORS (`tests/Feature/Security/CorsConfigTest.php`, 6 tests)

| Test | Description | Status |
|---|---|---|
| CORS-01 | CORS config exists and loadable | PASS |
| CORS-02 | Paths include api/* and sanctum | PASS |
| CORS-03 | No wildcard origins with credentials | PASS |
| CORS-04 | supports_credentials false by default | PASS |
| CORS-05 | allowed_origins env-driven | PASS |
| CORS-06 | WhatsApp webhook unaffected | PASS |

#### Session (`tests/Feature/Security/SessionConfigTest.php`, 7 tests)

| Test | Description | Status |
|---|---|---|
| SESS-01 | http_only defaults true | PASS |
| SESS-02 | same_site = lax | PASS |
| SESS-03 | encrypt config readable | PASS |
| SESS-04 | secure cookie config readable | PASS |
| SESS-05 | lifetime positive integer | PASS |
| SESS-06 | .env.example SESSION_ENCRYPT=true | PASS |
| SESS-07 | .env.example SESSION_SECURE_COOKIE=true | PASS |

#### Ejecución
```bash
vendor/bin/pest tests/Feature/Security/SecurityHeadersTest.php
vendor/bin/pest tests/Feature/Security/CorsConfigTest.php
vendor/bin/pest tests/Feature/Security/SessionConfigTest.php
vendor/bin/pest tests/Feature/Security/TokenExpirationTest.php
vendor/bin/pest tests/Feature/Security/TrustProxiesTest.php
vendor/bin/pest tests/Feature/Security/StructuredErrorTest.php
vendor/bin/pest tests/Feature/Security/TokenExpirationRolloutTest.php
```

#### Token Expiration (`tests/Feature/Security/TokenExpirationTest.php`, 10 tests)

| Test | Description | Status |
|---|---|---|
| TOK-01 | Token valid before expiration | PASS |
| TOK-02 | Token rejected after expiration | PASS |
| TOK-03 | Expired token returns 401 | PASS |
| TOK-04 | Expired token does not leak reason/details | PASS |
| TOK-05 | Valid token still works when not expired | PASS |
| TOK-06 | Logout still revokes token | PASS |
| TOK-07 | Tenant context unchanged with token expiration | PASS |
| TOK-08 | Expiration config readable and configurable | PASS |
| TOK-09 | Register response includes token metadata | PASS |
| TOK-10 | Login response includes token metadata | PASS |

#### TrustProxies (`tests/Feature/Security/TrustProxiesTest.php`, 8 tests)

| Test | Description | Status |
|---|---|---|
| PROXY-01 | Untrusted forwarded-for header is ignored | PASS |
| PROXY-02 | Trusted proxy forwarded-for is honored | PASS |
| PROXY-03 | Forwarded-proto=https secure when proxy trusted | PASS |
| PROXY-04 | Forwarded-proto ignored when proxy untrusted | PASS |
| PROXY-05 | HSTS present under trusted HTTPS proxy in production | PASS |
| PROXY-06 | Spoofed forwarded-for does not affect rate limit key | PASS |
| PROXY-07 | trustedproxy config file exists and loadable | PASS |
| PROXY-08 | trustedproxy proxies is env-driven | PASS |

#### Structured Errors (`tests/Feature/Security/StructuredErrorTest.php`, 10 tests)

| Test | Description | Status |
|---|---|---|
| ERR-01 | API 401 safe structured response | PASS |
| ERR-02 | API 403 safe structured response | PASS |
| ERR-03 | API 404 safe structured response | PASS |
| ERR-04 | API 422 validation response unchanged | PASS |
| ERR-05 | Rate limit 429 safe structured response | PASS |
| ERR-06 | Valid authenticated request works after U2 | PASS |
| ERR-07 | Invalid login returns 422 not 500 | PASS |
| ERR-08 | APP_DEBUG true does not leak internals | PASS |
| ERR-09 | 404 uses structured format not HTML | PASS |
| ERR-10 | Web requests return HTML not forced JSON | PASS |

#### Token Rollout Verification (`tests/Feature/Security/TokenExpirationRolloutTest.php`, 6 tests)

| Test | Description | Status |
|---|---|---|
| ROLL-01 | Old token (>24h) with null expires_at is INVALID under global expiration | PASS |
| ROLL-02 | Recent token (<24h) with null expires_at is valid | PASS |
| ROLL-03 | Token with past expires_at is invalid regardless of global expiration | PASS |
| ROLL-04 | Null expiration disables created_at check entirely | PASS |
| ROLL-05 | Old token with future expires_at fails global age check (ANDed) | PASS |
| ROLL-06 | Login token metadata matches actual validation | PASS |

#### Structured Logging (`tests/Feature/Security/StructuredLoggingTest.php`, 13 tests)

| Test | Description | Status |
|---|---|---|
| LOG-01 | Log line is valid JSON | PASS |
| LOG-02 | Timestamp field present | PASS |
| LOG-03 | Level field present | PASS |
| LOG-04 | Message/event key present | PASS |
| LOG-05 | Channel and extra fields present | PASS |
| LOG-06 | Context fields preserved | PASS |
| LOG-07 | Multiple log lines are separate JSON objects | PASS |
| LOG-08 | Newline-safe JSON | PASS |
| LOG-09 | No PII fields auto-injected | PASS |
| LOG-10 | SafeLogContext sanitizes provider messages | PASS |
| LOG-11 | SafeLogContext sanitizes phone numbers | PASS |
| LOG-12 | SafeLogContext truncates long messages | PASS |
| LOG-13 | SafeLogContext handles null/empty | PASS |

#### Request Correlation (`tests/Feature/Security/RequestCorrelationTest.php`, 9 tests)

| Test | Description | Status |
|---|---|---|
| REQ-01 | Missing X-Request-ID generates UUID | PASS |
| REQ-02 | Valid incoming UUID preserved | PASS |
| REQ-03 | Invalid incoming ID replaced | PASS |
| REQ-04 | Oversized incoming ID replaced | PASS |
| REQ-05 | Response contains X-Request-ID | PASS |
| REQ-06 | Request attributes contain request_id | PASS |
| REQ-07 | Two sequential requests do not leak IDs | PASS |
| REQ-08 | Error response still has X-Request-ID | PASS |
| REQ-09 | Safe alphanumeric IDs accepted | PASS |

#### Log Context Processors (`tests/Feature/Security/LogContextTest.php`, 7 tests)

| Test | Description | Status |
|---|---|---|
| LOG-14 | TenantContextProcessor adds tenant_id when bound | PASS |
| LOG-15 | TenantContextProcessor omits tenant_id when no context | PASS |
| LOG-16 | TenantContextProcessor does not leak between tenants | PASS |
| LOG-17 | RequestContextProcessor resolves from request attributes | PASS |
| LOG-18 | RequestContextProcessor omits request_id when no context | PASS |
| LOG-19 | SafeLogContext strips Bearer tokens | PASS |
| LOG-20 | SafeLogContext strips email addresses | PASS |

#### Provider Log Privacy (`tests/Feature/Security/ProviderLogPrivacyTest.php`, 5 tests)

| Test | Description | Status |
|---|---|---|
| PII-01 | Meta raw text with phone not in logs | PASS |
| PII-02 | OpenAI raw error with API key not emitted | PASS |
| PII-03 | Stripe sensitive content scrubbed | PASS |
| PII-04 | Authorization header never emitted raw | PASS |
| PII-05 | Message body not auto-emitted in provider logs | PASS |

#### Sentry Event Scrubber (`tests/Feature/SentryScrubberTest.php`, 12 tests)

| Test | Description | Status |
|---|---|---|
| SCRUB-01 | Strips Authorization header from request | PASS |
| SCRUB-02 | Strips Cookie header from request | PASS |
| SCRUB-03 | Strips X-Hub-Signature-256 header | PASS |
| SCRUB-04 | Strips Stripe-Signature header | PASS |
| SCRUB-05 | Strips sensitive query parameters | PASS |
| SCRUB-06 | Strips request body for webhook paths | PASS |
| SCRUB-07 | Strips request body for /login path | PASS |
| SCRUB-08 | Preserves request body for non-sensitive paths | PASS |
| SCRUB-09 | Scrubs email PII from extra data | PASS |
| SCRUB-10 | Scrubs phone numbers from extra data | PASS |
| SCRUB-11 | Scrubs OpenAI API keys from extra data | PASS |
| SCRUB-12 | Strips user data except id | PASS |

#### Sentry Config + Fail-Open (`tests/Feature/SentryConfigTest.php`, 8 tests)

| Test | Description | Status |
|---|---|---|
| QUEUE-01 | Config loads with correct defaults | PASS |
| QUEUE-02 | Tracing disabled by default (opt-in) | PASS |
| QUEUE-03 | max_request_body_size is none | PASS |
| QUEUE-04 | before_send callback registered | PASS |
| QUEUE-05 | ignore_transactions excludes /up | PASS |
| QUEUE-06 | ignore_exceptions includes business exceptions | PASS |
| FAIL-01 | Scrubber returns event (fail-open) | PASS |
| FAIL-02 | Scrubber survives invalid request data | PASS |

#### Frontend Sentry Scrubber (`resources/js/sentry.test.ts`, 12 tests)

| Test | Description | Status |
|---|---|---|
| F28-U3-SCRUB-01 | Email removed from extra | PASS |
| F28-U3-SCRUB-02 | Phone removed from extra | PASS |
| F28-U3-SCRUB-03 | Token query param removed from URL | PASS |
| F28-U3-SCRUB-04 | Authorization header removed | PASS |
| F28-U3-SCRUB-05 | Request data removed | PASS |
| F28-U3-SCRUB-06 | Message content scrubbed | PASS |
| F28-U3-SCRUB-07 | Stack trace preserved (message field) | PASS |
| F28-U3-SCRUB-08 | Malformed event does not throw | PASS |
| F28-U3-SCRUB-09 | API key scrubbed from extra | PASS |
| F28-U3-SCRUB-10 | User keeps only id | PASS |
| F28-U3-SCRUB-11 | Nested context values scrubbed | PASS |
| F28-U3-SCRUB-12 | CSRF token header removed | PASS |

#### CSP Sentry Domain (`tests/Feature/Security/SecurityHeadersTest.php`, 3 new tests)

| Test | Description | Status |
|---|---|---|
| F28-U3-CSP-01 | CSP connect-src includes Sentry domain when DSN configured | PASS |
| F28-U3-CSP-02 | CSP connect-src has no Sentry when DSN empty | PASS |
| F28-U3-CSP-03 | CSP connect-src has no Sentry when DSN null | PASS |

#### Health/Readiness + Queue Monitoring (`tests/Feature/Security/HealthReadinessTest.php`, 27 tests)

| Test | Description | Status |
|---|---|---|
| F28-U4-HEALTH-01 | Liveness healthy returns 200 | PASS |
| F28-U4-HEALTH-02 | Liveness does not check database | PASS |
| F28-U4-HEALTH-03 | Liveness does not check redis | PASS |
| F28-U4-HEALTH-04 | Liveness does not check queue | PASS |
| F28-U4-HEALTH-05 | Readiness healthy returns 200 | PASS |
| F28-U4-HEALTH-06 | Readiness does not check external providers | PASS |
| F28-U4-HEALTH-07 | Safe response format no exception details | PASS |
| F28-U4-HEALTH-08 | X-Request-ID present on health | PASS |
| F28-U4-HEALTH-09 | X-Request-ID present on ready | PASS |
| F28-U4-HEALTH-10 | Readiness includes scheduler info | PASS |
| F28-U4-HEALTH-11 | checkLiveness returns only app key | PASS |
| F28-U4-HEALTH-12 | checkReadiness returns database redis queue | PASS |
| F28-U4-HEALTH-13 | checkApp verifies config is accessible | PASS |
| F28-U4-HEALTH-14 | Scheduler heartbeat returns null when no heartbeat | PASS |
| F28-U4-HEALTH-15 | Scheduler heartbeat returns true when fresh | PASS |
| F28-U4-HEALTH-16 | Scheduler heartbeat returns false when stale | PASS |
| F28-U4-HEALTH-17 | allOk returns true when all statuses ok | PASS |
| F28-U4-HEALTH-18 | allOk returns false when any status fail | PASS |
| F28-U4-SCHED-01 | Scheduler heartbeat command writes timestamp | PASS |
| F28-U4-SCHED-02 | Scheduler heartbeat timestamp is fresh | PASS |
| F28-U4-AN-01 | AggregateDailyAnalyticsCommand dispatches to analytics queue | SKIP (no PG tenants table) |
| F28-U4-AN-02 | AggregateDailyAnalyticsJob failed() logs structured warning | PASS |
| F28-U4-Q-01 | SentryQueueFailureServiceProvider is registered | PASS |
| F28-U4-Q-02 | SentryQueueFailureServiceProvider class exists | PASS |
| F28-U4-Q-03 | Queue config has default and analytics queues | PASS |
| F28-U4-Q-04 | failed_jobs config uses database-uuids driver | PASS |
| F28-U4-CFG-01 | observability config is loadable | PASS |

#### Failed Login Audit (`tests/Feature/Security/FailedLoginAuditTest.php`, 6 tests)

| Test | Description | Status |
|---|---|---|
| F28-U5-AUTH-01 | Failed API login emits audit event | PASS |
| F28-U5-AUTH-02 | Failed login does not store email in audit | PASS |
| F28-U5-AUTH-03 | Failed login does not distinguish user exists | PASS |
| F28-U5-AUTH-04 | Failed login includes request_id | PASS |
| F28-U5-AUTH-05 | Successful login does not emit failed event | PASS |
| F28-U5-AUTH-06 | Failed login does not store password hash | PASS |

#### Retention Commands (`tests/Feature/Security/RetentionCommandTest.php`, 6 tests)

| Test | Description | Status |
|---|---|---|
| F28-U5-RET-01 | Audit prune removes old records | PASS |
| F28-U5-RET-02 | Audit prune dry run does not delete | PASS |
| F28-U5-RET-03 | Audit prune preserves recent records | PASS |
| F28-U5-RET-04 | Audit prune respects cutoff boundary | PASS |
| F28-U5-RET-05 | Failed jobs prune removes old records | PASS |
| F28-U5-RET-06 | Failed jobs prune dry run does not delete | PASS |

### FASE 29 U1 — Coverage Infrastructure + Critical Authorization/Billing Baseline

#### Coverage Setup

| Component | Tool | Config |
|---|---|---|
| Backend PHP coverage | PCOV | Dockerfile `coverage` build target, `docker-compose.coverage.yml` |
| Backend config | phpunit.xml | `<coverage>` with text, clover (`coverage.xml`), html (`coverage-html/`) reporters |
| Frontend JS/TS coverage | `@vitest/coverage-v8` | `vitest.config.ts` coverage config (v8 provider) |

**Measuring backend coverage (Docker required)**:
```bash
docker compose -f docker-compose.yml -f docker-compose.coverage.yml build coverage
docker compose -f docker-compose.yml -f docker-compose.coverage.yml run --rm coverage \
  php -d memory_limit=512M vendor/bin/pest --coverage
```

**Measuring frontend coverage (host)**:
```bash
npx vitest run --coverage
```

#### Authorization Policy Tests (POL-01..26)

| File | Tests | Coverage |
|---|---|---|
| `TenantUserPolicyTest.php` | POL-01..10 | viewAny/update/delete for Owner/Admin/Agent + cross-tenant |
| `TenantPolicyTest.php` | POL-11..18 | viewAny/view/update/switch for member/non-member/suspended |
| `TenantInvitationPolicyTest.php` | POL-19..26 | create/viewAny/delete for Owner/Admin/Agent + cross-tenant |

#### Billing Service Tests (SUB-01..14, CUST-01..08)

| File | Tests | Coverage |
|---|---|---|
| `SubscriptionServiceTest.php` | SUB-01..14 | listPlans, currentSubscription, assignPlan, changePlan, cancel + tenant isolation |
| `BillingCustomerServiceTest.php` | CUST-01..08 | findByTenant, ensureCustomer, idempotency, provider error safety, no PII, tenant isolation |

#### Test Totals After FASE 29 U1

| Layer | Before | After | Delta |
|---|---|---|---|
| Backend (Pest) | 2344 | 2392 | +48 |
| Frontend (Vitest) | 544 | 544 | 0 |
| Total | 2888 | 2936 | +48 |

### FASE 29 U2 — Tenancy + Auth Hardening Gaps

Cubre 5 unidades sin tests dedicados: `TenantMiddleware`, `AuthorizationService`, `MemberService`,
`RecoverPendingWhatsAppMessage` y `MessageOriginClassifier`. 60 tests nuevos.

| File | Tests | Coverage |
|---|---|---|
| `TenantMiddlewareTest.php` | TEN-01..12 | resolution + deny (403 JSON `NO_TENANT` / web abort 403) + TenantContext set/clear/leak prevention + membership/status edge cases |
| `AuthorizationServiceTest.php` | AUTHZ-01..16 | can/authorize/permissionsForTenant, owner/admin/agent permission matrix, inactive tenant, cross-tenant |
| `MemberServiceTest.php` | MEM-01..16 | list/changeRole/remove, last-owner safeguard, IDOR safety, current_tenant_id cleanup |
| `RecoverPendingWhatsAppMessageTest.php` | REC-01..07 | constructor, tries, status transition + TenantContext restore, failed() handling |
| `MessageOriginClassifierTest.php` | ORIGIN-01..09 | automation/human/handoff/unknown branch classification |

**Nota de implementación (REC)**: `RecoverPendingWhatsAppMessage::handle()` instancia
`SendWhatsAppMessage` y lo ejecuta directamente (`->handle()`), por lo que `Queue::fake()` no lo
captura; la recuperación es síncrona en el worker. Documentado, NO un bug.

**Nota de tenancy (TEN-05)**: el `current_tenant_id` apunta por FK a un tenant existente; el caso
"id inexistente" no es representable bajo integridad referencial, por lo que TEN-05 cubre el
escenario de membresía `pending` (no activa) que también fuerza el deny.

#### Test Totals After FASE 29 U2

| Layer | After U1 | After U2 | Delta |
|---|---|---|---|
| Backend (Pest) | 2392 | 2452 | +60 |
| Frontend (Vitest) | 544 | 544 | 0 |
| Total | 2936 | 2996 | +60 |

- PHPStan: 0 errores. Pint: PASS. vue-tsc: PASS. Build: PASS. npm audit: 0 vulnerabilidades.
- composer audit: 0 advisories; 1 paquete abandonado pre-existente (`nunomaduro/larastan` → `larastan/larastan`), fuera de alcance de U2.

### FASE 29 U3 — Billing / Concurrency / PostgreSQL gaps (PARCIAL)

Grupos nuevos en U3 (todos verdes):

| Grupo | Archivo | Tests | Cubre |
|---|---|---|---|
| Leads dedup race | `tests/Postgres/Lead/LeadDedupConcurrencyTest.php` | F29-U3-LEAD-01..04 (PG) | Race check-then-insert crea duplicados; no hay UNIQUE en `leads`; mismo phone cross-tenant permitido |
| Analytics DST/timezone | `tests/Postgres/Analytics/AnalyticsAggregationPostgresTest.php` | F29-U3-DST-01..02 (PG) | Estabilidad UTC spring-forward/fall-back; timezone inválido → fallback UTC |
| AI retry/timeout | `tests/Unit/AI/OpenAIProviderTest.php` | F29-U3-AI-01..03 | `retry($times)` = intentos TOTALES; recuperación tras ConnectionException; maxRetries=0 |
| Lock context | `tests/Unit/Flows/ConversationLockContextTest.php` | F29-U3-LOCK-02..07 | Reentrancia, independencia conversación/tenant, `refreshHeld` tras release, leave no-op, no-BaseLock |
| Sentry scope | `tests/Feature/Security/SentryScopeMiddlewareTest.php` | F29-U3-SENTRY-01..04 | Tags request_id + tenant_id; ausencia si contexto vacío; aislamiento entre peticiones |
| Flow webhook gaps | `tests/Feature/Flows/FlowWebhookCoverageGapsTest.php` | F29-U3-FLOWWH-01..02 | trigger id no-UUID → 401; trigger activo no-webhook → 401 |

**Bloqueador PG reparado**: `2026_08_25_100001_create_usage_reservations_table.php` ponía el
`ALTER TABLE ... CHECK` dentro de `Schema::create` → la migración era no ejecutable y rompía TODA la
suite `phpunit.pgsql.xml`. Movido a post-create (patrón de `create_leads_table`). Suite PG pasó de
"100% bloqueada" a 162 verdes.

**BUG-ANALYTICS-DST (CORREGIDO)**: `AggregationService` emitía el window en wall-clock local
(`toDateTimeString()`) pese a ADR-078 (UTC). Confirmado empíricamente en DST y corregido:
`$start->copy()->utc()->toDateTimeString()` / `$end->copy()->utc()->toDateTimeString()`. El test
`F29-U3-DST-01` (tenant no-UTC) falla con el bug y pasa con el fix.

**Fallos PG pre-existentes fuera de alcance U3** (21-22, enmascarados antes por el bloqueador de
`usage_reservations`): `KnowledgeBase` (`filename` vs `original_filename`), `FaqPostgresTest` (FK),
`EmbeddingMaterializationPostgresTest`, `KnowledgeSearchPostgresTest`, `AnalyticsPostgresTest`
(up/down). NO tocados en U3 (dominios KnowledgeBase/FAQ).

**Totales U3**: backend no-PG 2467 passed / 15 skipped / 0 failed. PG 162 passed + pre-existentes.

### FASE 29 U4 — Jobs / Webhooks (COMPLETADA, 1 bug P1 detectado)

Grupos nuevos (13 + 1 reproducer skip):

| Grupo | Archivo | Tests | Cubre |
|---|---|---|---|
| Inbound guards | `tests/Feature/Jobs/ProcessIncomingWhatsAppMessageTest.php` | F29-U4-IN-* | Evento inexistente/ya-procesado/tipo-status → no-op; **aislamiento multi-tenant**; `invalid_payload` |
| Status guards | `tests/Feature/Jobs/ProcessWhatsAppStatusUpdateGuardTest.php` | F29-U4-STAT-* | Inexistente/ya-procesado → no-op; **aislamiento multi-tenant**; data ausente → `processed` |
| Webhook service | `tests/Feature/WhatsApp/WhatsAppWebhookServiceTest.php` | F29-U4-WS-* | `reprocessEvent()` sweeper; `handle()` robusto vs JSON malformado |

**BUG-WEBHOOK-FOREACH (P1, RESUELTO en U4-HOTFIX)**: en `WhatsAppWebhookService::handle()` los
`foreach` sobre colecciones externas (`$payload['entry']`, `$entry['changes']`, `$value['messages']`,
`$value['statuses']`) lanzaban `TypeError` con JSON válido pero shape malformado → HTTP 500 en el
webhook público. Fix mínimo: guardar `is_array(...)` antes de cada `foreach`; malformado → no-op.
Regresión: reproducer `U4-WS-INGEST-BUG-01` (verde) + matriz `U4-WS-SHAPE-01..11`.

**Totales U4-HOTFIX**: backend no-PG 2492 passed / 15 skipped / 0 failed (+12, sin skips en webhook).

### FASE 29 U5-HOTFIX — Inbox permission guard + PCOV

- **FRONTEND-INBOX-PERMISSION-LOAD (P2, RESUELTO)**: `Conversations/Index.vue` ejecutaba
  `loadConversations()` al montar aunque `canView` fuera falso, generando una petición innecesaria.
  El backend siguió siendo la autoridad y no hubo bypass de autorización. El guard de montaje ahora
  retorna antes de cargar conversaciones o miembros cuando no existe `conversations.view`.
- Regresión `F29-U5-INBOX-01..05`: **5 passed**; cubre ausencia de GET no autorizado, estado limitado,
  carga autorizada y relación `canSeeUsers`.
- **PCOV**: `docker-php-ext-enable pcov` generaba la extensión y el stage coverage la sobrescribía con
  sólo `pcov.enabled=1`. El stage `coverage` ahora conserva `extension=pcov.so` + `pcov.enabled=1`;
  el stage `runtime` no instala PCOV.
- Comando de cobertura: `docker compose -f docker-compose.yml -f docker-compose.coverage.yml run --rm
  coverage php -d memory_limit=512M vendor/bin/pest --coverage`. No se versionan reportes generados.
- U5-HOTFIX queda listo para reanudar; FASE 29 continúa **EN PROGRESO**.

### FASE 29 U5-PG-H1 — KnowledgeSearchService PostgreSQL hydration (BUG P1 RESUELTO)

**BUG-KNOWLEDGE-PG-HYDRATION (P1, RESUELTO)**: `KnowledgeSearchService::search()` ejecuta
`DB::select()` (raw query pgvector). En PostgreSQL (`pdo_pgsql`), `DB::select()` devuelve filas como
`stdClass`, mientras que `applyThreshold()` y `mapToRetrievedChunks()` esperan arrays
(`$row['similarity']`, `$row['chunk_id']`, ...). Resultado: `TypeError` ("must be of type array,
stdClass given") en toda búsqueda RAG con chunks coincidentes sobre PostgreSQL → fallo de
filtering/mapping en 6 tests PG deterministas.

- **Root cause**: contrato interno de filas asumía arrays pero el driver PG devuelve `stdClass`.
  SQLite no ejercita esta ruta (devuelve arrays y el servicio short-circuits en no-pgsql).
- **Fix (mínimo, SOLO representación interna)**: `executeCosineSearch()` ahora normaliza cada fila a
  array asociativo vía `normalizeSearchRows()` (nuevo método privado), convirtiendo
  `chunk_index` a `int` y `similarity` a `float` para evitar comparaciones implícitas/lexicográficas.
  `applyThreshold()` y `mapToRetrievedChunks()` quedan invariantes (ya consumían arrays). Sin
  cambios de SQL, bindings, tenant filter, similarity math, ordering, top-k, threshold ni API pública.
- **No se ampliaron dominios**: la revisión de `AggregationService` (Analytics) confirmó que consume
  `DB::select()` con acceso a propiedades (`$row->conversation_id`), correcto para `stdClass`; sin
  bug similar. Fuera de alcance H1 (H2/H3/H4 pendientes).
- **Regresión PG**: las 6 fallas de hidratación pasan (cosine ordering, top-k, threshold, tie
  ordering, identical cross-tenant isolation, tenant context switching). Quedan 2 fallos del mismo
  archivo, ambos cluster H4 (P3, test-only, NO tocados): assertion de nombre de índice HNSW y
  expectation de vector inválido parametrizado.
- **Regresión no-PG**: 124 tests Knowledge/RAG/AI pasan (304 assertions, 0 failed).
- PHPStan: 0 errores. Pint: PASS.


### FASE 29 U5-PG-H2 � Analytics + FAQ test harness fixes (TEST-ONLY)

Harness fixes for 7 deterministic PostgreSQL failures. **Production code y migrations intactas.**

- **Analytics up/down/up (AN-PG-12)**: el test usaba `migrate:rollback --step=2`, que hace rollback
  de las �ltimas migraciones GLOBALES (orden-dependent), NO de la pareja analytics. Fix test-only:
  targetear las 2 migraciones analytics v�a `--path` (`2026_08_20_010000_create_analytics_daily_table` y
  `2026_08_20_010100_create_conversation_metrics_table`). Verifica UP ? objetos existen, DOWN ? se
  eliminan, UP ? se recrean e inserta OK. Sin reliance en el orden global de migraciones.
- **FAQ fixtures (FAQ-PG-03..06, 10)**: `setUp()` creaba el tenant ANTES de que el test ejecutara
  `migrate:fresh`, que borra la tabla `tenants` ? el tenant quedaba obsoleto ? FK `23503`. Fix
  test-only: recrear el fixture de tenant DESPU�S del reset de esquema (`createTestFaqTenant()`).
  Para FAQ-PG-06 (soft-delete recreate), adem�s el count final filtra `deleted_at IS NULL` (el
  contract del partial unique index excluye filas soft-deleted; la recreaci�n NO lanza violaci�n).
- **FAQ partial index predicate (FAQ-PG-08)**: la assertion buscaba el string exacto
  `WHERE deleted_at IS NULL`, pero PostgreSQL deparse como `WHERE (deleted_at IS NULL)`. Fix test-only:
  assertion SEM�NTICA normalizando espacios/parentesis insignificantes y comprobando `deleted_at IS NULL`.
- **FAQ up/down/up (FAQ-PG-10)**: targetear la migraci�n de faqs v�a `--path` (patr�n ya usado por
  `EmbeddingNullableMigrationTest`) y recrear el tenant tras el ciclo.
- **Regresi�n PG**: 7/7 H2 tests PASS. Suite PG completa: 175 passed / 9 failed (solo clusters
  H3=7 + H4=2 pendientes). Sin dependencia de orden (FAQ?Analytics y Analytics?FAQ id�nticos).
- PHPStan: 0 errores. Pint: PASS.

### FASE 29 U5-PG-H3 - Knowledge embedding + nullable fixture contracts (TEST-ONLY)

Contract alignment for 7 deterministic PostgreSQL failures. **Production code, migrations y DDL intactos.**

- **Wrong dimension (EMB-PG-02)**: fail-closed productivo real: `VectorSerializer::validate()` lanza
  `EmbeddingDimensionMismatchException` dentro de la DB transaction. Fix test-only: el test ahora verifica
  que la excepcion PROPAGA (no se silencia) y que no hay persistencia parcial (2 chunks NULL, 0 non-null,
  sin filas extra). Sin cambios de servicio.
- **Transaction rollback (EMB-PG-05)**: el fallo del provider se PROPAGA (`processBatch` libera reserva y
  re-lanza; no se traga). Fix test-only: assert excepcion propagada + rollback de transaction (3 chunks
  NULL, 0 non-null, sin estado success falso).
- **Deleted document (EMB-PG-10)**: boundary soportado = guard `isDocumentDeleted()` (tambien en el job
  antes de llamar al servicio). El servicio type-hints `KnowledgeDocument` NO-nullable. Fix test-only: se
  obtiene el documento con `withTrashed()` (instancia valida) y se verifica que NO se materializa ni se
  llama al provider. Ya NO se llama `materialize(null)`.
- **Stale column (EMB-NULL-PG-02/03/06/07)**: fixture usaba `filename`; schema real es `original_filename`
  (2026_08_18_020100_create_knowledge_documents_table.php). Fix test-only: `filename` -> `original_filename`
  + `storage_disk` explicito. Sin columna de compatibilidad.
- **Rollback with NULL (EMB-NULL-PG-07)**: confirmado el contract de la migracion nullable: `down()` LANZA
  `RuntimeException('Cannot revert embedding to NOT NULL...')` si existen NULLs. Asertado via
  `->throws(RuntimeException::class, 'Cannot revert embedding to NOT NULL')`.
- **Regresion PG**: 7/7 H3 PASS (17 tests embedding+nullable, 44 assertions, 0 failed). KnowledgeBase PG:
  **43 passed / 2 failed** (solo H4=2: HNSW index assertion + parameterized invalid-vector, NO tocados).
  Sin dependencia de orden (nullable<->materialization idAgnosticos).
- **Regresion no-PG**: 331 tests Knowledge/RAG/Embedding/AI/document processing (910 assertions, 13 skip
  por columna embedding en SQLite, 0 failed). PHPStan 0, Pint PASS.

### FASE 29 U5-PG-H4 - Pgvector assertion alignment (TEST-ONLY)

Ultima unidad U5-PG. Production intacto; sin cambios de migraciones, indices, schema ni SQL productivo.
No se renombra ni recrea ningun indice: el indice real knowledge_chunks_embedding_idx (hnsw,
vector_cosine_ops, sobre embedding) se preserva.

- **KnowledgeSearchPostgresTest::test_hnsw_index_exists_and_cosine_query_compatible**:
  - Antes: indexname LIKE '%hnsw%' (naming fragil); el indice real se llama knowledge_chunks_embedding_idx
    y no contiene "hnsw", por lo que ssertNotEmpty fallaba.
  - Ahora: assertion SEMANTICA via catalogo PostgreSQL (pg_index/pg_class/pg_am/pg_attribute/
    pg_opclass): indice existe sobre columna embedding, access method = hnsw, operator class =
    ector_cosine_ops, is_valid = true. Independiente del nombre del indice. Se conserva la assertion
    de compatibilidad coseno (<=> con ?::vector).
- **KnowledgeSearchPostgresTest::test_vector_passed_via_parameterized_binding**:
  - Antes: esperaba ssertEmpty() tras lanzar la query con el string malicioso; el DB::select
    lanzaba QueryException 22P02 sin capturar -> fallo.
  - Ahora: aserta que el string invalido 1.0,2.0,3.0]::vector; DROP TABLE knowledge_chunks; -- se enlaza
    como parametro (?::vector), PostgreSQL lo rechaza por tipo con SQLSTATE **22P02**, y no se interpola
    en SQL (sin injection; el DROP nunca se ejecuta). Post-condiciones de seguridad: tabla knowledge_chunks
    existe, mismo conteo de filas, sin mutacion de esquema. Control: vector VALIDO por la misma ruta de
    binding se ejecuta con exito (rechazo = validacion de tipo, no query construction rota).
  - Uso de SAVEPOINT/ROLLBACK TO para aislar el error esperado dentro de la transaccion del test
    (RefreshDatabase) y permitir las assertions posteriores sin abortar.
- **Regresion PG**: KnowledgeSearchPostgresTest 14/14 PASS (32 assertions). KnowledgeBase PG: **45/45 PASS
  (96 assertions)**. Suite PostgreSQL COMPLETA: **184 passed, 0 failed, 489 assertions, 0 skipped**.
  H4 repetido 2/2 PASS (sin flakiness).
- **Regresion no-PG**: 338 tests (926 assertions, 13 skip por columna embedding en SQLite, 0 failed).
  PHPStan 0, Pint PASS.
- **Seguridad**: SQL injection demostrada NO; input invalido enlazado como parametro SI; rechazado por PG
  (22P02) SI; objetos de BD preservados SI.
- Commit: `test(pgvector): align assertions with postgres behavior (local, NO PUSH).

### FASE 29 U5 - Final Closure (COMPLETADA)

Cierre global de FASE 29. **FASE 29 = COMPLETADA.**

- **Backend no-PG**: 2492 passed / 15 skipped / 0 failed (7114 assertions, 874.78s). Igual al baseline
  validado U4; sin regresiones.
- **PostgreSQL suite (puerta obligatoria)**: 184 passed / 0 failed / 0 skipped (489 assertions, 566.69s).
  SQLite no valida pgvector, locking, advisory locks, migraciones/indices PG, concurrencia ni hydration;
  por eso la suite PG es MANDATORIA antes de merge.
- **Frontend**: 36 files / 555 passed / 0 failed (8.46s). Incluye Login.test.ts (4) y Dashboard.test.ts (2)
  de U5, revisados y validos (render, submit, errores, estado vacio; sin E2E/red/snapshot/secrets).
- **Coverage backend**: 85.0% (baseline validado, PCOV en stage Docker `coverage`; tooling intacto;
  runner con `php -d memory_limit=512M`).
- **Coverage frontend**: Statements 49.51 / Branches 85.30 / Functions 72.86 / Lines 49.51 (sin delta por
  incluir los tests de pagina U5).
- **Calidad**: PHPStan 0 errores (proyecto, level 6 en phpstan.neon); Pint PASS (825 files); vue-tsc PASS;
  vite build PASS; npm audit 0 vulns; composer audit 0 advisories (1 abandonado no-seguridad:
  `nunomaduro/larastan`); config cache PASS.
- **Skips**: non-PG 15 legitimos (13 columna embedding en SQLite + partial unique index + LeadSecurity
  is_active; cubiertos por suite PG). PG 0 skips. unknown 0, flaky critical 0.
- **Bugs productivos resueltos en FASE29**: Analytics DST/window (f31d606), WhatsApp malformed collection
  (1977f09), Inbox unauthorized load (3199951), KnowledgeSearch PG hydration (6f01c2a).
- **Correcciones PG (test-only)**: H2 (771bf48), H3 (0914991), H4 (4a40c9f).
- **P0=0, P1=0, P2 bloqueante=0**.
- Migracion pendiente de produccion `2026_08_25_100001_create_usage_reservations_table` NO ejecutada en
  produccion (solo registrada en suite PG via RefreshDatabase). H1-H4 sin nuevas migraciones.
- **FASE30 (E2E Playwright)** NO INICIADA: login, inbox, handoff, flow builder, billing, knowledge upload.

### FASE 30 U1 — Playwright Infrastructure + Auth + Multi-Tenancy Base

Primera unidad de E2E de **FASE 30 (Playwright)**. Establece la infraestructura E2E (config, guard de
entorno, DB de aislamiento, seeds deterministas, provider fakes) y cubre los flujos de autenticación y el
aislamiento multi-tenant básico (P0).

#### Herramienta y alcance (MVP)

| Aspecto | Decisión |
|---|---|
| Runner | `@playwright/test ^1.62.1` (solo devDependency) |
| Navegador | Chromium únicamente (proyecto `chromium`; sin firefox/webkit) |
| Scripts | `test:e2e`, `test:e2e:headed`, `test:e2e:ui`, `test:e2e:report` |
| Concurrencia | `workers=1` (compartir DB/Redis), `retries=0` (no ocultar flakiness) |
| Base | `baseURL` 8082 |
| Timeouts | `navigationTimeout` 60s (bottleneck es navegación bajo server `php -S` lento); `expect.timeout` 15s |

**Carpeta**: `tests/e2e/` (separada de unit/feature). Auth por `storageState` (login una sola vez en
`global-setup.ts` y reutilizado entre specs — política de seguridad: nunca se versiona `tests/e2e/.auth/`,
ignored).

#### Infraestructura

- **`playwright.config.ts`**: projects, webServer (compose E2E), config de timeouts, reporters, output dirs.
- **`global-setup.ts`**: arranca entorno, hace login único y guarda `storageState`, sondea salud (`pollHealth`).
- **`helpers/auth.ts` / `helpers/constants.ts`**: logins por rol, `apiGet`/`apiPost` (header `Origin` correcto
  para CSRF), constantes deterministas de tenants/contactos/conversaciones/usuarios.
- **`app/Infrastructure/Testing/E2EEnvironmentGuard.php`**: **guard de seguridad**. E2E SOLO corre si
  `APP_ENV=e2e` (literal) Y la DB termina en `_e2e_test` Y usa índice Redis dedicado (db 15) + prefijo.
  Aborta (`e2e:setup`, restore database) ante condiciones no seguras. Testeado por
  `tests/Unit/Infrastructure/E2EEnvironmentGuardTest.php` (E2E-ENV-01..03, cubre las condiciones negativas).
- **`app/Providers/E2EOnlyServiceProvider.php`**: re-bindea fakes (`FakeAIProvider`, `FakeEmbeddingProvider`,
  `FakeCapacityGuard`, `FakeUsageGuard`, `FakeFaqMatcherService`, `FakeKnowledgeSearchService`) SOLO si
  `APP_ENV=e2e`. WhatsApp/Stripe/Sentry reales pero latentes (no invocados en U1). DSNs vacíos en
  `.env.e2e.example`; **ningún proveedor externo real** en E2E.
- **`app/Console/Commands/SetupE2EEnvironment.php`**: configura el entorno E2E (migrate:fresh + seed) con
  el guard. **`database/seeders/E2ETenantSeeder.php`**: seeds deterministas con UUIDs fijos (tenant A/B,
  contactos, conversación, usuarios owner/admin/agent) para assert estables.

#### Aislamiento E2E (DB / Redis / storage)

- **DB**: `whatsapp_saas_e2e_test` (dedicada; no toca la suite unit/feature ni PG canónica).
- **Redis**: índice dedicado **db 15** + prefijo; la DB dev (db 0) y la de tests PG (db 14) quedan
  intactas (NO `FLUSHALL`).
- **Storage**: mount `./storage/e2e-app:/var/www/html/storage/app`; `storage/e2e-app/` ignored.

#### Autenticación (specs)

- **`login.spec.ts`**: login válido owner/admin/agent (storageState), logout, "credenciales inválidas"
  (timeout targeted 30s en el mensaje de error: espera un POST HTTP real de ~10–20s bajo carga del server lento).
- **`logout.spec.ts`**: logout desde sesión activa (test 90s + `toHaveURL` 45s justificados por el server lento).
- No se usa `waitForTimeout` en ningún spec.

#### Multi-tenancy P0 (specs)

- Tenant A ve **su** conversación (200) y **no** ve/reacciona sobre la conversación de Tenant B (404).
- Switch de tenant inválido → 404. Sin fuga de datos entre tenants (Tenant A nunca lee datos de Tenant B).

#### Timeouts (justificados, no blanket)

`navigationTimeout` 60s porque el server `php artisan serve` (SAPI CLI, opcache no compartido entre
workers) es el bottleneck determinista (login completo warm ~22s; fases HTTP POST/redirect de ~8–10s).
`expect.timeout` se mantiene en 15s; el único timeout targeted extra es el mensaje de error de login inválido.
Esto NO es un wait-condition flaky ni carga de assets (assets ~0.1–0.5ms).

#### Resultados U1

- **E2E Run #1**: 13/13 PASS. **Run #2**: 13/13 PASS. **Run #3 (auth)**: 9/9 PASS. **logout** 3/3 (repetido).
- **Multi-tenancy P0**: own 200, foreign 404, switch 404, leakage NO.
- **Guard unit tests**: E2E-ENV-01/01b/02/02b/03 (condiciones negativas ambas).
- **Regresiones FASE30 U1**:
  - Backend no-PG (SQLite): **2499 passed / 15 skipped / 0 failed** (~15.3 min).
  - PostgreSQL canónica (`phpunit.pgsql.xml`, `tests/Postgres`): **184 passed / 0 failed** (~14.6 min).
  - Frontend Vitest: **555 passed / 0 failed**; typecheck PASS; build PASS.
  - PHPStan `[OK] No errors`; Pint PASS (2 files: `SetupE2EEnvironment.php`, `E2EEnvironmentGuardTest.php`).
  - `npm audit` 0 vulns; `composer audit` 0 advisories (1 abandonado no-seguridad conocido: `larastan`).
  - Docker compose E2E config PASS.
- **CONV-4/CONV-10 clasificados TEST ASSERTION PORTABILITY GAP, P3 (sin fix)**: ambos en suite SQLite
  (`tests/Feature/Conversations`), no en la PG canónica. Producción NO afectada.
- **Login timing (root-cause de 29–31s)**: server `php artisan serve` lentísimo (warm: `/up`=3.5–6.3s,
  `/login`=2.3s, login completo=22s; POST login=9.98s, redirect→/dashboard=7.53s). PHP built-in server con
  `opcache.enable_cli=1` pero la OPcache NO persiste entre workers (`cached_scripts=1, hits=0`): cada request
  recompila/bootstrap Laravel (~2–10s). Clasificación: **STACK STARTUP / SERVER PERFORMANCE / FIRST REQUEST
  WARMUP**, no wait-condition flaky.
- **Hotfix productivo previo (commit separado)**: `d85751ad53b10eb2da64efc8b84ff5596b5d2195`
  `fix(inbox): lastMessage() with PK uuid in PostgreSQL (max uuid)` — solo `Conversation.php` +
  `ConversationTest.php`. Aislado de U1 (no amend/push).
