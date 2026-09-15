<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Tenants\Services\ProvisionNewWorkspace;
use App\Application\Users\Services\RegisterUser;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProvisionTenantCustomer
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly ProvisionNewWorkspace $workspace,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{tenant: Tenant, owner: User, created_owner: bool}
     */
    public function provision(
        User $actor,
        string $tenantName,
        string $ownerName,
        string $ownerEmail,
        bool $confirmExistingOwner = false,
    ): array {
        $ownerEmail = mb_strtolower(trim($ownerEmail));

        $result = DB::transaction(function () use ($actor, $tenantName, $ownerName, $ownerEmail, $confirmExistingOwner): array {
            $owner = User::query()->where('email', $ownerEmail)->first();
            $createdOwner = $owner === null;

            if ($owner !== null && ! $confirmExistingOwner) {
                throw ValidationException::withMessages([
                    'owner_email' => 'An account with this email already exists. Confirm using the existing account.',
                ]);
            }

            if ($owner === null) {
                // The owner establishes a known password through the reset email.
                $owner = $this->registerUser->register($ownerName, $ownerEmail, Str::random(64));
            }

            $tenant = $this->workspace->provision($owner, trim($tenantName));

            $this->audit->record(
                action: 'platform.customer.created',
                data: [
                    'tenant_id' => $tenant->id,
                    'owner_user_id' => $owner->id,
                    'plan_id' => $tenant->plan_id,
                    'source' => 'platform_backoffice',
                    'existing_owner' => ! $createdOwner,
                ],
                subjectType: Tenant::class,
                subjectId: $tenant->id,
                actorUserId: $actor->id,
                tenantId: null,
                platform: true,
            );

            return ['tenant' => $tenant->fresh(), 'owner' => $owner->fresh(), 'created_owner' => $createdOwner];
        });

        if ($result['created_owner']) {
            $result['owner']->sendEmailVerificationNotification();
            Password::sendResetLink(['email' => $result['owner']->email]);
        }

        return $result;
    }
}
