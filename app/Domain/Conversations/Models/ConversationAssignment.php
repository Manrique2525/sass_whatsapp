<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asignación registrada de una conversación a un agente (FASE 8, ADR-031).
 *
 * Historial acumulativo: cada asignación/transferencia inserta una fila y
 * cierra la anterior (`unassigned_at`). `reason` distingue asignación manual,
 * transferencia o claim. `assigned_by` es el usuario que la realizó.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property int $agent_id
 * @property int|null $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $unassigned_at
 * @property string $reason
 */
class ConversationAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'conversation_id',
        'agent_id',
        'assigned_by',
        'assigned_at',
        'unassigned_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'string',
            'conversation_id' => 'string',
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
