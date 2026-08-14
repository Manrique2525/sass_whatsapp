<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Exceptions\ChatbotHasPublishedFlowsException;
use App\Domain\Flows\Exceptions\ChatbotNotFoundException;
use App\Domain\Flows\Exceptions\FlowAlreadyPublishedException;
use App\Domain\Flows\Exceptions\FlowConflictException;
use App\Domain\Flows\Exceptions\FlowInvalidException;
use App\Domain\Flows\Exceptions\FlowInvalidStateException;
use App\Domain\Flows\Exceptions\FlowNotFoundException;
use App\Domain\Flows\Exceptions\FlowPublishedException;
use App\Domain\Flows\Exceptions\TriggerNotFoundException;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Flows\Services\FlowValidator;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Casos de uso de chatbots y flujos (FASE 11, ADR-035).
 *
 * Invariantes:
 * - Chatbot/Flow/Trigger se resuelven SIN el scope global (`withoutTenantScope`)
 *   filtrando SIEMPRE por `tenant_id` del tenant autorizado: el 404 oculta la
 *   existencia cross-tenant (ADR-010/023). Nada usa route-model binding.
 * - `tenant_id` nunca viene del frontend (lo fija `BelongsToTenant`).
 * - Un flujo `published` NO se puede editar (nodes/connections/metadata):
 *   primero hay que deactivarlo (`409 FLOW_PUBLISHED`). Publicar valida el
 *   grafo (`FlowValidator`); flujo inválido jamás se publica (`422 FLOW_INVALID`).
 * - El contenido del borrador se reemplaza de forma atómica (transacción):
 *   PATCH del flujo recibe `nodes[]` + `connections[]` completos.
 * - Los triggers usan los ids de nodo del cliente (UUID v4); `tenant_id` lo
 *   fija el modelo.
 * - Toda mutación queda auditada (AuditLogger).
 */
final class FlowService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly FlowValidator $validator,
        private readonly ConnectionInterface $db,
    ) {}

    // ---------------------------------------------------------------- Chatbots

    /**
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Chatbot>
     */
    public function indexChatbots(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        $query = Chatbot::query()->withCount('flows');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function showChatbot(User $user, Tenant $tenant, string $chatbotId): Chatbot
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        return $this->findChatbotForTenant($tenant, $chatbotId)
            ->load('flows');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createChatbot(User $user, Tenant $tenant, array $validated): Chatbot
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $chatbot = Chatbot::query()->create([
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $this->auditLogger->record(
            action: 'flow.chatbot_created',
            data: ['tenant_id' => $tenant->id],
            subjectType: Chatbot::class,
            subjectId: $chatbot->id,
        );

        return $chatbot;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateChatbot(User $user, Tenant $tenant, string $chatbotId, array $validated): Chatbot
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $chatbot = $this->findChatbotForTenant($tenant, $chatbotId);

        $changed = [];

        if (array_key_exists('name', $validated)) {
            $chatbot->name = (string) $validated['name'];
            $changed[] = 'name';
        }

        if (array_key_exists('description', $validated)) {
            $chatbot->description = $validated['description'] === null ? null : (string) $validated['description'];
            $changed[] = 'description';
        }

        if ($changed === []) {
            return $chatbot;
        }

        $chatbot->save();

        $this->auditLogger->record(
            action: 'flow.chatbot_updated',
            data: ['tenant_id' => $tenant->id, 'changed' => $changed],
            subjectType: Chatbot::class,
            subjectId: $chatbot->id,
        );

        return $chatbot;
    }

    public function deleteChatbot(User $user, Tenant $tenant, string $chatbotId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $chatbot = $this->findChatbotForTenant($tenant, $chatbotId);

        $hasPublished = Flow::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('chatbot_id', $chatbot->id)
            ->where('status', FlowStatus::Published->value)
            ->exists();

        if ($hasPublished) {
            throw new ChatbotHasPublishedFlowsException(
                'No se puede eliminar el chatbot: tiene flujos publicados (deactivarlos primero).',
            );
        }

        $chatbot->delete();

        $this->auditLogger->record(
            action: 'flow.chatbot_deleted',
            data: ['tenant_id' => $tenant->id],
            subjectType: Chatbot::class,
            subjectId: $chatbot->id,
        );
    }

    // ------------------------------------------------------------------ Flows

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Flow>
     */
    public function indexFlows(User $user, Tenant $tenant, string $chatbotId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        $this->findChatbotForTenant($tenant, $chatbotId);

        $query = Flow::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('chatbot_id', $chatbotId)
            ->withCount('triggers');

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function showFlow(User $user, Tenant $tenant, string $flowId): Flow
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        return $this->findFlowForTenant($tenant, $flowId)
            ->load(['nodes', 'connections', 'triggers', 'chatbot']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFlow(User $user, Tenant $tenant, string $chatbotId, array $validated): Flow
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $this->findChatbotForTenant($tenant, $chatbotId);

        $flow = Flow::query()->create([
            'chatbot_id' => $chatbotId,
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => FlowStatus::Draft,
            'config' => $validated['config'] ?? null,
        ]);

        $this->auditLogger->record(
            action: 'flow.created',
            data: ['tenant_id' => $tenant->id, 'chatbot_id' => $chatbotId],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );

        return $flow;
    }

    /**
     * Actualización de metadatos (name/description/config). Un flujo publicado
     * no se edita: 409 FLOW_PUBLISHED (deactivar primero).
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateFlow(User $user, Tenant $tenant, string $flowId, array $validated): Flow
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $changed = [];

        if (array_key_exists('name', $validated)) {
            $flow->name = (string) $validated['name'];
            $changed[] = 'name';
        }

        if (array_key_exists('description', $validated)) {
            $flow->description = $validated['description'] === null ? null : (string) $validated['description'];
            $changed[] = 'description';
        }

        if (array_key_exists('config', $validated)) {
            $flow->config = $validated['config'] === null ? null : (array) $validated['config'];
            $changed[] = 'config';
        }

        if ($changed === []) {
            return $flow;
        }

        $flow->save();

        $this->auditLogger->record(
            action: 'flow.updated',
            data: ['tenant_id' => $tenant->id, 'changed' => $changed],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );

        return $flow;
    }

    /**
     * Reemplazo atómico del grafo del borrador (nodes[] + connections[]).
     * El flujo inválido jamás persiste (422 FLOW_INVALID); la transacción
     * garantiza que nunca queda un grafo a medias.
     *
     * Lock optimista (FASE 12, ADR-042): si `$baseUpdatedAt` (ISO 8601) es
     * anterior a `flow.updated_at`, otro usuario modificó el flujo después de
     * que este cliente lo cargó → `409 FLOW_CONFLICT`. Nunca se sobrescribe en
     * silencio: el cliente decide recargar/sobrescribir explícitamente.
     *
     * Los secretos del nodo webhook (headers/payload) viven SOLO en el backend
     * (ADR-044): cuando el payload del editor las omite se preservan los
     * valores persistidos; si el payload las incluye explícitamente, ganan.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $connections
     */
    public function replaceDraft(
        User $user,
        Tenant $tenant,
        string $flowId,
        array $nodes,
        array $connections,
        ?string $baseUpdatedAt = null,
    ): Flow {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $this->assertVersion($flow, $baseUpdatedAt);

        $existingNodes = $flow->nodes()->get()->keyBy('id');

        $nodeModels = $this->buildNodeModels($nodes, $existingNodes);
        $connectionModels = $this->buildConnectionModels($connections);

        $errors = $this->validator->validate($nodeModels, $connectionModels, $flow->config);

        if ($errors !== []) {
            throw new FlowInvalidException('El flujo no es válido y no puede guardarse.', $errors);
        }

        $this->db->transaction(function () use ($flow, $nodeModels, $connectionModels): void {
            FlowConnection::query()
                ->where('flow_id', $flow->id)
                ->delete();

            FlowNode::query()
                ->where('flow_id', $flow->id)
                ->delete();

            foreach ($nodeModels as $node) {
                $node->flow_id = $flow->id;
                $node->save();
            }

            foreach ($connectionModels as $connection) {
                $connection->flow_id = $flow->id;
                $connection->save();
            }

            $flow->touch();
        });

        $this->auditLogger->record(
            action: 'flow.draft_updated',
            data: ['tenant_id' => $tenant->id, 'nodes' => count($nodeModels), 'connections' => count($connectionModels)],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );

        return $flow->load(['nodes', 'connections']);
    }

    /**
     * Valida el grafo persistido del flujo (sin mutar nada). Devuelve el
     * resultado del `FlowValidator`: `['valid' => true, 'errors' => []]` o
     * `['valid' => false, 'errors' => [...]]`.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateFlow(User $user, Tenant $tenant, string $flowId): array
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId)
            ->load(['nodes', 'connections']);

        $errors = $this->validator->validate($flow->nodes, $flow->connections, $flow->config);

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function publish(User $user, Tenant $tenant, string $flowId): Flow
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        if ($flow->status === FlowStatus::Published) {
            throw new FlowAlreadyPublishedException('El flujo ya está publicado.');
        }

        $flow->load(['nodes', 'connections']);

        $errors = $this->validator->validate($flow->nodes, $flow->connections, $flow->config);

        if ($errors !== []) {
            throw new FlowInvalidException('El flujo no es válido y no puede publicarse.', $errors);
        }

        $flow->forceFill(['status' => FlowStatus::Published])->save();

        $this->auditLogger->record(
            action: 'flow.published',
            data: ['tenant_id' => $tenant->id],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );

        return $flow;
    }

    public function deactivate(User $user, Tenant $tenant, string $flowId): Flow
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        if ($flow->status !== FlowStatus::Published) {
            throw new FlowInvalidStateException(sprintf(
                'No se puede deactivar un flujo en estado "%s": solo los publicados.',
                $flow->status->value,
            ));
        }

        $flow->forceFill(['status' => FlowStatus::Inactive])->save();

        $this->auditLogger->record(
            action: 'flow.deactivated',
            data: ['tenant_id' => $tenant->id],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );

        return $flow;
    }

    public function deleteFlow(User $user, Tenant $tenant, string $flowId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $flow->delete();

        $this->auditLogger->record(
            action: 'flow.deleted',
            data: ['tenant_id' => $tenant->id],
            subjectType: Flow::class,
            subjectId: $flow->id,
        );
    }

    // ---------------------------------------------------------------- Triggers

    /**
     * @return Collection<int, Trigger>
     */
    public function indexTriggers(User $user, Tenant $tenant, string $flowId): Collection
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        return $this->findFlowForTenant($tenant, $flowId)
            ->triggers()
            ->orderBy('priority')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createTrigger(User $user, Tenant $tenant, string $flowId, array $validated): Trigger
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $trigger = $flow->triggers()->create([
            'type' => FlowTriggerType::from((string) $validated['type']),
            'keyword' => $validated['keyword'] ?? null,
            'config' => $validated['config'] ?? null,
            'priority' => (int) ($validated['priority'] ?? 0),
            'active' => (bool) ($validated['active'] ?? true),
        ]);

        $this->auditLogger->record(
            action: 'flow.trigger_created',
            data: ['tenant_id' => $tenant->id, 'flow_id' => $flowId],
            subjectType: Trigger::class,
            subjectId: $trigger->id,
        );

        return $trigger;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateTrigger(User $user, Tenant $tenant, string $flowId, string $triggerId, array $validated): Trigger
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $trigger = $this->findTriggerForTenant($tenant, $triggerId, $flowId);

        $changed = [];

        if (array_key_exists('type', $validated)) {
            $trigger->type = FlowTriggerType::from((string) $validated['type']);
            $changed[] = 'type';
        }

        if (array_key_exists('keyword', $validated)) {
            $trigger->keyword = $validated['keyword'] === null ? null : (string) $validated['keyword'];
            $changed[] = 'keyword';
        }

        if (array_key_exists('config', $validated)) {
            $trigger->config = $validated['config'] === null ? null : (array) $validated['config'];
            $changed[] = 'config';
        }

        if (array_key_exists('priority', $validated)) {
            $trigger->priority = (int) $validated['priority'];
            $changed[] = 'priority';
        }

        if (array_key_exists('active', $validated)) {
            $trigger->active = (bool) $validated['active'];
            $changed[] = 'active';
        }

        if ($changed === []) {
            return $trigger;
        }

        $trigger->save();

        $this->auditLogger->record(
            action: 'flow.trigger_updated',
            data: ['tenant_id' => $tenant->id, 'flow_id' => $flowId, 'changed' => $changed],
            subjectType: Trigger::class,
            subjectId: $trigger->id,
        );

        return $trigger;
    }

    public function deleteTrigger(User $user, Tenant $tenant, string $flowId, string $triggerId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $flow = $this->findFlowForTenant($tenant, $flowId);

        $this->assertEditable($flow);

        $trigger = $this->findTriggerForTenant($tenant, $triggerId, $flowId);

        $trigger->delete();

        $this->auditLogger->record(
            action: 'flow.trigger_deleted',
            data: ['tenant_id' => $tenant->id, 'flow_id' => $flowId],
            subjectType: Trigger::class,
            subjectId: $trigger->id,
        );
    }

    // ------------------------------------------------------------- Internals

    private function assertEditable(Flow $flow): void
    {
        if ($flow->status === FlowStatus::Published) {
            throw new FlowPublishedException(
                'El flujo está publicado: deactivarlo antes de editarlo.',
            );
        }
    }

    private function findChatbotForTenant(Tenant $tenant, string $chatbotId): Chatbot
    {
        $chatbot = Chatbot::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($chatbotId)
            ->first();

        if ($chatbot === null) {
            throw new ChatbotNotFoundException;
        }

        return $chatbot;
    }

    private function findFlowForTenant(Tenant $tenant, string $flowId): Flow
    {
        $flow = Flow::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($flowId)
            ->first();

        if ($flow === null) {
            throw new FlowNotFoundException;
        }

        return $flow;
    }

    private function findTriggerForTenant(Tenant $tenant, string $triggerId, string $flowId): Trigger
    {
        $trigger = Trigger::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($triggerId)
            ->where('flow_id', $flowId)
            ->first();

        if ($trigger === null) {
            throw new TriggerNotFoundException;
        }

        return $trigger;
    }

    /**
     * Lock optimista: `base_updated_at` opcional enviado por el editor. Si el
     * flujo fue tocado después de esa marca de tiempo, hay un conflicto de
     * escritura concurrente. El campo es opcional para mantener compatibilidad
     * con clientes que no usan el editor (backward compatible).
     */
    private function assertVersion(Flow $flow, ?string $baseUpdatedAt): void
    {
        if ($baseUpdatedAt === null || trim($baseUpdatedAt) === '') {
            return;
        }

        $base = Carbon::parse($baseUpdatedAt);

        if ($flow->updated_at->gt($base)) {
            throw new FlowConflictException(
                'El flujo fue modificado por otro usuario mientras editabas. Recargá la versión actual antes de guardar (o sobrescribí explícitamente).',
            );
        }
    }

    /**
     * Preserva los secretos del nodo webhook (headers/payload) cuando el
     * payload del editor no los incluye. Solo aplica a nodos webhook ya
     * persistidos; si el editor envía valores explícitos, ganan.
     *
     * @param  array<string, mixed>|null  $config
     * @param  Collection<string, FlowNode>|null  $existingNodes
     * @return array<string, mixed>|null
     */
    private function preserveWebhookSecrets(string $nodeId, ?array $config, ?Collection $existingNodes): ?array
    {
        if ($config === null || $existingNodes === null) {
            return $config;
        }

        $existing = $existingNodes->get($nodeId);

        if ($existing === null || $existing->type !== FlowNodeType::Webhook) {
            return $config;
        }

        $stored = is_array($existing->config) ? $existing->config : [];

        foreach (['headers', 'payload'] as $secret) {
            if (! array_key_exists($secret, $config) && array_key_exists($secret, $stored)) {
                $config[$secret] = $stored[$secret];
            }
        }

        return $config;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  Collection<string, FlowNode>|null  $existingNodes
     * @return array<string, FlowNode>
     */
    private function buildNodeModels(array $nodes, ?Collection $existingNodes = null): array
    {
        $models = [];

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : null;

            if (($node['type'] ?? '') === FlowNodeType::Webhook->value) {
                $config = $this->preserveWebhookSecrets($id, $config, $existingNodes);
            }

            $model = new FlowNode([
                'type' => FlowNodeType::from((string) $node['type']),
                'name' => (string) ($node['name'] ?? $id),
                'position_x' => (int) ($node['position_x'] ?? 0),
                'position_y' => (int) ($node['position_y'] ?? 0),
                'config' => $config,
                'is_start' => (bool) ($node['is_start'] ?? false),
            ]);

            $model->id = $id;
            $model->exists = false;

            $models[$id] = $model;
        }

        return $models;
    }

    /**
     * @param  array<int, array<string, mixed>>  $connections
     * @return list<FlowConnection>
     */
    private function buildConnectionModels(array $connections): array
    {
        $models = [];

        foreach ($connections as $connection) {
            $source = (string) ($connection['source_node_id'] ?? '');
            $target = (string) ($connection['target_node_id'] ?? '');

            if ($source === '' || $target === '') {
                continue;
            }

            $model = new FlowConnection([
                'source_node_id' => $source,
                'target_node_id' => $target,
                'label' => isset($connection['label']) && $connection['label'] !== ''
                    ? (string) $connection['label']
                    : null,
            ]);

            $model->exists = false;

            $models[] = $model;
        }

        return $models;
    }
}
