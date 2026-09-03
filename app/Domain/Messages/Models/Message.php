<?php

declare(strict_types=1);

namespace App\Domain\Messages\Models;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Mensaje de una conversación (FASE 9, ADR-032).
 *
 * `tenant_id` lo gestiona `BelongsToTenant` (nunca llega del frontend).
 * La idempotencia del inbound la garantiza el índice UNIQUE parcial
 * `(tenant_id, provider_message_id)`; los status de Meta actualizan la fila
 * por `provider_message_id`, nunca crean mensajes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property int|null $sent_by_user_id
 * @property string|null $provider_message_id
 * @property MessageDirection $direction
 * @property MessageType $type
 * @property MessageStatus $status
 * @property string|null $body
 * @property string|null $media_url
 * @property string|null $media_mime
 * @property int|null $media_size
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Message extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'conversation_id',
        'provider_message_id',
        'direction',
        'type',
        'status',
        'body',
        'media_url',
        'media_mime',
        'media_size',
        'metadata',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'media_size' => 'integer',
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * Usuario autenticado que originó manualmente el mensaje. Inbound y bot
     * permanecen en null; la asignación de este actor se implementa en U3.
     *
     * @return BelongsTo<User, $this>
     */
    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /**
     * Asset de media del mensaje (FASE 31 U5); un mensaje tiene a lo sumo uno.
     *
     * @return HasOne<MessageMedia, $this>
     */
    public function media(): HasOne
    {
        return $this->hasOne(MessageMedia::class);
    }
}
