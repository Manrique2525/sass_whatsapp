<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invitación a un tenant (ADR-027).
 *
 * Solo se persiste `token_hash` (sha256); el token plano solo viaja en el
 * enlace de invitación. El `status` garantiza no-reutilización (pending es la
 * única transición válida hacia accepted/revoked/expired).
 *
 * @property-read UserRole $role
 * @property-read InvitationStatus $status
 * @property-read Carbon $expires_at
 * @property-read Tenant $tenant
 */
class TenantInvitation extends Model
{
    protected $table = 'tenant_invitations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'status',
        'expires_at',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'string',
            'role' => UserRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->id = (string) Str::uuid();
        });
    }

    /**
     * ¿La invitación sigue siendo utilizable (pendiente y no expirada)?
     */
    public function isUsable(): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
