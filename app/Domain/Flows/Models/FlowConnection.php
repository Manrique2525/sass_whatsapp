<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Arista dirigida de un flujo (FASE 11).
 *
 * `label` es el resultado de rama (`true`/`false` para condiciones); el resto
 * de nodos tienen una única arista saliente sin label (determinismo garantizado
 * por el `FlowValidator`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property string $source_node_id
 * @property string $target_node_id
 * @property string|null $label
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FlowConnection extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'flow_id',
        'source_node_id',
        'target_node_id',
        'label',
    ];

    /**
     * @return BelongsTo<Flow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /**
     * @return BelongsTo<FlowNode, $this>
     */
    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'source_node_id');
    }

    /**
     * @return BelongsTo<FlowNode, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'target_node_id');
    }
}
