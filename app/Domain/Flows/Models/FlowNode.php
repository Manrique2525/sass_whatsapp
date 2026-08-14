<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Nodo de un flujo (FASE 11).
 *
 * `type` + `config` definen el comportamiento; `position_x`/`position_y` la
 * posición del editor (FASE 12). `is_start` marca el nodo de entrada (único
 * por flujo, UNIQUE parcial). El motor NUNCA modifica `config` de un nodo:
 * ejecuta desde los datos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property FlowNodeType $type
 * @property string $name
 * @property int $position_x
 * @property int $position_y
 * @property array<string, mixed>|null $config
 * @property bool $is_start
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FlowNode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'flow_id',
        'type',
        'name',
        'position_x',
        'position_y',
        'config',
        'is_start',
    ];

    protected function casts(): array
    {
        return [
            'type' => FlowNodeType::class,
            'position_x' => 'integer',
            'position_y' => 'integer',
            'config' => 'array',
            'is_start' => 'boolean',
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
     * Aristas que salen de este nodo (source).
     *
     * @return HasMany<FlowConnection, $this>
     */
    public function outgoingConnections(): HasMany
    {
        return $this->hasMany(FlowConnection::class, 'source_node_id');
    }

    /**
     * Aristas que llegan a este nodo (target).
     *
     * @return HasMany<FlowConnection, $this>
     */
    public function incomingConnections(): HasMany
    {
        return $this->hasMany(FlowConnection::class, 'target_node_id');
    }
}
