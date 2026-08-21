<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\Users\Models\User;
use Database\Factories\Domain\Notifications\Models\NotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-scoped notification (FASE 22 U1, ADR-082).
 *
 * Persistent in-app notifications. Supports both targeted (user_id != NULL)
 * and tenant-wide (user_id = NULL) notifications.
 *
 * Privacy: title/body are plain text only. data JSON contains ONLY safe
 * metadata (conversation_id, agent_id, event type, route hints).
 * NO PII: no phone, email, message body, AI prompt/response, API keys.
 *
 * Soft deletes: user can dismiss without losing audit trail.
 * user_id FK: SET NULL on user delete (preserve history).
 * tenant_id FK: CASCADE on tenant delete.
 */
final class Notification extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'priority',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'priority' => NotificationPriority::class,
            'data' => 'array',
            'read_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}
