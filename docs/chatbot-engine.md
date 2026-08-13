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
- Sustitución en textos y payloads. `conversation.context` (JSONB) persiste `custom.*`.
- Sanitización: output escapado; prohibido exponer tokens/secrets en textos.

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
