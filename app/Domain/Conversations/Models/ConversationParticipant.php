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
 * Participación de un usuario en una conversación (FASE 8, ADR-031).
 *
 * `role` replica el rol del usuario en el tenant al momento de participar.
 * El participante activo es `left_at IS NULL`; al transferir se marca
 * `left_at` y se da de alta el nuevo participante.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 */
class ConversationParticipant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'string',
            'conversation_id' => 'string',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
