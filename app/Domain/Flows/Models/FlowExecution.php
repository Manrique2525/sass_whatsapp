<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ejecución de un flujo para una conversación (FASE 11, ADR-037).
 *
 * Una sola ejecución activa (`running`/`waiting`) por conversación, reforzada
 * por el UNIQUE parcial. `current_node_id` es el nodo en curso; `variables`
 * persiste `{{custom.*}}` y respuestas de `question`; `last_inbound_message_id`
 * es la barrera de idempotencia del motor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property string $conversation_id
 * @property string|null $current_node_id
 * @property FlowExecutionStatus $status
 * @property array<string, mixed> $variables
 * @property int $attempts
 * @property string|null $last_inbound_message_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FlowExecution extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'flow_id',
        'conversation_id',
        'current_node_id',
        'status',
        'variables',
        'attempts',
        'last_inbound_message_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlowExecutionStatus::class,
            'variables' => 'array',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Flow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<FlowNode, $this>
     */
    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'current_node_id');
    }

    /**
     * @return HasMany<FlowExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(FlowExecutionLog::class, 'execution_id');
    }

    /**
     * Ejecuciones activas (running/waiting).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FlowExecutionStatus::Running->value,
            FlowExecutionStatus::Waiting->value,
        ]);
    }
}
