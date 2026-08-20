<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Contacts\Enums\TagAssignmentOrigin;
use App\Domain\Contacts\Events\TagAssigned;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
use App\Jobs\StartFlowFromTag;

/**
 * Escucha TagAssigned y despacha StartFlowFromTag para triggers de tipo tag.
 *
 * Primer listener del codebase (FASE 20, UNIDAD 4, ADR-050).
 *
 * Política anti-recursión: si la asignación proviene de un flujo (origin=Flow),
 * se descarta completamente para evitar cadenas tag→flow→tag. Solo origin=Manual
 * (API/asignación manual) activa el pipeline de triggers.
 *
 * Patrón similar a FireScheduleTriggers: busca todos los triggers activos del
 * tenant cuyo config.tags contenga el tag asignado y despacha un job por cada uno.
 *
 * Registrado en AppServiceProvider::boot() vía Event::listen().
 */
class DispatchTagTriggerJob
{
    public function handle(TagAssigned $event): void
    {
        if ($event->origin === TagAssignmentOrigin::Flow) {
            return;
        }

        $triggers = Trigger::query()
            ->withoutTenantScope()
            ->where('tenant_id', $event->tenantId)
            ->where('type', FlowTriggerType::Tag->value)
            ->where('active', true)
            ->get();

        foreach ($triggers as $trigger) {
            $config = is_array($trigger->config) ? $trigger->config : [];
            $tagNames = $config['tags'] ?? [];

            if (! is_array($tagNames) || ! in_array($event->tagName, $tagNames, true)) {
                continue;
            }

            dispatch((new StartFlowFromTag(
                triggerId: $trigger->id,
                contactId: $event->contactId,
                tagName: $event->tagName,
            ))->forTenant($event->tenantId))->onQueue('default');
        }
    }
}
