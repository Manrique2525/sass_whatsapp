<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Billing\Guards\UsageGuard;
use App\Application\Flows\Services\ConversationLockContext;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Messages\Services\MessageOriginClassifier;
use App\Application\Messages\Services\MessageService;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Models\UsageReservation;
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
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

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

    public bool $afterCommit = true;

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
        return $this->providerMaxAttempts() + 10;
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

        $lockContext = app(ConversationLockContext::class);

        if ($lockContext->refreshHeld($tenant->id, $this->conversationId, $this->timeout + 30)) {
            $this->sendLocked($tenant);

            return;
        }

        $lock = app(FlowExecutionService::class)->conversationLock(
            $tenant,
            $this->conversationId,
            seconds: $this->timeout + 30,
        );
        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            if (config('queue.default') === 'sync') {
                $message = Message::query()
                    ->withoutTenantScope()
                    ->where('tenant_id', $this->tenantId)
                    ->where('conversation_id', $this->conversationId)
                    ->whereKey($this->messageId)
                    ->first();

                if ($message !== null) {
                    $this->failMessage(
                        $tenant,
                        $message,
                        MessageService::LOCK_TIMEOUT_CODE,
                        metadata: [
                            'error_code' => MessageService::LOCK_TIMEOUT_CODE,
                            'error_source' => 'internal',
                        ],
                    );
                }

                return;
            }

            if ($this->job === null) {
                throw new LockTimeoutException;
            }

            $this->release(1);

            return;
        }

        try {
            $this->sendLocked($tenant);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(Tenant $tenant): void
    {

        $message = Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('conversation_id', $this->conversationId)
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

        if ($conversation->bot_paused
            && $conversation->handoff_requested_at !== null
            && app(MessageOriginClassifier::class)->isAutomation($message)) {
            app(MessageService::class)->blockAutomaticMessageForHandoff($tenant, $message);

            return;
        }

        $claimedPending = false;

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

            $message->refresh();
            $claimedPending = true;
        }

        if ($message->type !== MessageType::Text) {
            $this->failMessage($tenant, $message, 'unsupported_outbound_type');

            return;
        }

        $idempotencyKey = "message:{$message->id}";
        $reservation = app(UsageGuard::class)->reserve(
            tenant: $tenant,
            category: UsageCategory::Messages,
            quantity: 1,
            idempotencyKey: $idempotencyKey,
            ttlSeconds: 900,
        );

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

        $recordedAttempts = MessageSendAttempt::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('payload->message_id', $message->id)
            ->count();
        $providerMaxAttempts = $this->providerMaxAttempts();
        $tracksAttemptsByMessage = ($message->metadata['attempt_tracking'] ?? null) === 'message_id_v1';
        $providerAttempt = $recordedAttempts > 0
            ? $recordedAttempts + 1
            : ($claimedPending || $tracksAttemptsByMessage
                ? 1
                : min(max(1, $this->attempts()), $providerMaxAttempts));

        $attempt = MessageSendAttempt::create([
            'whatsapp_phone_number_id' => $phone->id,
            'to' => $to,
            'type' => $message->type->value,
            'payload' => [
                'message_id' => $message->id,
                'text' => $message->body,
            ],
            'status' => MessageSendStatus::Pending,
            'attempt' => $providerAttempt,
            'max_attempts' => $providerMaxAttempts,
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

            if ($e->retryable() && $providerAttempt < $providerMaxAttempts) {
                throw $e;
            }

            $this->failMessage($tenant, $message, $e->errorCode()->value, $e->getMessage());
            if ($reservation !== null) {
                app(UsageGuard::class)->release($reservation);
            }

            return;
        }

        if ($reservation !== null) {
            app(UsageGuard::class)->commit($reservation);
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

    public function failed(?Throwable $exception): void
    {
        TenantContext::withId($this->tenantId, function () use ($exception): void {
            $tenant = Tenant::query()->find($this->tenantId);
            $message = Message::query()
                ->withoutTenantScope()
                ->where('tenant_id', $this->tenantId)
                ->where('conversation_id', $this->conversationId)
                ->whereKey($this->messageId)
                ->first();

            if ($tenant === null
                || $message === null
                || ! in_array($message->status, [MessageStatus::Pending, MessageStatus::Sending], true)) {
                return;
            }

            $this->releaseReservationIfExists($tenant, $message);

            $this->failMessage(
                $tenant,
                $message,
                MessageService::QUEUE_EXHAUSTED_CODE,
                $exception?->getMessage(),
                [
                    'error_code' => MessageService::QUEUE_EXHAUSTED_CODE,
                    'error_source' => 'internal',
                ],
            );
        });
    }

    private function providerMaxAttempts(): int
    {
        return max(1, (int) config('whatsapp.max_attempts', 3));
    }

    /** @param array<string, mixed> $metadata */
    private function failMessage(
        Tenant $tenant,
        Message $message,
        string $errorCode,
        ?string $errorMessage = null,
        array $metadata = [],
    ): void {
        $previous = $message->status->value;

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'metadata' => array_merge($message->metadata ?? [], $metadata),
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

    private function releaseReservationIfExists(Tenant $tenant, Message $message): void
    {
        $idempotencyKey = "message:{$message->id}";

        $reservation = UsageReservation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', UsageReservationStatus::Reserved)
            ->first();

        if ($reservation !== null) {
            try {
                app(UsageGuard::class)->release($reservation);
            } catch (Throwable) {
                // Best-effort release; reservation will expire via TTL
            }
        }
    }
}
