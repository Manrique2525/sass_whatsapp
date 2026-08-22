<?php

declare(strict_types=1);

namespace App\Application\Notifications\Listeners;

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Tenants\Models\Tenant;
use App\Events\InboxConversationChanged;
use App\Infrastructure\Tenancy\TenantContext;

/**
 * Escucha InboxConversationChanged y crea notificaciones in-app (FASE 22 U2).
 *
 * Eventos que generan notificación:
 * - HandoffRequested → fan-out a todos los miembros activos del tenant.
 * - Assigned → notificación dirigida al agente asignado.
 * - Transferred → notificación dirigida al nuevo agente.
 *
 * Eventos ignorados (sin notificación):
 * - Claimed → el claimer ya sabe que reclamó.
 * - BotResumed → no es notificación relevante.
 * - ConversationUpdated →变更 genérico, no requiere notificación.
 *
 * afterCommit: el evento ya garantiza que la transacción de negocio fue confirmada.
 * Síncrono: operación DB pequeña, no necesita cola (email será U4).
 *
 * Registrado en AppServiceProvider::boot() vía Event::listen().
 */
class CreateNotificationFromInboxChange
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(InboxConversationChanged $event): void
    {
        $previousTenantId = TenantContext::id();
        $tenantId = $event->conversation->tenant_id;

        TenantContext::setId($tenantId);

        try {
            match ($event->kind) {
                InboxConversationChangeKind::HandoffRequested => $this->handleHandoff($event),
                InboxConversationChangeKind::Assigned => $this->handleAssigned($event),
                InboxConversationChangeKind::Transferred => $this->handleTransferred($event),
                default => null,
            };
        } finally {
            if ($previousTenantId !== null) {
                TenantContext::setId($previousTenantId);
            } else {
                TenantContext::clear();
            }
        }
    }

    private function handleHandoff(InboxConversationChanged $event): void
    {
        $tenant = Tenant::find($event->conversation->tenant_id);

        if ($tenant === null) {
            return;
        }

        $this->notificationService->handleHandoffRequested(
            $tenant,
            $event->conversation,
        );
    }

    private function handleAssigned(InboxConversationChanged $event): void
    {
        $tenant = Tenant::find($event->conversation->tenant_id);

        if ($tenant === null) {
            return;
        }

        $agentId = $event->conversation->agent_id;

        if ($agentId === null) {
            return;
        }

        $this->notificationService->handleConversationAssigned(
            $tenant,
            $event->conversation,
            (int) $agentId,
        );
    }

    private function handleTransferred(InboxConversationChanged $event): void
    {
        $tenant = Tenant::find($event->conversation->tenant_id);

        if ($tenant === null) {
            return;
        }

        $agentId = $event->conversation->agent_id;

        if ($agentId === null) {
            return;
        }

        $this->notificationService->handleConversationAssigned(
            $tenant,
            $event->conversation,
            (int) $agentId,
        );
    }
}
