# WhatsApp (Meta Cloud API)

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
    public function sendText(string $phoneId, string $to, string $text, array $context = []): MessageSendResult;
    public function sendTemplate(string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult;
    public function sendImage(string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult;
    public function sendDocument(string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult;
    public function sendInteractiveMessage(string $phoneId, string $to, InteractiveMessage $message): MessageSendResult;
    public function markAsRead(string $phoneId, string $messageId): void;
    public function validateWebhookSignature(string $signature, string $rawBody): bool;
    public function verifyWebhook(array $query): array; // verificación GET (challenge)
}
```

Implementación: `MetaWhatsAppProvider` (Laravel HTTP Client, base
`https://graph.facebook.com/v21.0/`). **El token de autenticación de cada llamada se obtiene de
`whatsapp_accounts.access_token` del tenant** (nunca de `.env`). El resultado normaliza
`provider_message_id`, estado y errores (`MessageSendResult`).

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

### 4.2 Recepción (POST) — flujo del request

1. Validar firma `X-Hub-Signature-256` contra el **App Secret global** de la app:
   `HMAC-SHA256(app_secret, raw_body)` comparada con `hash_equals`. La firma se calcula sobre el
   **cuerpo crudo exacto** (`$request->getContent()`); jamás sobre un re-serializado del JSON.
2. Leer `entry[].changes[].value.messages[]` y `...statuses[]`. Extraer el identificador:
   `messages[].id` para mensajes, `statuses[].id` para estados.
3. **Dedupe**: `webhook_events` (plataforma) con UNIQUE `provider_event_id`. Insert con
   `ON CONFLICT DO NOTHING`; si ya existía → responde `200` (duplicado) sin reprocesar. Esto
   cubre eventos duplicados y POSTs concurrentes (la violación de unique es el dedupe).
4. Resolver tenant por `metadata.phone_number_id` → `whatsapp_phone_numbers.phone_id`
   (consulta indexada). Si no se encuentra: registrar `webhook_events.status=failed` con motivo
   "unknown_phone_number_id" (y log de alerta) pero **responder 200** igualmente (Meta no debe
   reintentar infinitamente).
5. Marcar `webhook_events.status=enqueued` y despachar
   `ProcessIncomingWhatsAppMessage` / `ProcessWhatsAppStatusUpdate` a la cola.
6. Responder `200`. **El request del webhook nunca hace trabajo pesado.**

### 4.3 Outbox (no perder eventos)

`webhook_events.status` ∈ {received, enqueued, processed, failed}. Un comando programado
(every 1 min) re-encola eventos con `status='received'` con `created_at` anterior a X minutos.
Así, si el proceso cae entre el insert y el encolado, el evento no se pierde.

### 4.4 Idempotencia

- `webhook_events.provider_event_id` UNIQUE (id global de Meta) → dedupe a nivel plataforma.
- `messages` UNIQUE parcial `(tenant_id, provider_message_id) WHERE provider_message_id IS NOT NULL`
  → el mensaje inbound se crea una sola vez por worker.
- Un evento de `status` (delivered/read/failed) **actualiza** el mensaje existente por
  `provider_message_id`, no crea mensajes.

## 5. Flujo de mensaje entrante (worker)

```
ProcessIncomingWhatsAppMessage
 ├─ set TenantContext (por tenant_id resuelto en el webhook)  [finally: clear()]
 ├─ localizar whatsapp_phone_number (phone_number_id)
 ├─ find-or-create Contact (tenant + phone E.164)  → dedupe
 ├─ find-or-create Conversation (abierta o nueva)
 ├─ persist Message (inbound)
 ├─ marcar contexto de conversación
 ├─ decidir acción (bajo LOCK de conversación, ver §7):
 │    ├─ bot activo y no pausada → FlowEngine::handleMessage()
 │    ├─ keyword trigger → arrancar flow
 │    └─ sin bot → asignación/notificación a agentes
 ├─ webhook_events.status = processed
 └─ broadcast (Reverb) + audit
```

## 6. Envío (worker `SendWhatsAppMessage`)

- Job encolado con `tenant_id`, `conversation_id`, `message_id`. Implementa `ShouldBeUnique`
  por `message_id` (impide envíos concurrentes duplicados del mismo mensaje).
- Worker re-valida límite de uso y permisos del tenant (nunca confiar en estado previo).
- **CAS**: el envío solo procede si `message.status === 'pending'` (update atómico
  `where status='pending'` → `sending`). Si otro proceso ya lo tomó, se ignora.
- Llama al provider con el token del tenant → persiste `provider_message_id`, `sent`.
- Estados posteriores vía webhook de status: `delivered` → `read` (por `statuses[].id`,
  actualizando el mensaje por `provider_message_id`). `failed` → marca `failed`, log, y la
  conversación pasa a `pending` con aviso a agentes.
- Fallo → reintento con backoff (cola `retry`, registrado en `message_send_attempts`),
  `failed` tras N intentos.
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

## 10. Desconexión / estado de números

- `whatsapp_phone_numbers.status` (connected/disconnected/banned...). Un número desconectado
  **detiene** el envío (el engine lo comprueba) y notifica al owner.
- Desconectar = cancelar suscripción del WABA al webhook (llamada Graph API) + revocar token;
  los datos históricos se conservan.

## 11. Configuración (`.env`) — solo valores de la app (globales)

```
WHATSAPP_GRAPH_VERSION=v21.0
WHATSAPP_APP_SECRET=...          # App Secret de la app (firma de webhooks, global)
WHATSAPP_VERIFY_TOKEN=...        # verify token del webhook (global)
```
El access token de cada WABA y el phone id viven en DB (cifrados); NO en `.env`.

## 12. Tests (ver `testing.md` y FASE 31)

- Verificación GET válida/inválida.
- Firma inválida → 401.
- Mensaje entrante de texto/interactivo/template.
- Status delivered/read/failed.
- Evento duplicado (secuencial y concurrente) → no duplica `messages` ni `webhook_events`.
- `phone_number_id` desconocido → 200 + `webhook_events.failed`.
- Payload malformado → 200 + log (nunca 500 que provoque reenvíos).
- Timeout/errores de Meta → reintento controlado + `message_send_attempts`.
- Outbox: evento en `received` se re-encola por el sweeper.
- Lock de conversación: dos mensajes concurrentes no duplican ejecución.
