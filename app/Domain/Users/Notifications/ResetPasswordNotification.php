<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Notificación de restablecimiento de contraseña.
 *
 * La URL apunta a la página de la SPA (`/reset-password?token=&email=`), que
 * es consumida por Inertia.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

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
            ->subject('Restablecer contraseña')
            ->greeting('Hola '.$notifiable->name.'!')
            ->line('Estás recibiendo este correo porque solicitaste restablecer tu contraseña.')
            ->action('Restablecer contraseña', $this->resetUrl($notifiable))
            ->line('Si no lo solicitaste, ignora este mensaje. El enlace expira en 60 minutos.');
    }

    private function resetUrl(object $notifiable): string
    {
        return URL::to('/reset-password?token='.$this->token.'&email='.$notifiable->getEmailForPasswordReset());
    }
}
