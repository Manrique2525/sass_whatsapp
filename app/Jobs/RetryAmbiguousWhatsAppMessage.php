<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Replay explícito de un outbound cuyo resultado de transporte fue ambiguo.
 * Nunca se dispara desde el worker normal: requiere una decisión operativa.
 */
final class RetryAmbiguousWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use TenantAwareJob;

    public function __construct(
        string $tenantId,
        public readonly string $conversationId,
        public readonly string $messageId,
    ) {
        $this->tenantId = $tenantId;
    }

    protected function executeInTenantContext(): void
    {
        $replay = DB::transaction(function (): bool {
            $message = Message::query()
                ->withoutTenantScope()
                ->where('tenant_id', $this->tenantId)
                ->where('conversation_id', $this->conversationId)
                ->whereKey($this->messageId)
                ->where('status', MessageStatus::Sending)
                ->lockForUpdate()
                ->first();

            if ($message === null || ($message->metadata['delivery_state'] ?? null) !== 'ambiguous') {
                return false;
            }

            $message->forceFill([
                'status' => MessageStatus::Pending,
                'metadata' => array_merge($message->metadata ?? [], [
                    'delivery_state' => 'replay_requested',
                    'replay_requested_at' => now()->toIso8601String(),
                ]),
            ])->save();

            app(AuditLogger::class)->record(
                action: 'message.delivery_replayed',
                data: [
                    'tenant_id' => $this->tenantId,
                    'conversation_id' => $this->conversationId,
                    'message_id' => $this->messageId,
                ],
                subjectType: Message::class,
                subjectId: $this->messageId,
                tenantId: $this->tenantId,
            );

            return true;
        });

        if (! $replay) {
            return;
        }

        dispatch(
            (new SendWhatsAppMessage($this->tenantId, $this->conversationId, $this->messageId))
                ->forTenant($this->tenantId),
        );
    }
}
