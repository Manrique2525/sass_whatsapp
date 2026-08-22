<?php

declare(strict_types=1);

namespace App\Application\Notifications\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Models\TenantUser;
use Illuminate\Database\Eloquent\Collection;

/**
 * Servicio centralizado de creación de notificaciones in-app (FASE 22 U2).
 *
 * Responsabilidades:
 * - Crear notificaciones per-user (targeted) o fan-out (handoff).
 * - Validar que el target pertenece al tenant y tiene membresía activa.
 * - Persistir vía Notification model (BelongsToTenant auto-asigna tenant_id).
 * - Registrar auditoría con payload seguro (sin PII).
 *
 * NO list/read/delete — eso es U3.
 */
final class NotificationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * HandoffRequested: crea una notificación POR CADA miembro activo del tenant.
     *
     * read_at es per-row, por lo que cada usuario necesita su propia fila
     * para poder marcar como leído individualmente (ADR-083).
     *
     * @return list<Notification>
     */
    public function handleHandoffRequested(Tenant $tenant, Conversation $conversation): array
    {
        $activeMembers = $this->activeMembersForTenant($tenant);

        $notifications = [];

        foreach ($activeMembers as $membership) {
            $notification = $this->createNotification(
                tenant: $tenant,
                userId: (int) $membership->user_id,
                type: NotificationType::HandoffRequested,
                priority: NotificationPriority::High,
                title: 'Conversación requiere atención',
                body: 'Una conversación fue transferida a atención humana y requiere seguimiento.',
                data: [
                    'conversation_id' => $conversation->id,
                    'event' => 'handoff_requested',
                ],
                auditAction: 'notification.created',
                auditSubjectId: null,
            );

            $notifications[] = $notification;
        }

        return $notifications;
    }

    /**
     * ConversationAssigned: crea notificación dirigida a un agente específico.
     *
     * Se usa tanto para assign como para transfer (el nuevo agente recibe la notificación).
     */
    public function handleConversationAssigned(
        Tenant $tenant,
        Conversation $conversation,
        int $agentId,
    ): ?Notification {
        $membership = $this->activeMembershipForUser($tenant, $agentId);

        if ($membership === null) {
            return null;
        }

        return $this->createNotification(
            tenant: $tenant,
            userId: $agentId,
            type: NotificationType::ConversationAssigned,
            priority: NotificationPriority::Normal,
            title: 'Conversación asignada',
            body: 'Se te asignó una conversación para atención.',
            data: [
                'conversation_id' => $conversation->id,
                'event' => 'conversation_assigned',
            ],
            auditAction: 'notification.created',
            auditSubjectId: null,
        );
    }

    /**
     * Busca todos los miembros activos del tenant.
     *
     * @return Collection<int, TenantUser>
     */
    private function activeMembersForTenant(Tenant $tenant): Collection
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembershipStatus::Active)
            ->get();
    }

    /**
     * Verifica que un usuario sea miembro activo del tenant.
     */
    private function activeMembershipForUser(Tenant $tenant, int $userId): ?TenantUser
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->where('status', TenantMembershipStatus::Active)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createNotification(
        Tenant $tenant,
        int $userId,
        NotificationType $type,
        NotificationPriority $priority,
        string $title,
        string $body,
        array $data,
        string $auditAction,
        ?string $auditSubjectId,
    ): Notification {
        $notification = Notification::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $userId,
            'type' => $type,
            'priority' => $priority,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $this->auditLogger->record(
            action: $auditAction,
            data: [
                'notification_id' => $notification->id,
                'type' => $type->value,
                'priority' => $priority->value,
                'target_user_id' => $userId,
                'conversation_id' => $data['conversation_id'] ?? null,
            ],
            subjectType: Notification::class,
            subjectId: $notification->id,
            tenantId: $tenant->id,
        );

        return $notification;
    }
}
