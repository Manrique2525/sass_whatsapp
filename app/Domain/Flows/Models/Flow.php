<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Flujo del chatbot (FASE 11, ADR-034).
 *
 * NO existe `flow_versions`: la fila es la versión; solo `published` se
 * ejecuta. `config` es JSON libre del motor (p. ej. `max_steps`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $chatbot_id
 * @property string $name
 * @property string|null $description
 * @property FlowStatus $status
 * @property array<string, mixed>|null $config
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Flow extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'chatbot_id',
        'name',
        'description',
        'status',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'status' => FlowStatus::class,
            'config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Chatbot, $this>
     */
    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    /**
     * @return HasMany<FlowNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class);
    }

    /**
     * Nodo de entrada del flujo (único, reforzado por el UNIQUE parcial).
     *
     * @return HasOne<FlowNode, $this>
     */
    public function startNode(): HasOne
    {
        return $this->hasOne(FlowNode::class)->where('is_start', true);
    }

    /**
     * @return HasMany<FlowConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(FlowConnection::class);
    }

    /**
     * @return HasMany<Trigger, $this>
     */
    public function triggers(): HasMany
    {
        return $this->hasMany(Trigger::class);
    }

    /**
     * @return HasMany<FlowExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(FlowExecution::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FlowStatus::Published->value);
    }
}
