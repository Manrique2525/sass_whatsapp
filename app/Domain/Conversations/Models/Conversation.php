<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Models;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Conversación del inbox del tenant (FASE 8, ADR-031).
 *
 * Nace `open` y sin agente asignado. `agent_id` es la asignación vigente
 * (el historial completo vive en `conversation_assignments`). `context` es
 * JSON libre que el motor de flujos podrá usar (FASE 10+). `tenant_id` lo
 * gestiona `BelongsToTenant`; nunca se acepta del frontend.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property ConversationStatus $status
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_interaction_at
 * @property int|null $agent_id
 * @property bool $auto_assigned
 * @property bool $bot_paused
 * @property array<string, mixed>|null $context
 * @property string|null $flow_execution_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Conversation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'contact_id',
        'status',
        'last_message_at',
        'last_interaction_at',
        'agent_id',
        'auto_assigned',
        'bot_paused',
        'context',
        'flow_execution_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'auto_assigned' => 'boolean',
            'bot_paused' => 'boolean',
            'context' => 'array',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Agente con la asignación vigente (puede estar null si no está asignada).
     *
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<ConversationAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Último mensaje de la conversación (preview en la lista del inbox, FASE 10).
     *
     * @return HasOne<Message, $this>
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
