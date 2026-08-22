<?php

declare(strict_types=1);

namespace App\Application\Notifications\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;

/**
 * Servicio de preferencias de notificación por usuario+tenant (FASE 22 U4).
 *
 * La preferencia vive en el pivot `tenant_users.email_notifications_enabled`.
 * Cada usuario controla SI RECIBE email por tenant independiente.
 *
 * Default: false (sin contrato previo de email, evitar spam involuntario).
 */
final class NotificationPreferenceService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Lee la preferencia del usuario autenticado en el tenant.
     */
    public function get(User $user, Tenant $tenant): bool
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        $membership = $this->activeMembership($user, $tenant);

        if ($membership === null) {
            return false;
        }

        return $membership->email_notifications_enabled;
    }

    /**
     * Actualiza la preferencia del usuario autenticado en el tenant.
     */
    public function update(User $user, Tenant $tenant, bool $emailEnabled): bool
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        $membership = $this->activeMembership($user, $tenant);

        if ($membership === null) {
            return false;
        }

        $membership->update(['email_notifications_enabled' => $emailEnabled]);

        $this->auditLogger->record(
            action: 'notification_preferences.updated',
            data: [
                'email_notifications_enabled' => $emailEnabled,
            ],
            subjectType: TenantUser::class,
            subjectId: $membership->id,
            tenantId: $tenant->id,
        );

        return true;
    }

    /**
     * Verifica si el usuario tiene email habilitado para este tenant.
     *
     * Usado por listeners al momento de despachar mail.
     */
    public function isEmailEnabled(User $user, Tenant $tenant): bool
    {
        $membership = $this->activeMembership($user, $tenant);

        if ($membership === null) {
            return false;
        }

        return $membership->email_notifications_enabled;
    }

    private function activeMembership(User $user, Tenant $tenant): ?TenantUser
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}
