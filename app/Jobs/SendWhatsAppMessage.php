<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\MessageSendStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Events\MessageStatusUpdated;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envío asíncrono de un mensaje saliente (FASE 9, ADR-032; diseño §6 whatsapp.md).
 *
 * - `ShouldBeUnique` por message_id: nunca hay dos jobs enviando el mismo mensaje.
 * - CAS `pending → sending`: si otro worker ya lo tomó, este job se ignora.
 * - Re-valida en el worker cuenta conectada + número + límites (nunca confía en
 *   el estado previo). Reintenta SOLO errores retryable de Meta (timeout/5xx/429)
 *   con backoff; errores permanentes o intentos agotados marcan el mensaje
 *   `failed` y registran `message_send_attempts`.
 */
final class SendWhatsAppMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use TenantAwareJob;

    public int $timeout = 60;

    public function __construct(
        string $tenantId,
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return 'send:'.$this->messageId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function tries(): int
    {
        return (int) config('whatsapp.max_attempts', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $message = Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->messageId)
            ->first();

        if ($message === null || in_array($message->status, [MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read, MessageStatus::Failed], true)) {
            return;
        }

        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->conversationId)
            ->first();

        if ($conversation === null) {
            $this->failMessage($tenant, $message, 'conversation_not_found');

            return;
        }

        if ($message->status === MessageStatus::Pending) {
            $taken = Message::query()
                ->withoutTenantScope()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($this->messageId)
                ->where('status', MessageStatus::Pending)
                ->update(['status' => MessageStatus::Sending]);

            if ($taken === 0) {
                return;
            }
        }

        if ($message->type !== MessageType::Text) {
            $this->failMessage($tenant, $message, 'unsupported_outbound_type');

            return;
        }

        $account = $tenant->whatsappAccount;
        $phone = $tenant->whatsappPhoneNumbers()
            ->where('status', PhoneNumberStatus::Connected->value)
            ->orderByDesc('is_default')
            ->first();

        $accessToken = $account?->access_token;

        if ($account === null || $phone === null || ! $account->isConnected() || $accessToken === null || $accessToken === '') {
            $this->failMessage($tenant, $message, 'whatsapp_not_connected');

            return;
        }

        $to = $conversation->contact?->phone;

        if ($to === null || $to === '') {
            $this->failMessage($tenant, $message, 'missing_recipient');

            return;
        }

        $attempt = MessageSendAttempt::create([
            'whatsapp_phone_number_id' => $phone->id,
            'to' => $to,
            'type' => $message->type->value,
            'payload' => ['text' => $message->body],
            'status' => MessageSendStatus::Pending,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries(),
        ]);

        $provider = app(WhatsAppProviderInterface::class);

        try {
            $result = $provider->sendText($accessToken, $phone->phone_id, $to, (string) $message->body);
        } catch (WhatsAppMessageFailedException $e) {
            $attempt->fill([
                'status' => MessageSendStatus::Failed,
                'error_code' => $e->errorCode()->value,
                'error_message' => $e->getMessage(),
                'payload' => array_merge((array) $attempt->payload, [
                    'provider_error_code' => $e->providerErrorCode(),
                    'retryable' => $e->retryable(),
                ]),
                'attempted_at' => now(),
            ])->save();

            if ($e->retryable() && $this->attempts() < $this->tries()) {
                throw $e;
            }

            $this->failMessage($tenant, $message, $e->errorCode()->value, $e->getMessage());

            return;
        }

        $attempt->fill([
            'status' => MessageSendStatus::Sent,
            'provider_message_id' => $result->providerMessageId,
            'attempted_at' => now(),
        ])->save();

        $previous = $message->status->value;

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => now(),
        ])->save();

        app(AuditLogger::class)->record(
            action: 'message.sent',
            data: [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'provider_message_id' => $result->providerMessageId,
            ],
            subjectType: Message::class,
            subjectId: $message->id,
            tenantId: $this->tenantId,
        );

        event(new MessageStatusUpdated($message, $previous));
    }

    private function failMessage(Tenant $tenant, Message $message, string $errorCode, ?string $errorMessage = null): void
    {
        $previous = $message->status->value;

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
        ])->save();

        app(AuditLogger::class)->record(
            action: 'message.failed',
            data: [
                'tenant_id' => $tenant->id,
                'conversation_id' => $this->conversationId,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
            subjectType: Message::class,
            subjectId: $message->id,
            tenantId: $tenant->id,
        );

        event(new MessageStatusUpdated($message, $previous));
    }
}
