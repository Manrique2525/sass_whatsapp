# WhatsApp (Meta Cloud API)

Estado: **FASE 6 COMPLETADA** (provider, webhook, conexión y envío), **FASE 7 COMPLETADA**
(CRM de contactos: el find-or-create por teléfono ya está disponible para los jobs del webhook),
**FASE 9 COMPLETADA** (mensajes: persistencia inbound/outbound, status updates y outbox sweeper)
y **FASE 10 COMPLETADA** (REST de mensajes para el inbox + notificaciones Reverb por conversación).
Lo pendiente de diseño (media, plantillas, motor de flujos, rate limits, lock de conversación) se
detalla abajo con su fase de implementación.

## 1. Regla

Solo **Meta WhatsApp Cloud API** oficial. No se usan librerías no oficiales ni Web APIs
privadas de WhatsApp.

## 2. Jerarquía de Meta (imprescindible)

```
Meta Business Portfolio / Business Manager   (organización)
  └── WhatsApp Business Account (WABA)        → 1 por número conectado (tenant)
        └── Phone Number (con Phone Number ID) → el número del negocio
```

- **App**: UNA app de desarrollador (el SaaS). El webhook y el `App Secret` se registran UNA
  vez a nivel de app y son **compartidos por todos los tenants**. Cada WABA de cada tenant se
  suscribe al webhook de esa app.
- **WABA + token**: cada tenant conecta su propia WABA mediante su propio access token
  (token de usuario del sistema / token permanente scopeado a la app + WABA). Se guarda
  **cifrado** en `whatsapp_accounts.access_token`. Es el token que usa el envío de ese tenant.
- **Phone Number ID**: identifica el número del negocio. Aparece en el webhook como
  `entry[].changes[].value.metadata.phone_number_id`. Es la **clave de resolución tenant**.

## 3. Abstracción

```php
interface WhatsAppProviderInterface
{
    public function sendText(string $accessToken, string $phoneId, string $to, string $text, array $context = []): MessageSendResult;
    public function sendTemplate(string $accessToken, string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult;
    public function sendImage(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult;
    public function sendDocument(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult;
    public function sendInteractiveMessage(string $accessToken, string $phoneId, string $to, InteractiveMessage $message): MessageSendResult;
    public function markAsRead(string $accessToken, string $phoneId, string $messageId): void;
    public function getPhoneNumberInfo(string $accessToken, string $phoneId): PhoneNumberInfo;
    public function subscribeToWebhooks(string $accessToken, string $wabaId): bool;
    public function unsubscribeFromWebhooks(string $accessToken, string $wabaId): bool;
    public function validateWebhookSignature(string $signature, string $rawBody): bool;
    public function verifyWebhook(array $query): array; // verificación GET (challenge)
}
```

Implementación: `MetaWhatsAppProvider` (Laravel HTTP Client, base `https://graph.facebook.com/v26.0/`,
configurable vía `WHATSAPP_GRAPH_URL`/`WHATSAPP_GRAPH_VERSION`). U1 valida que la URL use HTTPS
y el host oficial `graph.facebook.com`, que la versión tenga formato `v<integer>.0`, y que el
timeout de conexión sea menor que el timeout total (`WHATSAPP_CONNECT_TIMEOUT`/`WHATSAPP_TIMEOUT`).
**El `access_token` se pasa en
CADA llamada**: es el token del WABA del tenant (cifrado en `whatsapp_accounts.access_token`,
ADR-029), nunca un token global de `.env`. El resultado normaliza `provider_message_id`, estado y
errores (`MessageSendResult`). Los errores de Meta se mapean a excepciones de dominio
(`WhatsAppAuthFailedException` 401/403, `WhatsAppPhoneNotFoundException` 404,
`WhatsAppMessageFailedException` 4xx/5xx/429) con `providerErrorCode` y `retryable`
(transitorios: timeout/5xx/429; permanentes: 4xx).

### Conexión de un número (FASE 6)

- `POST /api/v1/tenants/{tenant}/whatsapp/connect` (permiso `whatsapp.manage`, owner/admin):
  valida SIEMPRE las credenciales contra Meta (`getPhoneNumberInfo`) antes de persistir; guarda el
  token **cifrado**; crea/actualiza la cuenta y el `whatsapp_phone_numbers`; suscribe el WABA al
  webhook (best-effort). Error de Meta → 401 `WHATSAPP_AUTH_FAILED` / 404 `WHATSAPP_PHONE_NOT_FOUND`.
- `GET .../whatsapp` (permiso `whatsapp.view`, todos los roles): estado de la cuenta + números.
- `POST .../whatsapp/disconnect` (permiso `whatsapp.manage`): cancela la suscripción del WABA,
  anula el token (`access_token = null`) y marca `disconnected`; **el historial se conserva**.
- Sin cuenta conectada: 409 `WHATSAPP_NOT_CONNECTED`; sin permiso: 403 `PERMISSION_DENIED`;
  `{tenant}` distinto del activo: 404.

### Media (imagen/documento)

El envío de media requiere un paso previo: subir el archivo a
`POST /{phone_number_id}/media` para obtener `media_id`, y enviar con `{ "id": media_id }`.
El provider expone un paso interno `uploadMedia()`; en la UI los archivos se suben a S3 y el
worker hace upload a Meta (o usa `link` si la URL firmada es accesible por Meta).

## 4. Webhook

### 4.1 Verificación (GET)
```
GET /api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
```
- Si `hub.mode === 'subscribe'` y `hash_equals(verify_token, WHATSAPP_VERIFY_TOKEN)` →
  responder `hub.challenge`.
- Sino → 403. (`WHATSAPP_VERIFY_TOKEN` es global de la app.)
- `WHATSAPP_VERIFY_TOKEN` debe estar configurado y no vacío; `hub.mode`, `hub.verify_token` y
  `hub.challenge` deben ser valores escalares presentes. Nunca se acepta un fallback vacío.

### 4.2 Recepción (POST) — flujo del request (U2)

1. Validar firma `X-Hub-Signature-256` contra el **App Secret global** de la app:
   `HMAC-SHA256(app_secret, raw_body)` comparada con `hash_equals`. La firma se calcula sobre el
   **cuerpo crudo exacto** (`$request->getContent()`); jamás sobre un re-serializado del JSON.
   Firma ausente/incorrecta → **401** `WHATSAPP_SIGNATURE_INVALID` (nunca procesar).
2. Rechazar de forma segura JSON inválido, payload sobredimensionado o envelope que no sea un objeto
   `whatsapp_business_account` con `entry[].changes[]`. Los payloads firmados pero inválidos no se
   persisten ni se encolan y reciben ACK `200` para evitar reintentos infinitos. Un `field` no soportado
   se ignora; los cambios `messages` requieren `value` objeto.
3. Leer `entry[].changes[].value.messages[]` y `...statuses[]`. Extraer el identificador:
   `messages[].id` para mensajes; para `statuses[]` la clave de dedupe es compuesta
   `id|status|timestamp` (Meta reusa el id de mensaje en `delivered`/`read` → un UNIQUE simple
   sobre `statuses[].id` colisionaba). Otros `field` se ignoran.
4. **Dedupe**: `webhook_events` (plataforma) con UNIQUE `provider_event_id`. Insert con
   `ON CONFLICT DO NOTHING`; si ya existía → responde `200` con `duplicate = true` sin reprocesar.
   Esto cubre eventos duplicados y POSTs concurrentes (la violación de unique es el dedupe).
5. Resolver tenant por `metadata.phone_number_id` → `whatsapp_phone_numbers.phone_id`
   (consulta indexada, sin scope de tenant). Si no se encuentra: registrar `webhook_events.status=failed`
   con motivo "unknown_phone_number_id" (y log de alerta) pero **responder 200** igualmente (Meta no debe
   reintentar infinitamente).
6. Marcar atómicamente `webhook_events.status=enqueued` y despachar
   `ProcessIncomingWhatsAppMessage` / `ProcessWhatsAppStatusUpdate` a la cola
   (`forTenant($tenantId)`, TenantAwareJob). (`WhatsAppWebhookService::resolveAndEnqueue()` +
   `reprocessEvent()` público para el outbox, FASE 9.)
7. Responder `200`. **El request del webhook nunca hace trabajo pesado.** Si el dispatch falla,
   el evento vuelve a `received`, conserva `dispatch_failed` como código seguro y el sweeper lo
   recupera; no se descarta silenciosamente.

Los jobs (FASE 9) delegan en `MessageService`: mensaje entrante → find-or-create contacto
(`ContactService::findOrCreateForPhone`, FASE 7) + conversación activa (`findOrCreateActiveForContact`,
FASE 8) + mensaje con dedupe por `provider_message_id` + auditoría `message.received`; status →
update por `provider_message_id` (nunca crea). El tipo no soportado de Meta lanza
`UnsupportedMessageTypeException` → el job marca el evento `failed` (permanente). Luego el evento
pasa a `processed`. El motor de flujos (§5) se conecta en FASE 11.

### 4.3 Outbox (no perder eventos) — U2

`webhook_events.status` ∈ {received, enqueued, processed, failed}. El comando
`whatsapp:reprocess-webhook-events` (programado cada 1 minuto con `withoutOverlapping` en
`routes/console.php`) re-encola eventos con `status='received'` y `created_at` anterior a 5 minutos
(limit 100), usando `WhatsAppWebhookService::reprocessEvent()`. Así, si el proceso cae entre el
  insert y el encolado, el evento no se pierde. La transición a `enqueued` es atómica para que un replay y la ingesta inicial
  no despachen dos veces. Exitoso → marca `enqueued` y despacha el job; evento desconocido → `failed` con `error_code`.
  El comando `whatsapp:prune-webhook-events` elimina únicamente eventos terminales fuera de retención: procesados
  después de 7 días y fallidos después de 30 días por defecto. `received` y `enqueued` nunca se podan porque son
  recuperables.

### 4.4 Idempotencia

- `webhook_events.provider_event_id` UNIQUE (id global de Meta; compuesto `id|status|timestamp`
  para statuses) → dedupe a nivel plataforma.
- `messages` UNIQUE `(tenant_id, provider_message_id)` (los NULL no colisionan)
  → el mensaje inbound se crea una sola vez por worker (backstop `QueryException`).
- Un evento de `status` (sent/delivered/read/failed) **actualiza** el mensaje existente por
  `provider_message_id`, no crea mensajes.

### 4.5 Payload y privacidad (U2)

El registro persistido no conserva el envelope completo ni el raw body: solo guarda
`phone_number_id`, `type` y el elemento `data` necesario para replay del job. El límite de aplicación
por defecto es `WHATSAPP_WEBHOOK_MAX_PAYLOAD_BYTES=5242880` (5 MiB), configurable para batches
legítimos de Meta. El raw body no se registra en logs ni se envía a Sentry. La poda terminal usa
`WHATSAPP_WEBHOOK_RETENTION_DAYS=7`, `WHATSAPP_WEBHOOK_FAILED_RETENTION_DAYS=30` y un lote máximo
`WHATSAPP_WEBHOOK_PRUNE_BATCH=100`; no afecta eventos `received`/`enqueued`.

La resolución de tenant confía exclusivamente en `metadata.phone_number_id` y en la fila globalmente
única de `whatsapp_phone_numbers`. `tenant_id`, `waba_id` u otros valores del payload nunca seleccionan
tenant. La relación cuenta/número debe conservar el mismo tenant en el flujo de conexión; U2 no añade
una migración, y el webhook no usa la cuenta para seleccionar ownership.

### 4.6 Normalización inbound (U3)

`ProcessIncomingWhatsAppMessage` convierte cada elemento de `messages[]` a
`NormalizedInboundMessage` antes de llamar a `MessageService`. Los tipos soportados son `text`,
`image`, `video`, `audio`, `document`, `interactive`, `location` y el tipo histórico `template`.
El DTO conserva solo el identificador Meta, sender, timestamp, body normalizado y metadata específica:
media ID/mime/sha256/caption/filename/size/voice, `interactive` button/list (`id` y `title`) o
location (`latitude`, `longitude`, `name`, `address`). No se descargan binarios ni se aceptan URLs
remotas como media. `reaction`, `contacts`, `sticker` y tipos desconocidos permanecen terminalmente
unsupported, sin retry infinito.

Text mantiene el pipeline existente de contacto, conversación, FlowEngine, FAQ y handoff. Interactive
button/list expone su título como body para que los nodos de botones/preguntas existentes puedan
resolver la respuesta sin depender del array Meta original. Un inbound duplicado no vuelve a ejecutar
Flow/FAQ porque la barrera `provider_message_id` conserva `created=false`.

### 4.7 Estados monotónicos (U3)

El orden de éxito es `pending/sending < sent < delivered < read`. Se permiten `sent → delivered`,
`delivered → read` y saltos como `sent → read`; se ignoran `read → delivered`, `read → sent`,
`delivered → sent` y estados repetidos. `failed` se permite desde `pending`, `sending` o `sent`,
pero no regresa un mensaje ya `delivered`/`read` y es terminal para estados posteriores. Cada transición
se protege con `SELECT ... FOR UPDATE`; solo las transiciones reales auditan y emiten realtime.

Un status `failed` guarda en `metadata.status_failure` únicamente código, título y detalle corto
sanitizados; nunca el payload Meta completo. Los status de mensajes desconocidos siguen siendo no-op.

## 5. Flujo de mensaje entrante (worker)

```
ProcessIncomingWhatsAppMessage
 ├─ set TenantContext (por tenant_id resuelto en el webhook)  [finally: clear()]
 ├─ localizar whatsapp_phone_number (phone_number_id)
 ├─ find-or-create Contact (tenant + phone E.164)  → dedupe  [FASE 7]
 ├─ find-or-create Conversation (abierta o nueva)  [FASE 8]
 ├─ persist Message (inbound, dedupe por provider_message_id)  [FASE 9]
 ├─ marcar contexto de conversación (reabre resolved/archived)
 ├─ decidir acción (bajo LOCK de conversación, ver §7):   [PENDIENTE FASE 11]
 │    ├─ bot activo y no pausada → FlowEngine::handleMessage()
 │    ├─ keyword trigger → arrancar flow
 │    └─ sin bot → asignación/notificación a agentes
 ├─ webhook_events.status = processed
 └─ broadcast (Reverb) + audit
```

## 6. Envío (FASE 6: `WhatsAppMessagingService`; worker `SendWhatsAppMessage` en FASE 9)

- **FASE 6**: `WhatsAppMessagingService::sendText()` registra cada llamada al provider en
  `message_send_attempts` (provider_message_id, status, `attempt`/`max_attempts`), persiste el
  resultado y audita `whatsapp.message_sent`/`whatsapp.message_failed`. Un error permanente de
  Meta → `WhatsAppMessageFailedException` con `retryable=false` (502 `WHATSAPP_MESSAGE_FAILED`);
  timeout/5xx/429 → `retryable=true`.
- **FASE 9**: el job `SendWhatsAppMessage` encolado con `tenant_id`, `conversation_id`,
  `message_id` y `ShouldBeUnique` por `message_id` (impide envíos concurrentes duplicados del
  mismo mensaje). Worker re-valida cuenta conectada + número default conectado + tipo text
  (nunca confiar en estado previo).
- **CAS**: el envío solo procede si `message.status === 'pending'` (update atómico
  `where status='pending'` → `sending`). Si otro proceso ya lo tomó, se ignora.
- Llama al provider con el token del tenant → persiste `provider_message_id`, `sent` (+ audita
  `message.sent`). Registra `message_send_attempts` (attempt/max_attempts, `attempted_at`).
- Estados posteriores vía webhook de status: `delivered` → `read` (por `statuses[].id` compuesto,
  actualizando el mensaje por `provider_message_id`). `failed` → marca `failed` + `failed_at`
  (detalle del error en el attempt), y la conversación pasa a `pending` con aviso a agentes.
- Fallo → reintento con backoff `[10,30,60]` (cola `retry`, registrado en `message_send_attempts`),
  `failed` tras N intentos (`WHATSAPP_MAX_ATTEMPTS`, `SendWhatsAppMessage::tries()`); fallo
  permanente (4xx) → `failed` sin reintento.
- `markAsRead` se dispara cuando un agente abre la conversación (usa el phone id del tenant).

## 7. Concurrencia en la conversación

Dos mensajes del mismo cliente pueden llegar casi a la vez y dos workers podrían tocar la misma
conversación. Antes de ejecutar `FlowEngine::handleMessage` se adquiere un **lock de Redis**
(`lock:tenant:{id}:flow:{conversation_id}`, con espera breve y timeout). La única ejecución
activa por conversación se refuerza con el UNIQUE parcial de `flow_executions` (§ database.md).
Esto previene: doble ejecución de flow, respuestas duplicadas y carreras en `variables`.

## 8. Ventana de 24h y templates

- Mensaje del cliente abre ventana de 24h → texto libre, `interactive`, media OK.
- Fuera de ventana solo `template` (aprobado en el WABA del tenant). El engine decide: si la
  conversación está fuera de ventana, envía template (nombre configurado por el tenant) en lugar
  de texto libre.
- `markAsRead` y `templates` pueden usarse siempre; el número de templates se limita por Meta.

## 9. Rate limits de Meta

- Meta limita por número: throughput (mensajes/seg, por tier) y límite de mensajería
  conversacional (business-initiated). El provider captura 429 y cabeceras
  `X-Business-Use-Case-Usage` y expone backoff. La cola envía por número con throttling.
- Tests de rate limit simulados con `Http::fake`.

## 10. Desconexión / estado de números (FASE 6)

- `whatsapp_phone_numbers.status` (connected/disconnected/banned...). Un número desconectado
  **detiene** el envío (el engine lo comprueba) y notifica al owner.
- Desconectar (FASE 6) = cancelar suscripción del WABA al webhook (llamada Graph API,
  best-effort) + anular `access_token` + `status=disconnected`; **los datos históricos se
  conservan** (solo se borra el secreto). Audita `whatsapp.disconnected`.

## 11. Configuración (`.env`) — solo valores de la app (globales)

```
WHATSAPP_GRAPH_URL=https://graph.facebook.com   # opcional (default)
WHATSAPP_GRAPH_VERSION=v26.0
WHATSAPP_CONNECT_TIMEOUT=3        # conexión máxima en segundos
WHATSAPP_TIMEOUT=10               # request total máximo en segundos
WHATSAPP_APP_SECRET=...          # App Secret de la app (firma de webhooks, global)
WHATSAPP_VERIFY_TOKEN=...        # verify token del webhook (global)
WHATSAPP_MAX_ATTEMPTS=3          # reintentos de envío (FASE 9)
```
El access token de cada WABA y el phone id viven en DB (cifrados); NO en `.env`. La URL del
webhook a registrar en Meta es `https://<dominio>/api/webhooks/whatsapp`.

U1 mantiene la versión de Graph fijada y no usa `latest` ni la cambia automáticamente. Un cambio
de versión requiere revisar el changelog de Meta y ejecutar los contratos HTTP del provider antes
de modificar `WHATSAPP_GRAPH_VERSION`. El provider rechaza configuración inválida al realizar
llamadas salientes: URL insegura o host no oficial, versión inválida, timeouts no positivos o
desordenados, y tokens/identificadores en blanco. La firma POST y la verificación GET fallan
cerrado si falta el App Secret o el verify token configurado.

La rotación de access token se realiza reemplazando el token del tenant mediante la conexión
autorizada, que vuelve a cifrar el valor y no expone el anterior. La rotación del App Secret global
requiere una estrategia futura de solapamiento para no rechazar webhooks durante el cambio; la
rotación del verify token requiere actualizar la configuración de callback en Meta y revalidar el
webhook. Ninguna rotación de producción se ejecuta en U1.

## 12. Tests (FASE 6 implementado; ver `testing.md` y FASE 31)

- Verificación GET válida → challenge en texto plano; inválida → 403.
- Firma inválida/ausente → 401 `WHATSAPP_SIGNATURE_INVALID`.
- Mensaje entrante/status: ingesta + resolución de tenant por `phone_number_id` + dedupe.
- Evento duplicado → `duplicate = true`, no se reprocesa (WHATSAPP-8).
- `phone_number_id` desconocido → 200 + `webhook_events.failed` (WHATSAPP-10).
- Payload malformado → 200 + log (nunca 500 que provoque reenvíos) (WHATSAPP-5).
- Aislamiento: webhook de un número de B jamás toca datos de A (WHATSAPP-11, CRITICO).
- Conexión: token cifrado, 401/404 en Meta, 409 sin cuenta, aislamiento A/B (WHATSAPP-15..30).
- Provider: payload oficial, mapeo de errores retryable/no-retryable, timeout (WHATSAPP-31..40).
- FASE 9: mensaje entrante crea contact+conversation+message una sola vez (dedupe por
  `provider_message_id`, MSG-1..9); status delivered/read/failed actualiza mensajes y `failed`
  pasa la conversación a `pending` (STAT-1..8); outbound con CAS `pending → sending`, attempts,
  reintento retryable y fallo permanente (OUT-1..7); outbox sweeper (OUTBOX-1..4).
- FASE 10: REST `GET/POST /api/v1/tenants/{tenant}/conversations/{conversation}/messages`
  (`conversations.view` / `messages.send`) con envío async vía `SendWhatsAppMessage` y
  notificaciones Reverb (`MessageCreated`/`MessageStatusUpdated`/`ConversationUpdated`) en el canal
  privado por conversación para el inbox (MSG-API-1..16).
- Pendiente (FASE 11+): media outbound, templates, lock de conversación, rate limits.
