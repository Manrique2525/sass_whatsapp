<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Contacts\Services\ContactConversationResolver;
use App\Application\Flows\Services\FlowEngine;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
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
 * Ejecuta un trigger tag (FASE 20, UNIDAD 4, ADR-050).
 *
 * Despachado por `DispatchTagTriggerJob` al escuchar `TagAssigned`. Revalida
 * todas las condiciones del trigger (defensa en profundidad), resuelve la
 * conversación más reciente del contacto, adquiere un lock Redis por trigger
 * para evitar doble disparo, y delega al FlowEngine vía handleScheduleTrigger()
 * que aplica conversationLock, bot_paused, ejecución activa y publicación.
 *
 * Anti-recursión: el listener (`DispatchTagTriggerJob`) descarta eventos con
 * origin=Flow antes de despachar este job, evitando cadenas tag→flow→tag.
 *
 * Capas de protección contra duplicación:
 * 1. Listener anti-recursión (origin=Flow → skip).
 * 2. ShouldBeUnique por tag-trigger:{contactId}:{triggerId}:{tagId} (cola).
 * 3. Cache::lock por trigger (runtime, dentro del job).
 * 4. FlowEngine::conversationLock (runtime, dentro del motor).
 * 5. FlowExecutionService::findActive (no crea si hay ejecución activa).
 * 6. UNIQUE parcial en flow_executions (barrera DB).
 */
final class StartFlowFromTag implements ShouldBeUnique, ShouldQueue
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
        public readonly string $contactId,
        public readonly string $tagName,
    ) {}

    public function uniqueId(): string
    {
        return "tag-trigger:{$this->contactId}:{$this->triggerId}:{$this->tagName}";
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

        if ($trigger->type !== FlowTriggerType::Tag) {
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
        $tagNames = $config['tags'] ?? [];

        if (! is_array($tagNames) || ! in_array($this->tagName, $tagNames, true)) {
            return;
        }

        $contact = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->contactId)
            ->first();

        if ($contact === null) {
            return;
        }

        $conversation = $this->resolveConversation($tenant, $contact);

        if ($conversation === null) {
            return;
        }

        $lock = Cache::lock("lock:tag:trigger:{$trigger->id}", 30);

        if (! $lock->get()) {
            return;
        }

        try {
            if (! app(FlowEngine::class)->handleScheduleTrigger($tenant, $flow, $conversation)) {
                return;
            }

            app(AuditLogger::class)->record(
                action: 'flow.tag_triggered',
                data: [
                    'trigger_id' => $trigger->id,
                    'flow_id' => $flow->id,
                    'conversation_id' => $conversation->id,
                    'tag_name' => $this->tagName,
                ],
                subjectType: Trigger::class,
                subjectId: $trigger->id,
                tenantId: $this->tenantId,
            );
        } finally {
            $lock->release();
        }
    }

    private function resolveConversation(Tenant $tenant, Contact $contact): ?Conversation
    {
        return app(ContactConversationResolver::class)
            ->resolveForTagAssignment($tenant, $contact);
    }
}
