<?php

declare(strict_types=1);

namespace App\Application\WhatsApp\Services;

use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Exceptions\WhatsAppWebhookInvalidException;
use App\Domain\WhatsApp\Exceptions\WhatsAppWebhookSignatureInvalidException;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pipeline de recepción de webhooks de Meta (FASE 6, ADR-029).
 *
 * 1. Validar firma `X-Hub-Signature-256` (HMAC-SHA256 sobre el body CRUDO).
 * 2. Extraer `messages[]`/`statuses[]` con su `provider_event_id`.
 * 3. Dedupe real con `INSERT ... ON CONFLICT DO NOTHING` (UNIQUE
 *    `provider_event_id`): duplicados secuenciales o concurrentes se ignoran.
 * 4. Resolver tenant por `metadata.phone_number_id` (sin scope, indexado).
 * 5. Marcar enqueued y despachar el job del tipo correspondiente.
 *
 * El request NUNCA hace trabajo pesado y responde rápido (200): Meta reintenta
 * todo lo que no sea 200.
 */
final class WhatsAppWebhookService
{
    public function __construct(private readonly WhatsAppProviderInterface $provider) {}

    /**
     * Verificación GET de Meta. Devuelve el challenge a repetir o null si el
     * request no es válido.
     *
     * @param  array<string, mixed>  $query
     */
    public function verify(array $query): ?string
    {
        $result = $this->provider->verifyWebhook($query);

        return $result['verified'] ? $result['challenge'] : null;
    }

    public function handle(Request $request): void
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! $this->provider->validateWebhookSignature($signature, $rawBody)) {
            throw new WhatsAppWebhookSignatureInvalidException;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            Log::warning('whatsapp.webhook_invalid', ['reason' => 'invalid_json']);

            throw new WhatsAppWebhookInvalidException;
        }

        $entries = $payload['entry'] ?? [];

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = $entry['changes'] ?? [];

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? null;

                if (! is_array($value)) {
                    continue;
                }

                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                $phoneNumberId = isset($metadata['phone_number_id']) && is_scalar($metadata['phone_number_id'])
                    ? (string) $metadata['phone_number_id']
                    : null;

                $messages = $value['messages'] ?? [];

                if (is_array($messages)) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            $this->ingest(WebhookEventType::Message, $message, $phoneNumberId);
                        }
                    }
                }

                $statuses = $value['statuses'] ?? [];

                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if (is_array($status)) {
                            $this->ingest(WebhookEventType::Status, $status, $phoneNumberId);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ingest(WebhookEventType $type, array $item, ?string $phoneNumberId): void
    {
        $id = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';

        if ($id === '') {
            Log::warning('whatsapp.webhook_missing_event_id', ['type' => $type->value]);

            return;
        }

        // Meta envía `statuses[].id` = id del MENSAJE: delivered/read/... del
        // mismo mensaje comparten id. Para no descartar updates posteriores como
        // "duplicados", la clave de dedupe de status es compuesta id|status|timestamp.
        $providerEventId = match ($type) {
            WebhookEventType::Message => $id,
            WebhookEventType::Status => sprintf(
                '%s|%s|%s',
                $id,
                (string) ($item['status'] ?? ''),
                (string) ($item['timestamp'] ?? ''),
            ),
        };

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'provider_event_id' => $providerEventId,
            'tenant_id' => null,
            'payload' => json_encode([
                'phone_number_id' => $phoneNumberId,
                'type' => $type->value,
                'data' => $item,
            ], JSON_THROW_ON_ERROR),
            'status' => WebhookEventStatus::Received->value,
            'event_type' => $type->value,
            'duplicate' => false,
            'error_code' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            WebhookEvent::query()->where('provider_event_id', $providerEventId)->update(['duplicate' => true]);

            Log::info('whatsapp.webhook_duplicate', ['provider_event_id' => $providerEventId]);

            return;
        }

        /** @var WebhookEvent $event */
        $event = WebhookEvent::query()->where('provider_event_id', $providerEventId)->firstOrFail();

        $this->resolveAndEnqueue($event, $phoneNumberId);
    }

    /**
     * Re-procesa un evento que quedó en `received` (outbox, FASE 9).
     *
     * Lo invoca el sweeper programado cuando el proceso murió entre el insert y
     * el encolado del job. Resuelve el tenant por `phone_number_id` del payload
     * y encola; si el número ya no existe, marca `failed` (no reintenta en bucle).
     */
    public function reprocessEvent(WebhookEvent $event): void
    {
        if ($event->status !== WebhookEventStatus::Received) {
            return;
        }

        $payload = $event->payload;
        $phoneNumberId = is_array($payload) && isset($payload['phone_number_id']) && is_scalar($payload['phone_number_id'])
            ? (string) $payload['phone_number_id']
            : null;

        $this->resolveAndEnqueue($event, $phoneNumberId);
    }

    private function resolveAndEnqueue(WebhookEvent $event, ?string $phoneNumberId): void
    {
        if ($phoneNumberId === null || $phoneNumberId === '') {
            $event->markFailed('missing_phone_number_id');

            Log::warning('whatsapp.webhook_missing_phone_number_id', ['provider_event_id' => $event->provider_event_id]);

            return;
        }

        $phone = WhatsAppPhoneNumber::query()
            ->withoutTenantScope()
            ->where('phone_id', $phoneNumberId)
            ->first();

        if ($phone === null) {
            $event->markFailed('unknown_phone_number_id');

            Log::warning('whatsapp.webhook_unknown_phone_number_id', [
                'provider_event_id' => $event->provider_event_id,
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        $event->fill([
            'status' => WebhookEventStatus::Enqueued,
            'tenant_id' => $phone->tenant_id,
        ])->save();

        $type = $event->event_type;

        if ($type === null) {
            $event->markFailed('invalid_payload');

            return;
        }

        match ($type) {
            WebhookEventType::Message => dispatch((new ProcessIncomingWhatsAppMessage($event->id))->forTenant($phone->tenant_id)),
            WebhookEventType::Status => dispatch((new ProcessWhatsAppStatusUpdate($event->id))->forTenant($phone->tenant_id)),
        };
    }
}
