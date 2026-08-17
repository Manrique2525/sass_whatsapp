<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Flows\Services\FlowEngine;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ejecuta un trigger webhook (FASE 14, UNIDAD 3, ADR-049).
 *
 * Despachado por FlowWebhookController tras validar token, resolver
 * conversación y verificar idempotencia. Revalida todas las condiciones
 * (defensa en profundidad) bajo TenantContext propio, adquiere lock de
 * conversación y delega al pipeline existente FlowEngine::handleScheduleTrigger.
 *
 * Capas de protección:
 * 1. Idempotency-Key + Cache::lock en controller (pre-despacho).
 * 2. ShouldBeUnique por idempotencyKey (cola).
 * 3. Re-validación completa en executeInTenantContext (defensa en profundidad).
 * 4. FlowEngine::conversationLock (runtime, dentro del motor).
 * 5. FlowExecutionService::findActive (no crea si hay ejecución activa).
 * 6. UNIQUE parcial en flow_executions (barrera DB).
 */
final class StartFlowFromWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $triggerId,
        public readonly string $conversationId,
        public readonly string $idempotencyKey,
        public readonly array $payload = [],
    ) {}

    public function uniqueId(): string
    {
        return "webhook-trigger:{$this->idempotencyKey}";
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            return;
        }

        $trigger = Trigger::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->triggerId)
            ->first();

        if ($trigger === null || ! $trigger->active || $trigger->type !== FlowTriggerType::Webhook) {
            return;
        }

        $flow = $trigger->flow;

        if ($flow === null || $flow->status !== FlowStatus::Published) {
            return;
        }

        if ($flow->chatbot === null) {
            return;
        }

        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->conversationId)
            ->first();

        if ($conversation === null) {
            return;
        }

        if (! app(FlowEngine::class)->handleScheduleTrigger($tenant, $flow, $conversation)) {
            return;
        }

        app(AuditLogger::class)->record(
            action: 'flow.webhook_triggered',
            data: [
                'trigger_id' => $trigger->id,
                'flow_id' => $flow->id,
                'conversation_id' => $conversation->id,
            ],
            subjectType: Trigger::class,
            subjectId: $trigger->id,
            tenantId: $this->tenantId,
        );
    }
}
