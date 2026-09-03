<?php

declare(strict_types=1);

namespace App\Application\WhatsApp\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Replay operator de eventos de webhook (FASE 31 U6).
 *
 * Permite a un owner/admin RE-encolar eventos `failed` o `received` atascados de
 * SU tenant. La tabla `webhook_events` es de PLATAFORMA (sin `BelongsToTenant`),
 * por lo que aquí se filtra SIEMPRE por `tenant_id` del tenant para respetar el
 * aislamiento: un tenant jamás lista ni replayea eventos de otro.
 *
 * - Solo estados elegibles: `failed` (terminal, decisión explícita) y `received`
 *   (stale). NUNCA `processed`/`enqueued` (evita doble trabajo).
 * - `--dry-run`: reporta lo que se re-procesaría SIN mutar nada.
 * - Cada replay re-encolado queda auditado (`whatsapp.webhook.replayed`).
 */
final class WhatsAppWebhookReplayService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WhatsAppWebhookService $webhookService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Lista los eventos elegibles para replay de un tenant (owner/admin).
     *
     * @return array{status: 'clean'|'pending', count: int}
     */
    public function count(User $user, Tenant $tenant): array
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $failed = $this->query($tenant)
            ->where('status', WebhookEventStatus::Failed->value)
            ->count();

        $received = $this->query($tenant)
            ->where('status', WebhookEventStatus::Received->value)
            ->count();

        $status = ($failed + $received) > 0 ? 'pending' : 'clean';

        return ['status' => $status, 'count' => $failed + $received];
    }

    /**
     * Replaya eventos `failed` de un tenant (owner/admin).
     *
     * @return array{requested: int, replayed: int, failed: int}
     */
    public function replayFailed(User $user, Tenant $tenant, int $limit = 100): array
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $events = $this->query($tenant)
            ->where('status', WebhookEventStatus::Failed->value)
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->all();

        $replayed = 0;
        $failed = 0;

        foreach ($events as $event) {
            /** @var WebhookEvent $event */
            if ($this->webhookService->replayEvent($event)) {
                $this->auditLogger->record(
                    action: 'whatsapp.webhook.replayed',
                    data: [
                        'tenant_id' => $tenant->id,
                        'webhook_event_id' => $event->id,
                        'provider_event_id' => $event->provider_event_id,
                        'previous_status' => WebhookEventStatus::Failed->value,
                    ],
                    subjectType: WebhookEvent::class,
                    subjectId: $event->id,
                    tenantId: $tenant->id,
                );

                $replayed++;
            } else {
                $failed++;
                Log::warning('whatsapp.webhook.replay_skipped', [
                    'webhook_event_id' => $event->id,
                    'tenant_id' => $tenant->id,
                ]);
            }
        }

        return [
            'requested' => count($events),
            'replayed' => $replayed,
            'failed' => $failed,
        ];
    }

    /**
     * Query base siempre scopeada al tenant para aislamiento.
     *
     * @return Builder<WebhookEvent>
     */
    private function query(Tenant $tenant): Builder
    {
        return WebhookEvent::query()
            ->where('tenant_id', $tenant->id);
    }
}
