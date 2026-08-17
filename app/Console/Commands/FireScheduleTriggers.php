<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Flows\Services\TriggerValidator;
use App\Jobs\StartFlowFromSchedule;
use Illuminate\Console\Command;

/**
 * Sweeper de triggers schedule (FASE 14, UNIDAD 2, ADR-048).
 *
 * Se ejecuta cada minuto desde routes/console.php con withoutOverlapping().
 * Recorre todos los triggers de tipo schedule activos cuyo flujo está
 * publicado y chatbot operativo, evalúa si el cron coincide con el momento
 * actual y despacha un StartFlowFromSchedule por cada uno.
 *
 * Corre fuera de TenantContext (CLI global): la query usa withoutTenantScope.
 * Cada job establece su propio contexto vía TenantAwareJob.
 */
final class FireScheduleTriggers extends Command
{
    protected $signature = 'flow:fire-schedule-triggers';

    protected $description = 'Despacha jobs para triggers schedule que coinciden con el minuto actual.';

    public function handle(): int
    {
        $now = now();

        $chatbotIds = Chatbot::query()
            ->withoutTenantScope()
            ->pluck('id');

        $publishedFlowIds = Flow::query()
            ->withoutTenantScope()
            ->where('status', FlowStatus::Published->value)
            ->whereIn('chatbot_id', $chatbotIds)
            ->pluck('id');

        $triggers = Trigger::query()
            ->withoutTenantScope()
            ->where('active', true)
            ->where('type', FlowTriggerType::Schedule->value)
            ->whereIn('flow_id', $publishedFlowIds)
            ->get();

        $fired = 0;

        foreach ($triggers as $trigger) {
            $cron = is_array($trigger->config) ? ($trigger->config['cron'] ?? null) : null;

            if (! is_string($cron) || ! TriggerValidator::matchesCron($cron, $now)) {
                continue;
            }

            dispatch(
                (new StartFlowFromSchedule($trigger->id))
                    ->forTenant($trigger->tenant_id),
            );

            $fired++;
        }

        $this->info(sprintf('Schedule triggers procesados: %d.', $fired));

        return self::SUCCESS;
    }
}
