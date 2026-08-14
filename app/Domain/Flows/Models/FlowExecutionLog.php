<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Traza inmutable de una ejecución (FASE 11, ADR-036/037).
 *
 * Append-only (solo `created_at`): registra cada nodo visitado y evento del
 * motor (step_started, step_completed, waiting, sent, completed, failed,
 * retry, error). Base de auditoría/debugging del motor y del módulo de
 * auditoría (FASE 26).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $execution_id
 * @property string|null $node_id
 * @property string $event
 * @property array<string, mixed>|null $payload
 * @property int $sequence
 * @property Carbon $created_at
 */
final class FlowExecutionLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'node_id',
        'event',
        'payload',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FlowExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(FlowExecution::class, 'execution_id');
    }

    /**
     * @return BelongsTo<FlowNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'node_id');
    }
}
