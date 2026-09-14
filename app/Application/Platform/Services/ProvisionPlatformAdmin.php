<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Provisiona únicamente la identidad global de un Platform Super Admin.
 * No crea tenants ni credenciales MFA.
 */
final class ProvisionPlatformAdmin
{
    public function __construct(
        private readonly TenantRoleManager $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
            ]);

            $this->roles->assignGlobalRole($user, UserRole::SuperAdmin);
            $this->record('platform.admin.created', $user);

            return $user;
        });
    }

    public function promote(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $this->roles->assignGlobalRole($user, UserRole::SuperAdmin);
            $this->record('platform.admin.promoted', $user);

            return $user->fresh() ?? $user;
        });
    }

    private function record(string $action, User $user): void
    {
        $this->audit->record(
            action: $action,
            data: [
                'user_id' => $user->id,
                'email' => $user->email,
                'environment' => app()->environment(),
                'source' => 'artisan',
            ],
            subjectType: User::class,
            subjectId: $user->id,
            actorUserId: null,
            tenantId: null,
            platform: true,
        );
    }
}
