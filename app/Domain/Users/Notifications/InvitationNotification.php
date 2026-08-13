<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Users\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Notificación de invitación a un tenant (ADR-027).
 *
 * El enlace contiene el token PLANO (única vía de entrega del token); el
 * servidor solo guarda su hash. La URL apunta a la página de aceptación de la
 * SPA (`/invitations/{token}`), consumida por Inertia.
 */
final class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TenantInvitation $invitation,
        private readonly string $token,
    ) {}

    public function getToken(): string
    {
        return $this->token;
    }

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
            ->subject('Has sido invitado a un tenant')
            ->greeting('Has sido invitado!')
            ->line('Fuiste invitado a unirte a "'.$this->invitation->tenant->name.'" como '.$this->invitation->role->value.'.')
            ->line('Acepta la invitación para empezar a colaborar.')
            ->action('Aceptar invitación', $this->acceptanceUrl())
            ->line('El enlace expira en 7 días.');
    }

    private function acceptanceUrl(): string
    {
        return URL::to('/invitations/'.$this->token);
    }
}
