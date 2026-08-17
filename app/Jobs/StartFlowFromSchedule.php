<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Flows\Services\FlowEngine;
use App\Application\Flows\Services\FlowExecutionService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Flows\Services\TriggerValidator;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Ejecuta un trigger schedule (FASE 14, UNIDAD 2, ADR-048).
 *
 * Despachado por `FireScheduleTriggers` cada minuto. Establece su propio
 * TenantContext, valida todas las condiciones del trigger (defensa en
 * profundidad), adquiere un lock Redis por trigger para evitar doble
 * disparo entre ticks, y delega al FlowEngine vía handleScheduleTrigger()
 * que usa el mismo pipeline start() + run() que los mensajes entrantes.
 *
 * Capas de protección contra duplicación:
 * 1. Command `withoutOverlapping()` (serializa sweeper).
 * 2. ShouldBeUnique por triggerId (cola).
 * 3. Cache::lock por trigger (runtime, dentro del job).
 * 4. FlowEngine::conversationLock (runtime, dentro del motor).
 * 5. FlowExecutionService::findActive (no crea si hay ejecución activa).
 * 6. UNIQUE parcial en flow_executions (barrera DB).
 */
final class StartFlowFromSchedule implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $triggerId,
    ) {}

    public function uniqueId(): string
    {
        return "schedule-trigger:{$this->triggerId}";
    }

    public function uniqueFor(): int
    {
        return 30;
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $trigger = Trigger::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->triggerId)
            ->first();

        if ($trigger === null) {
            return;
        }

        if (! $trigger->active) {
            return;
        }

        if ($trigger->type !== FlowTriggerType::Schedule) {
            return;
        }

        $flow = $trigger->flow;

        if ($flow === null || $flow->status !== FlowStatus::Published) {
            return;
        }

        if ($flow->chatbot === null) {
            return;
        }

        $config = is_array($trigger->config) ? $trigger->config : [];
        $cron = $config['cron'] ?? null;

        if (! is_string($cron) || ! TriggerValidator::matchesCron($cron, now())) {
            return;
        }

        $conversationId = $config['conversation_id'] ?? null;

        if (! is_string($conversationId)) {
            return;
        }

        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            return;
        }

        $lock = Cache::lock("lock:schedule:trigger:{$trigger->id}", 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $conversation->refresh();

            if ($conversation->bot_paused) {
                return;
            }

            $active = app(FlowExecutionService::class)->findActive($conversation);

            if ($active !== null) {
                return;
            }

            app(FlowEngine::class)->handleScheduleTrigger($tenant, $flow, $conversation);

            app(AuditLogger::class)->record(
                action: 'flow.schedule_triggered',
                data: [
                    'trigger_id' => $trigger->id,
                    'flow_id' => $flow->id,
                    'conversation_id' => $conversation->id,
                ],
                subjectType: Trigger::class,
                subjectId: $trigger->id,
                tenantId: $this->tenantId,
            );
        } finally {
            $lock->release();
        }
    }
}
