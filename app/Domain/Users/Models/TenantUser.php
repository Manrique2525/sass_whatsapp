<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Users\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot usuario <-> tenant con rol por tenant.
 *
 * `tenant_id` referencia `tenants.id` (la FK se añade en FASE 3 cuando exista
 * la tabla `tenants`). `role` es uno de los roles específicos de tenant.
 */
class TenantUser extends Model
{
    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'role' => UserRole::class,
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
