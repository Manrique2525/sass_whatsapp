<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final class PlatformCustomerOperations
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function changeStatus(User $actor, Tenant $tenant, TenantStatus $status, string $reason): Tenant
    {
        return DB::transaction(function () use ($actor, $tenant, $status, $reason): Tenant {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if ($previous !== $status) {
                $locked->forceFill(['status' => $status])->save();
                $this->audit->record(
                    action: $status === TenantStatus::Suspended ? 'platform.tenant.suspended' : 'platform.tenant.reactivated',
                    data: ['tenant_id' => $locked->id, 'reason' => trim($reason), 'source' => 'platform_backoffice'],
                    subjectType: Tenant::class,
                    subjectId: $locked->id,
                    actorUserId: $actor->id,
                    platform: true,
                );
            }

            return $locked->fresh();
        });
    }

    public function resendOwnerVerification(User $actor, Tenant $tenant): void
    {
        $owner = $this->owner($tenant);
        if ($owner->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['owner' => 'The owner email is already verified.']);
        }

        $owner->sendEmailVerificationNotification();
        $this->audit->record(
            action: 'platform.owner.verification_resent',
            data: ['tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'source' => 'platform_backoffice'],
            subjectType: User::class,
            subjectId: $owner->id,
            actorUserId: $actor->id,
            platform: true,
        );
    }

    public function sendOwnerPasswordReset(User $actor, Tenant $tenant): void
    {
        $owner = $this->owner($tenant);
        Password::sendResetLink(['email' => $owner->email]);
        $this->audit->record(
            action: 'platform.owner.password_reset_sent',
            data: ['tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'source' => 'platform_backoffice'],
            subjectType: User::class,
            subjectId: $owner->id,
            actorUserId: $actor->id,
            platform: true,
        );
    }

    public function createFreeSubscription(User $actor, Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($actor, $tenant): Subscription {
            $free = Plan::query()->where('slug', 'free')->where('is_active', true)->first();
            if ($free === null) {
                throw ValidationException::withMessages(['subscription' => 'The canonical Free plan is unavailable.']);
            }

            $existing = Subscription::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->whereNull('deleted_at')->latest()->first();
            if ($existing !== null) {
                return $existing;
            }

            $subscription = TenantContext::withId($tenant->id, fn (): Subscription => Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $free->id,
                'status' => SubscriptionStatus::Active,
                'quantity' => 1,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->addMonth()->startOfMonth(),
            ]));
            $tenant->forceFill(['plan_id' => $free->id])->save();
            $this->audit->record(
                action: 'platform.subscription.free_created',
                data: ['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'plan_id' => $free->id, 'source' => 'platform_backoffice'],
                subjectType: Subscription::class,
                subjectId: $subscription->id,
                actorUserId: $actor->id,
                platform: true,
            );

            return $subscription->fresh('plan');
        });
    }

    private function owner(Tenant $tenant): User
    {
        /** @var User|null $owner */
        $owner = $tenant->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->orderBy('users.id')->first();
        if ($owner === null) {
            throw ValidationException::withMessages(['owner' => 'This tenant has no active owner.']);
        }

        return $owner;
    }
}
