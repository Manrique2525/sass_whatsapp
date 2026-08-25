<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Billing\Contracts\CapacityCheckInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Exceptions\InvitationAlreadyAcceptedException;
use App\Domain\Users\Exceptions\InvitationAlreadyPendingException;
use App\Domain\Users\Exceptions\InvitationEmailMismatchException;
use App\Domain\Users\Exceptions\InvitationExpiredException;
use App\Domain\Users\Exceptions\InvitationNotFoundException;
use App\Domain\Users\Exceptions\InvitationNotPendingException;
use App\Domain\Users\Exceptions\InvitationRevokedException;
use App\Domain\Users\Exceptions\MemberAlreadyExistsException;
use App\Domain\Users\Exceptions\RoleChangeNotAllowedException;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Ciclo de vida de las invitaciones a tenants (ADR-027).
 *
 * - El token real solo viaja en el enlace (email); en BD solo `token_hash`.
 * - Transiciones de estado: pending → accepted | revoked | expired (no reuso).
 * - La aceptación exige un usuario autenticado con el email de la invitación;
 *   materializa la membresía ACTIVA y el rol spatie espejo.
 */
final class InvitationService
{
    public const INVITATION_TTL_DAYS = 7;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantRoleManager $roleManager,
        private readonly AuditLogger $auditLogger,
        private readonly CapacityGuardInterface $capacityGuard,
    ) {}

    /**
     * Autoriza listar las invitaciones del tenant (para la vista de usuarios).
     */
    public function authorizeList(User $actor, Tenant $tenant): void
    {
        $this->authorization->authorize($actor, TenantPermission::ViewUsers, $tenant);
    }

    public function invite(User $actor, Tenant $tenant, string $email, UserRole $role): TenantInvitation
    {
        $this->authorization->authorize($actor, TenantPermission::InviteUsers, $tenant);

        $this->assertAssignableRole($actor, $tenant, $role);

        $email = mb_strtolower(trim($email));

        $this->assertCanInviteEmail($tenant, $email);

        $token = Str::random(64);

        $invitation = $this->capacityGuard->withinLock(
            $tenant,
            UsageCategory::Users,
            function (CapacityCheckInterface $capacity) use ($tenant, $email, $role, $token, $actor): TenantInvitation {
                $this->assertCanInviteEmail($tenant, $email);
                $capacity->assertCanCreate();

                return TenantInvitation::query()->create([
                    'tenant_id' => $tenant->id,
                    'email' => $email,
                    'role' => $role,
                    'token_hash' => hash('sha256', $token),
                    'invited_by' => $actor->id,
                    'status' => InvitationStatus::Pending,
                    'expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
                ]);
            },
        );

        $this->sendNotification($invitation, $token);

        $this->auditLogger->record(
            action: 'user.invited',
            data: [
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $role->value,
            ],
            subjectType: TenantInvitation::class,
            subjectId: $invitation->id,
            actorUserId: $actor->id,
            tenantId: $tenant->id,
        );

        return $invitation;
    }

    /**
     * Datos públicos de una invitación por su token (para la página de
     * aceptación). Lanza las excepciones de estado correspondientes.
     */
    public function findForToken(string $token): TenantInvitation
    {
        $invitation = TenantInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null) {
            throw new InvitationNotFoundException('Invitación no encontrada.');
        }

        return match ($invitation->status) {
            InvitationStatus::Accepted => throw new InvitationAlreadyAcceptedException('La invitación ya fue aceptada.'),
            InvitationStatus::Revoked => throw new InvitationRevokedException('La invitación fue revocada.'),
            InvitationStatus::Expired => throw new InvitationExpiredException('La invitación expiró.'),
            InvitationStatus::Pending => $this->markIfExpired($invitation),
        };
    }

    public function accept(User $user, string $token): TenantInvitation
    {
        $invitation = $this->findForToken($token);

        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw new InvitationEmailMismatchException('La invitación no corresponde a tu email.');
        }

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        return $this->capacityGuard->withinLock(
            $tenant,
            UsageCategory::Users,
            function (CapacityCheckInterface $capacity) use ($invitation, $user, $tenant): TenantInvitation {
                $lockedInvitation = TenantInvitation::query()
                    ->whereKey($invitation->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedInvitation === null) {
                    throw new InvitationNotFoundException('Invitación no encontrada.');
                }

                $lockedInvitation = $this->assertInvitationUsable($lockedInvitation);

                if (mb_strtolower($user->email) !== mb_strtolower($lockedInvitation->email)) {
                    throw new InvitationEmailMismatchException('La invitación no corresponde a tu email.');
                }

                $membership = TenantUser::query()
                    ->where('tenant_id', $lockedInvitation->tenant_id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($membership === null || $membership->status !== TenantMembershipStatus::Active) {
                    $capacity->assertCanCreate();
                }

                if ($membership === null) {
                    TenantUser::query()->create([
                        'tenant_id' => $lockedInvitation->tenant_id,
                        'user_id' => $user->id,
                        'role' => $lockedInvitation->role,
                        'status' => TenantMembershipStatus::Active,
                        'joined_at' => now(),
                    ]);
                } else {
                    $membership->forceFill([
                        'role' => $lockedInvitation->role,
                        'status' => TenantMembershipStatus::Active,
                        'joined_at' => now(),
                    ])->save();
                }

                $lockedInvitation->forceFill([
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                ])->save();

                $this->roleManager->syncRoles($user, $tenant, $lockedInvitation->role);

                $this->auditLogger->record(
                    action: 'user.invitation_accepted',
                    data: [
                        'tenant_id' => $lockedInvitation->tenant_id,
                        'invitation_id' => $lockedInvitation->id,
                        'role' => $lockedInvitation->role->value,
                    ],
                    subjectType: TenantInvitation::class,
                    subjectId: $lockedInvitation->id,
                    actorUserId: $user->id,
                    tenantId: $lockedInvitation->tenant_id,
                );

                return $lockedInvitation->fresh();
            },
        );
    }

    public function revoke(User $actor, Tenant $tenant, TenantInvitation $invitation): void
    {
        $this->authorization->authorize($actor, TenantPermission::InviteUsers, $tenant);

        $this->assertBelongsToTenant($tenant, $invitation);

        if ($invitation->status !== InvitationStatus::Pending) {
            throw new InvitationNotPendingException('La invitación ya no está pendiente.');
        }

        $invitation->forceFill(['status' => InvitationStatus::Revoked])->save();

        $this->auditLogger->record(
            action: 'user.invitation_revoked',
            data: [
                'tenant_id' => $tenant->id,
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
            ],
            subjectType: TenantInvitation::class,
            subjectId: $invitation->id,
            actorUserId: $actor->id,
            tenantId: $tenant->id,
        );
    }

    public function resend(User $actor, Tenant $tenant, TenantInvitation $invitation): void
    {
        $this->authorization->authorize($actor, TenantPermission::InviteUsers, $tenant);

        $this->assertBelongsToTenant($tenant, $invitation);

        if ($invitation->status !== InvitationStatus::Pending) {
            throw new InvitationNotPendingException('La invitación ya no está pendiente.');
        }

        if (! $invitation->expires_at->isFuture()) {
            throw new InvitationExpiredException('La invitación expiró.');
        }

        $token = Str::random(64);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
        ])->save();

        $this->sendNotification($invitation, $token);

        $this->auditLogger->record(
            action: 'user.invitation_resent',
            data: [
                'tenant_id' => $tenant->id,
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
            ],
            subjectType: TenantInvitation::class,
            subjectId: $invitation->id,
            actorUserId: $actor->id,
            tenantId: $tenant->id,
        );
    }

    private function sendNotification(TenantInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($invitation, $token));
    }

    private function markIfExpired(TenantInvitation $invitation): TenantInvitation
    {
        if (! $invitation->expires_at->isFuture()) {
            $invitation->forceFill(['status' => InvitationStatus::Expired])->save();

            throw new InvitationExpiredException('La invitación expiró.');
        }

        return $invitation;
    }

    private function assertInvitationUsable(TenantInvitation $invitation): TenantInvitation
    {
        return match ($invitation->status) {
            InvitationStatus::Accepted => throw new InvitationAlreadyAcceptedException('La invitación ya fue aceptada.'),
            InvitationStatus::Revoked => throw new InvitationRevokedException('La invitación fue revocada.'),
            InvitationStatus::Expired => throw new InvitationExpiredException('La invitación expiró.'),
            InvitationStatus::Pending => $invitation->expires_at->isFuture()
                ? $invitation
                : throw new InvitationExpiredException('La invitación expiró.'),
        };
    }

    private function assertCanInviteEmail(Tenant $tenant, string $email): void
    {
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null && $existingUser->belongsToTenant($tenant)) {
            throw new MemberAlreadyExistsException('El usuario ya es miembro del tenant.');
        }

        if (TenantInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending)
            ->exists()) {
            throw new InvitationAlreadyPendingException('Ya existe una invitación pendiente para este email.');
        }
    }

    private function assertBelongsToTenant(Tenant $tenant, TenantInvitation $invitation): void
    {
        if ($invitation->tenant_id !== $tenant->id) {
            throw new InvitationNotFoundException('Invitación no encontrada.');
        }
    }

    private function assertAssignableRole(User $actor, Tenant $tenant, UserRole $role): void
    {
        if (! in_array($role, UserRole::assignableTenantRoles(), true)) {
            throw new RoleChangeNotAllowedException('El rol no es invitables.');
        }

        $actorRole = $actor->roleForTenant($tenant->id);

        if ($actorRole === UserRole::Owner) {
            return;
        }

        // admin (sin AssignRoles, pero defensivo): solo invita agents.
        if ($actorRole === UserRole::Admin && $role === UserRole::Agent) {
            return;
        }

        throw new RoleChangeNotAllowedException('Tu rol no permite invitar con ese rol.');
    }
}
