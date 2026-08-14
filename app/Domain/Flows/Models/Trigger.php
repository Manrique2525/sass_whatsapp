<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disparador de un flujo (FASE 11).
 *
 * FASE 11 usa `keyword`/`new_message`/`start`; `tag`/`schedule`/`webhook`
 * llegan en FASE 14. `keyword` guarda el patrón de disparo; `config` extras
 * futuros. `priority` ordena triggers del mismo tipo; `active` desactiva sin
 * eliminar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property FlowTriggerType $type
 * @property string|null $keyword
 * @property array<string, mixed>|null $config
 * @property int $priority
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Trigger extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'flow_id',
        'type',
        'keyword',
        'config',
        'priority',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => FlowTriggerType::class,
            'config' => 'array',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Flow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
