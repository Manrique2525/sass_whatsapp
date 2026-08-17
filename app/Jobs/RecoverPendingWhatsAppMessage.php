<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Recupera un outbound persistido cuyo enqueue original pudo fallar. No usa
 * unique lock: SendWhatsAppMessage conserva la exclusión y el CAS al ejecutarse.
 */
final class RecoverPendingWhatsAppMessage implements ShouldQueue
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

    public function tries(): int
    {
        return max(1, (int) config('whatsapp.max_attempts', 3)) + 10;
    }

    protected function executeInTenantContext(): void
    {
        (new SendWhatsAppMessage(
            $this->tenantId,
            $this->conversationId,
            $this->messageId,
        ))->handle();
    }

    public function failed(?Throwable $exception): void
    {
        (new SendWhatsAppMessage(
            $this->tenantId,
            $this->conversationId,
            $this->messageId,
        ))->failed($exception);
    }
}
