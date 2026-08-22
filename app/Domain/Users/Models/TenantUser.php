<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot usuario <-> tenant con rol por tenant.
 *
 * `tenant_id` es un UUID que referencia `tenants.id`. `role` es uno de los
 * roles específicos de tenant (owner/admin/agent). `status` refleja si el
 * usuario ya es miembro activo o todavía es una invitación en curso
 * (ADR-026/027). La fuente de verdad del rol es `tenant_users.role`.
 *
 * @property-read UserRole $role
 * @property-read TenantMembershipStatus $status
 * @property-read User $user
 */
class TenantUser extends Model
{
    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'status',
        'email_notifications_enabled',
        'invited_at',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'string',
            'role' => UserRole::class,
            'status' => TenantMembershipStatus::class,
            'email_notifications_enabled' => 'boolean',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
