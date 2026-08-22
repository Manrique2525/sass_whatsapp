<?php

declare(strict_types=1);

namespace App\Application\Notifications\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Exceptions\NotificationNotFoundException;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Servicio centralizado de notificaciones in-app (FASE 22 U2/U3).
 *
 * U2 — Creación:
 * - Crear notificaciones per-user (targeted) o fan-out (handoff).
 * - Validar que el target pertenece al tenant y tiene membresía activa.
 * - Registrar auditoría con payload seguro (sin PII).
 *
 * U3 — Read/query:
 * - Listar notificaciones paginadas del usuario autenticado.
 * - Contar no leídas (inbox unread count).
 * - Marcar leído (individual) o mark-all-read.
 * - Ownership: cada usuario solo ve sus propias notificaciones.
 */
final class NotificationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ──────────────────────────────────────────────
    // U3 — Read / Query operations
    // ──────────────────────────────────────────────

    /**
     * Listado paginado de notificaciones del usuario autenticado.
     *
     * @param  array{read_status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        $query = Notification::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at');

        $readStatus = $filters['read_status'] ?? 'all';

        if ($readStatus === 'unread') {
            $query->whereNull('read_at');
        } elseif ($readStatus === 'read') {
            $query->whereNotNull('read_at');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Cantidad de notificaciones no leídas del usuario en el tenant.
     */
    public function unreadCount(User $user, Tenant $tenant): int
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        return (int) Notification::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Marca una notificación específica como leída.
     *
     * Idempotente: si ya está leída, mantiene el read_at original.
     * CAS-style: UPDATE WHERE read_at IS NULL protege concurrencia.
     *
     * @throws NotificationNotFoundException
     */
    public function markAsRead(User $user, Tenant $tenant, string $notificationId): Notification
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        $notification = $this->findAuthorizedNotification($user, $tenant, $notificationId);

        if ($notification->read_at === null) {
            Notification::query()
                ->withoutTenantScope()
                ->where('id', $notification->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $notification->refresh();
        }

        return $notification;
    }

    /**
     * Marca todas las notificaciones no leídas del usuario como leídas.
     *
     * @return int Cantidad de notificaciones actualizadas.
     */
    public function markAllAsRead(User $user, Tenant $tenant): int
    {
        $this->authorization->authorize($user, TenantPermission::ViewNotifications, $tenant);

        return Notification::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->update(['read_at' => now()]);
    }

    // ──────────────────────────────────────────────
    // U2 — Write / Creation operations
    // ──────────────────────────────────────────────

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

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Resuelve una notificación autorizada para el usuario/tenant.
     *
     * @throws NotificationNotFoundException
     */
    private function findAuthorizedNotification(User $user, Tenant $tenant, string $notificationId): Notification
    {
        $notification = Notification::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->find($notificationId);

        if ($notification === null) {
            throw new NotificationNotFoundException;
        }

        return $notification;
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
