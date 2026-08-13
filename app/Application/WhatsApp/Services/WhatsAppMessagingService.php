<?php

declare(strict_types=1);

namespace App\Application\WhatsApp\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\MessageSendStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;

/**
 * Envío de mensajes por WhatsApp (FASE 6).
 *
 * Registra cada intento en `message_send_attempts` y usa el token del WABA del
 * tenant (cifrado en `whatsapp_accounts`). El endpoint HTTP de envío
 * (`POST /api/v1/conversations/{conversation}/messages`) y el job de cola con
 * backoff corresponden a la fase de mensajería (FASE 9): aquí se ejercita la
 * capacidad real de envío con su política de reintento (no reintentar errores
 * permanentes de Meta).
 */
final class WhatsAppMessagingService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WhatsAppProviderInterface $provider,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sendText(User $user, Tenant $tenant, string $to, string $text): MessageSendResult
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $account = $tenant->whatsappAccount;
        $phone = $tenant->whatsappPhoneNumbers()
            ->where('status', PhoneNumberStatus::Connected->value)
            ->orderByDesc('is_default')
            ->first();

        if ($account === null || $phone === null || ! $account->isConnected()) {
            throw new WhatsAppNotConnectedException('No hay una cuenta de WhatsApp conectada para el tenant.');
        }

        $accessToken = $account->access_token;

        if ($accessToken === null || $accessToken === '') {
            throw new WhatsAppNotConnectedException('El token de WhatsApp no está disponible.');
        }

        $attempt = MessageSendAttempt::create([
            'whatsapp_phone_number_id' => $phone->id,
            'to' => $to,
            'type' => 'text',
            'payload' => ['text' => $text],
            'status' => MessageSendStatus::Pending,
            'attempt' => 1,
            'max_attempts' => (int) config('whatsapp.max_attempts', 3),
        ]);

        try {
            $result = $this->provider->sendText($accessToken, $phone->phone_id, $to, $text);
        } catch (WhatsAppMessageFailedException $e) {
            $attempt->fill([
                'status' => MessageSendStatus::Failed,
                'error_code' => $e->errorCode()->value,
                'error_message' => $e->getMessage(),
                'payload' => array_merge((array) $attempt->payload, [
                    'provider_error_code' => $e->providerErrorCode(),
                    'retryable' => $e->retryable(),
                ]),
                'attempted_at' => now(),
            ])->save();

            $this->auditLogger->record(
                action: 'whatsapp.message_failed',
                data: [
                    'tenant_id' => $tenant->id,
                    'to' => $to,
                    'provider_error_code' => $e->providerErrorCode(),
                    'retryable' => $e->retryable(),
                ],
                subjectType: MessageSendAttempt::class,
                subjectId: $attempt->id,
            );

            throw $e;
        }

        $attempt->fill([
            'status' => MessageSendStatus::Sent,
            'provider_message_id' => $result->providerMessageId,
            'attempted_at' => now(),
        ])->save();

        $this->auditLogger->record(
            action: 'whatsapp.message_sent',
            data: [
                'tenant_id' => $tenant->id,
                'to' => $to,
            ],
            subjectType: MessageSendAttempt::class,
            subjectId: $attempt->id,
        );

        return $result;
    }
}
