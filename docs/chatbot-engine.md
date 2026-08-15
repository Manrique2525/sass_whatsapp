# Chatbot Engine

## 1. Objetivo

Motor genérico de automatizaciones interpretado desde datos (flujos), **no código por negocio**.
Cada flujo es una definición persistida (`flows`, `flow_nodes`, `flow_connections`) y el motor
la ejecuta de forma determinista, reanudable e idempotente.

## 2. Modelo de ejecución

Entidades:
- `Flow`: versión publicada de un chatbot. Estados: `draft`, `published`, `inactive`.
- `FlowNode`: nodo. `type` + `config` (JSON) + posición (editor). Un `is_start` por flujo.
- `FlowConnection`: arista dirigida `source → target` con `label` (resultado de rama).
- `FlowExecution`: instancia en curso para una conversación (estado `running/waiting/completed/
  failed/handed_off`), apunta a `current_node_id` y lleva `variables`.
- `FlowExecutionLog`: traza de cada paso (auditoría y debugging).

## 3. Tipos de nodo

| Tipo | Config | Comportamiento |
|---|---|---|
| `message` | texto/variables/attachments | Envía mensaje de texto |
| `buttons` | lista de botones (texto + payload) | Envía interactiva; espera click (waiting) |
| `question` | pregunta + variable destino + prompt IA opcional | Envía pregunta, guarda la respuesta en `variables[field]` |
| `condition` | reglas (campo operador valor) | Rama: `true`/`false` según reglas evaluadas sobre variables |
| `delay` | segundos | Espera programada (job encolado con `delay`) |
| `tag` | tag(s) | Aplica etiquetas al contacto/conversación |
| `webhook` | URL + método + headers + payload | POST externo con contexto (idempotencia: `execution_id`) |
| `ai` | prompt + system + kb bool | Genera respuesta con IA (con/sin contexto RAG), aplica límites |
| `human` | mensaje de aviso | Pausa bot, crea asignación, notifica agentes |
| `end` | — | Finaliza ejecución (estado `completed`) |

## 4. Algoritmo

```
handleMessage(conversation, inboundMessage):
  execution = conversation.activeExecution()
  if none:
    flow = pickFlow(trigger)          # trigger por keyword / inicio / tag / horario
    if none → return (no action)
    execution = createExecution(flow)
  repeat (guard: max N pasos, timeout total):
    node = execution.currentNode()
    step(node, execution)             # cada paso se loguea
    if node.type in [question, buttons, ai, human]:
      execution.status = waiting      # espera siguiente mensaje del cliente
      break
    next = node.type == condition
      ? evaluateCondition(...)        # elige rama por label
      : outgoingConnections(node)[0]  # arista única
    if next → advance; continue
  execution.save()
```

- **Waiting**: cuando el siguiente input del cliente llega, `handleMessage` reanuda el nodo
  `question`/`buttons` (valida la opción, asigna variable) y continúa.
- **Acoso/loops**: límite de nodos visitados por ejecución (configurable) → fuerza `end`
  con log de error.
- **Concurrencia**: `handleMessage` se invoca **bajo un lock de Redis por conversación**
  (`lock:tenant:{id}:flow:{conversation_id}`) y solo hay una ejecución activa por conversación
  (UNIQUE parcial en `flow_executions`). Dos mensajes simultáneos del mismo cliente no duplican
  ejecución ni respuestas. Ver `whatsapp.md` §7.
- **Retries**: ante fallo de provider/IA, reintento con backoff (max N) antes de marcar
  `failed`; la conversación pasa a `pending` y se notifica a agentes.

## 5. Variables

- Fuentes: `{{contact.name}}`, `{{contact.phone}}`, `{{business.name}}`,
  `{{conversation.id}}`, `{{custom.<field>}}` (capturadas por nodos `question`).
- Sustitución en textos y payloads. `custom.*` se lee SOLO de `flow_executions.variables`
  (única fuente, decisión FASE 11 + ADR-046; no hay espejo hacia `conversation.context`).
- Sanitización: output escapado; prohibido exponer tokens/secrets en textos.
- DSL (FASE 13, UNIDAD 2): `{{variable|default:'valor'}}` usa `valor` cuando la variable no
  existe, es `null` o está vacía (`''` o `[]`); comillas escapables con `\'`; caracteres de
  control del resultado eliminados; sin `eval`.
- Default runtime (FASE 13, UNIDAD 6, ADR-046): una respuesta **vacía** a una pregunta con
  `question.config.default` usable persiste el default **coerceado al tipo declarado** en
  `flow_executions.variables.custom.<field>`. Una respuesta no vacía siempre gana (aunque falle
  la coerción se conserva la cadena en bruto). `default` ausente/`null`/`''` = sin valor por
  defecto (comportamiento previo intacto).

## 6. Triggers

| Trigger | Disparo |
|---|---|
| `keyword` | Primer mensaje del cliente contiene palabra/patrón |
| `new_message` | Cualquier mensaje entrante |
| `start` | Primer mensaje de una conversación nueva |
| `tag` | Conversación etiquetada |
| `schedule` | Horario (cron) |
| `webhook` | Evento externo entrante |

Precedencia: triggers específicos (`keyword`) antes que genéricos (`new_message`/`start`).

## 7. Handoff a humano

- Nodo `human` o comando del cliente ("hablar con alguien"):
  1. Marca `execution.handed_off`, `conversation.bot_paused = true`.
  2. Crea `conversation_assignments` (agente disponible / cola).
  3. Notifica a agentes (Reverb + Email) y cambia estado `open`.
  4. El bot NO responde mientras `bot_paused`.
- Acción `resume-bot` (agente): limpia pause, archiva/cierra, borra ejecución activa.

## 8. Validación de flujo (publicar)

`FlowValidator` ejecuta en FASE 12 + backend:
- Existe un único nodo `start`.
- Todos los nodos alcanzables desde `start` (no huérfanos).
- Ningún nodo sin conexión saliente excepto `end`.
- Sin ciclos infinitos entre nodos no-waiting (detección de ciclos con BFS sobre el grafo,
  excluyendo caminos que pasan por nodos `waiting`/`delay` con fin garantizado).
- Nodos con `config` válida (contenido no vacío, prompt no vacío, condición bien formada).
- `end` es alcanzable.
- Flujo inválido → `FLOW_INVALID`, no se publica.

## 9. Tests (FASE 11)

Unitarios (motor puro, sin HTTP):
- secuencia message → message → end.
- condition con variables → rama correcta.
- question captura respuesta → continua.
- delay agenda el siguiente paso.
- human pausa bot y notifica.
- límite de pasos/loop.
- variables se sustituyen.

Integración (con queue falsa y DB):
- flujo completo desde webhook → ejecución → mensajes outbound.
- reanudación tras `waiting`.
- duplicado de webhook no duplica ejecución.

## 10. FASE 11 — Estado de implementación (lo que existe en el código hoy)

Esta sección describe la implementación REAL entregada en FASE 11 (lo anterior es el diseño
objetivo completo; las diferencias marcadas abajo). Referencias: ADR-034..039.

### 10.1 Tablas y modelo (ADR-034)

- `chatbots` (soft delete), `flows` (la fila ES la versión; no hay `flow_versions`), `flow_nodes`,
  `flow_connections`, `triggers`, `flow_executions` (UNIQUE parcial activa por conversación),
  `flow_execution_logs`. Migraciones `2026_08_17_0000xx_*`.
- El nodo de inicio es un nodo real con `is_start=true`; **no existe** un tipo `start`
  (el §8 de arriba habla de "nodo start"; léase como "nodo con `is_start`").

### 10.2 Ejecución (ADR-035/037)

- `FlowNodeType`: `message, buttons, question, condition, delay, tag, webhook, human, end`
  implementados como ejecutores en `app/Application/Flows/Services/Executors/`; `ai` queda
  **bloqueado en el validador** (FASE 16) — nunca habrá un ejecutor vacío.
- `FlowEngine::handleMessage` / `continueExecution` bajo lock Redis por conversación,
  `last_inbound_message_id` como barrera de idempotencia, `flow_execution_logs` por paso,
  guard anti-loop. `pause/resume/cancel` sobre ejecuciones activas (409 sobre terminales).
- Variables: `VariableResolver` (contact/custom/conversation/node) + `ConditionEvaluator`
  (ramas de `condition`) + `WebhookUrlGuard` (anti-SSRF). `{{custom.*}}` se lee de
  `execution.variables` (respuestas de `question`); no hay espejo hacia `conversation.context`
  (decisión FASE 11 — ver FLOW-2 en tests). FASE 13: `{{contact.<campo>}}` es alias de
  `contact.metadata[<campo>]` (ADR-045); la traversión `contact.metadata.<clave>` sigue bloqueada.
  FASE 13 (UNIDAD 6, ADR-046): la captura de `question` aplica `question.config.default`
  coerceado al tipo declarado cuando la respuesta es vacía (sin default → comportamiento previo;
  una respuesta no vacía siempre gana).
- `FlowValidator` (FASE 13, ADR-045): límites de longitud (textos ≤ 4096, campo de condition
  ≤ 128, URL webhook ≤ 2048), `question` valida `type` (`VariableType`) y `default` (coercible al
  tipo declarado o `string`), escaneo de referencias con error duro solo para segmentos
  peligrosos (`__proto__`/`constructor`/`prototype`), campo de condition solo con namespaces
  `contact/business/conversation/custom` y segmentos seguros.
- Nodo `webhook` (FASE 13): esquema `http(s)` obligatorio, host literal (variables jamás
  bypasean SSRF), sin credenciales en el URL; los logs y la auditoría usan
  `WebhookUrlGuard::sanitizeForLog()` (sin userinfo/query/fragment) → nunca aparecen secrets.

### 10.3 Estados del flujo (ADR-036)

- `draft → published → inactive` con `canTransitionTo`; borrador atómico vía
  `PUT /flows/{flow}/draft` (transacción nodes+connections); publicar valida el grafo
  (`FlowValidator`); `GET /flows/{flow}/validate` → `{valid, errors}` sin mutar.
- Un flujo publicado no se edita/elimina (409 `FLOW_PUBLISHED`); un chatbot con flujos
  publicados no se elimina (409 `CHATBOT_HAS_PUBLISHED_FLOWS`); no dos flujos publicados con el
  mismo trigger genérico (409 `FLOW_ALREADY_PUBLISHED`).

### 10.4 Triggers (ADR-038)

- FASE 11: `keyword`, `new_message`, `start` (disparo por mensaje entrante vía
  `TriggerMatcher`, precedencia específico→genérico). `tag/schedule/webhook` registrados en el
  enum pero sin matcher hasta FASE 14.

### 10.5 API y permisos (ADR-039)

- `flows.view` (todos los roles) y `flows.manage` (owner/admin). REST bajo `middleware('tenant')`:
  chatbots CRUD, flows (show/update/delete/draft/validate/publish/deactivate), triggers CRUD,
  flow-executions (index/show/pause/resume/cancel). Errores estándar `{message, code, errors}`.
- Frontend read-only: `Pages/Settings/Flows.vue` + `features/flows/{flowTypes,flowUtils}.ts`.
  El editor visual (Vue Flow) es FASE 12.

### 10.6 Tests entregados

- `tests/Feature/Flows/FlowEngineTest.php` (FLOW-1..15): motor end-to-end sobre cola sync.
- `tests/Feature/Flows/FlowApiTest.php` (FLOW-16..28): API, matriz de permisos, aislamiento A/B,
  auditoría.
- `tests/Feature/Flows/FlowsPermissionTest.php` (FLOW-20/21): permisos con seeder sincronizado.
- `resources/js/features/flows/flowUtils.test.ts`: utils del frontend (Vitest).

## 11. Editor visual (FASE 12, ADR-040..044)

- **Arquitectura**: `useFlowEditor` (estado + mutaciones + `FlowEditorController`), historial
  propio (`useEditorHistory`, 50 snapshots clonados), atajos de teclado y canvas *one-way*
  `FlowEditor.vue` con `@vue-flow/core` v1.48. Los 10 tipos de nodo son SFCs registradas en
  `features/flows/components/nodes/index.ts`; la arista propia `FlowEdge.vue` muestra la etiqueta
  de rama (`true`/`false`) con `MarkerType.ArrowClosed`.
- **Contrato de grafo**: ids UUID del cliente, posiciones enteras, edge ids deterministas
  `e-{source}-{target}-{label}`, ramas de condición como `sourceHandle`+`label`
  `CONDITION_TRUE`/`CONDITION_FALSE`. El payload del draft no lleva `tenant_id` (lo fuerza
  `BelongsToTenant`) y los secrets del nodo webhook quedan en el backend (solo `method`/`url`).
  FASE 13: el panel de `question` conserva y edita `type`/`default` (`QuestionNodeConfig`).
- **Concurrencia**: `PUT /flows/{flow}/draft` acepta `base_updated_at` (opcional); si la versión
  cambió → 409 `FLOW_CONFLICT`. El editor muestra el `ConflictDialog` (recargar / seguir
  editando / sobrescribir explícito) y jamás sobrescribe automáticamente. Migración
  `timestamp(6)` en `flows.created_at/updated_at`.
- **Validación en el editor**: `flowValidation.ts` valida config por tipo y el grafo local
  (espejo del `FlowValidator`: un solo inicio, sin loops, terminales sin salida, ramas de
  condición, `end` alcanzable). El publish re-valida en backend (`FLOW_INVALID`); los errores
  del servidor se mapean a nodos por nombre (`mapBackendErrors`) para el `ValidationPanel`.
- **Solo lectura**: el agente y los flujos publicados abren el editor en read-only; el
  composable ignora las mutaciones y la barra oculta Guardar/Publicar.
- **Selección en Vue Flow v1.48**: sin evento `selection-change`; `syncSelection()` lee los
  flags `selected` de `nodes-change`/`edges-change`. Los tipos `FlowEditorNode`/`FlowEditorEdge`
  son propios del editor (desacoplados de `GraphNode`/`GraphEdge`).
- **Tests**: backend FLOW-29..43 (secrets, lock, página, estados, aislamiento A/B, permisos,
  publish tras editar); frontend 46 Vitest nuevos (`flowAdapter`, `flowValidation`,
  `useEditorHistory`, `useFlowEditor`).
