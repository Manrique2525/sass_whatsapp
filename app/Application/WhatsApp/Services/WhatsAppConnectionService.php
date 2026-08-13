<?php

declare(strict_types=1);

namespace App\Application\WhatsApp\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WhatsAppAccountStatus;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppPhoneNotFoundException;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Conexión/desconexión de la cuenta de WhatsApp del tenant (FASE 6, ADR-029).
 *
 * Conectar valida SIEMPRE las credenciales contra Meta (consulta del
 * `phone_number_id` con el token del tenant) antes de persistir; el token se
 * guarda cifrado y jamás se expone. La suscripción del WABA al webhook de la
 * app es best-effort (si falla, se configura desde el dashboard de Meta).
 */
final class WhatsAppConnectionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WhatsAppProviderInterface $provider,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function showForUser(User $user, Tenant $tenant): ?WhatsAppAccount
    {
        $this->authorization->authorize($user, TenantPermission::ViewWhatsApp, $tenant);

        return $tenant->whatsappAccount;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{account: WhatsAppAccount, webhook_subscribed: bool}
     */
    public function connect(User $user, Tenant $tenant, array $validated): array
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $accessToken = (string) $validated['access_token'];
        $phoneId = (string) $validated['phone_number_id'];
        $wabaId = (string) $validated['whatsapp_business_account_id'];

        $info = $this->provider->getPhoneNumberInfo($accessToken, $phoneId);

        if ($info->id === '') {
            throw new WhatsAppPhoneNotFoundException('Meta no devolvió un phone_number_id válido.');
        }

        $account = $tenant->whatsappAccount;

        if ($account === null) {
            $account = $tenant->whatsappAccount()->create([
                'whatsapp_business_account_id' => $wabaId,
                'display_name' => $validated['display_name'] ?? null,
                'access_token' => $accessToken,
                'status' => WhatsAppAccountStatus::Connected,
            ]);
        } else {
            $account->fill([
                'whatsapp_business_account_id' => $wabaId,
                'display_name' => $validated['display_name'] ?? null,
                'access_token' => $accessToken,
                'status' => WhatsAppAccountStatus::Connected,
            ])->save();
        }

        $phone = WhatsAppPhoneNumber::query()
            ->withoutTenantScope()
            ->where('phone_id', $phoneId)
            ->first();

        if ($phone !== null && $phone->tenant_id !== $tenant->id) {
            throw new WhatsAppMessageFailedException(
                'El número ya está conectado por otro tenant.',
                null,
                false,
            );
        }

        if ($phone === null) {
            $tenant->whatsappPhoneNumbers()->create([
                'whatsapp_account_id' => $account->id,
                'phone_id' => $phoneId,
                'display_phone_number' => (string) ($validated['phone_number'] ?? $info->displayPhoneNumber ?? ''),
                'verified_name' => $info->verifiedName,
                'quality_rating' => $info->qualityRating,
                'status' => PhoneNumberStatus::Connected,
                'is_default' => true,
            ]);
        } else {
            $phone->fill([
                'whatsapp_account_id' => $account->id,
                'display_phone_number' => (string) ($validated['phone_number'] ?? $phone->display_phone_number ?? ''),
                'verified_name' => $info->verifiedName ?? $phone->verified_name,
                'quality_rating' => $info->qualityRating ?? $phone->quality_rating,
                'status' => PhoneNumberStatus::Connected,
            ])->save();
        }

        $webhookSubscribed = false;

        try {
            $webhookSubscribed = $this->provider->subscribeToWebhooks($accessToken, $wabaId);
        } catch (WhatsAppMessageFailedException $e) {
            Log::warning('whatsapp.webhook_subscription_failed', [
                'tenant_id' => $tenant->id,
                'reason' => $e->getMessage(),
            ]);
        }

        if ($webhookSubscribed) {
            $this->auditLogger->record(
                action: 'whatsapp.webhook_configured',
                data: [
                    'tenant_id' => $tenant->id,
                    'whatsapp_business_account_id' => $wabaId,
                ],
                subjectType: WhatsAppAccount::class,
                subjectId: $account->id,
            );
        }

        $this->auditLogger->record(
            action: 'whatsapp.connected',
            data: [
                'tenant_id' => $tenant->id,
                'phone_id' => $phoneId,
                'webhook_subscribed' => $webhookSubscribed,
            ],
            subjectType: WhatsAppAccount::class,
            subjectId: $account->id,
        );

        return [
            'account' => $account->loadMissing('phoneNumbers'),
            'webhook_subscribed' => $webhookSubscribed,
        ];
    }

    public function disconnect(User $user, Tenant $tenant): WhatsAppAccount
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $account = $tenant->whatsappAccount;

        if ($account === null) {
            throw new WhatsAppNotConnectedException('No hay una cuenta de WhatsApp conectada.');
        }

        if ($account->isConnected()) {
            $accessToken = $account->access_token;
            $wabaId = $account->whatsapp_business_account_id;

            if ($accessToken !== null && $wabaId !== null) {
                try {
                    $this->provider->unsubscribeFromWebhooks($accessToken, $wabaId);
                } catch (WhatsAppMessageFailedException $e) {
                    Log::warning('whatsapp.webhook_unsubscription_failed', [
                        'tenant_id' => $tenant->id,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }

            $account->fill([
                'status' => WhatsAppAccountStatus::Disconnected,
                'access_token' => null,
            ])->save();

            $account->phoneNumbers()->update(['status' => PhoneNumberStatus::Disconnected->value]);
        }

        $this->auditLogger->record(
            action: 'whatsapp.disconnected',
            data: ['tenant_id' => $tenant->id],
            subjectType: WhatsAppAccount::class,
            subjectId: $account->id,
        );

        return $account->loadMissing('phoneNumbers');
    }
}
