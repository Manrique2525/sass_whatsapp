<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email de notificación de handoff solicitado (FASE 22 U4).
 *
 * Se envía a owners y admins del tenant cuando una conversación requiere
 * atención humana. Contenido genérico sin PII (ADR-086).
 *
 * ShouldQueue: se procesa asíncronamente via Redis queue.
 * afterCommit: el evento upstream ya garantiza transacción confirmada.
 */
final class HandoffRequestMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tenantName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Una conversación requiere atención')
            ->greeting('Conversación pendiente')
            ->line('Hay una conversación que requiere atención humana.')
            ->line('Por favor, revisa la bandeja de entrada de "'.$this->tenantName.'".')
            ->line('Si no deseas recibir estos correos, desactiva las notificaciones por email en tu configuración de perfil.');
    }
}
