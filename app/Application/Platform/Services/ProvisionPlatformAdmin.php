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

    /**
     * Provisions the deterministic local-only development administrator.
     *
     * @return array{user: User, created: bool}
     */
    public function provisionLocal(string $name, string $email, string $password): array
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('This operation may only run in the local environment.');
        }

        return DB::transaction(function () use ($name, $email, $password): array {
            $email = mb_strtolower(trim($email));
            $user = User::query()->where('email', $email)->first();
            $created = $user === null;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $email,
                    'password' => $password,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } else {
                $user->forceFill([
                    'name' => trim($name),
                    'password' => $password,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            }

            $this->roles->assignGlobalRole($user, UserRole::SuperAdmin);
            $this->audit->record(
                action: 'platform.admin.local_provisioned',
                data: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'environment' => 'local',
                    'source' => 'artisan',
                    'created' => $created,
                ],
                subjectType: User::class,
                subjectId: $user->id,
                actorUserId: null,
                tenantId: null,
                platform: true,
            );

            return ['user' => $user->fresh() ?? $user, 'created' => $created];
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
