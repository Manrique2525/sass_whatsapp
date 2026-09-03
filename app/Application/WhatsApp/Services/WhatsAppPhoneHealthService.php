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
use App\Domain\WhatsApp\Exceptions\WhatsAppException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedHealthException;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;
use App\Infrastructure\Observability\MetricsRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Monitoreo de salud de números de WhatsApp (FASE 31 U6).
 *
 * Consulta a la Graph API el estado/calidad actual (`quality_rating`,
 * `verified_name`, status del provider) de los números de un tenant y actualiza
 * los CAMPOS EXISTENTES del número (informativos). NO cambia el campo `status`
 * que decide la elegibilidad de envío: un problema temporal del provider no
 * desconecta un número en producción.
 *
 * Es una operación explícita (owner/admin, endpoint de operaciones), nunca en el
 * camino caliente. Cada refresco queda auditado (`whatsapp.phone.health.check`).
 */
final class WhatsAppPhoneHealthService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WhatsAppProviderInterface $provider,
        private readonly AuditLogger $auditLogger,
        private readonly ?MetricsRecorder $metrics = null,
    ) {}

    /**
     * Refresca la salud de los números conectados del tenant.
     *
     * @return array{checked: int, healthy: int, degraded: int, flagged: list<array{phone_id: string, status: ?string, quality_rating: ?string}>}
     */
    public function check(User $user, Tenant $tenant): array
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $account = $tenant->whatsappAccount;

        if ($account === null || ! $account->isConnected()) {
            throw new WhatsAppNotConnectedHealthException;
        }

        $phones = $tenant->whatsappPhoneNumbers()
            ->where('status', PhoneNumberStatus::Connected->value)
            ->get();

        $healthy = 0;
        $degraded = 0;
        $flagged = [];

        foreach ($phones as $phone) {
            $info = $this->readPhoneInfo($account, $phone);

            if ($info === null) {
                $degraded++;
                $flagged[] = [
                    'phone_id' => $phone->phone_id,
                    'status' => null,
                    'quality_rating' => null,
                ];

                continue;
            }

            // Persiste solo campos informativos en columnas existentes.
            $phone->fill([
                'quality_rating' => $info->qualityRating ?? $phone->quality_rating,
                'verified_name' => $info->verifiedName ?? $phone->verified_name,
            ])->save();

            $rating = strtoupper($info->qualityRating ?? '');
            $status = strtolower($info->status ?? '');
            $this->noteHealth($rating);

            if ($status === 'connected' && $rating === 'GREEN') {
                $healthy++;
            } else {
                $degraded++;
            }

            $flagged[] = [
                'phone_id' => $phone->phone_id,
                'status' => $info->status,
                'quality_rating' => $info->qualityRating,
            ];
        }

        $this->auditLogger->record(
            action: 'whatsapp.phone.health.check',
            data: [
                'tenant_id' => $tenant->id,
                'checked' => $phones->count(),
                'healthy' => $healthy,
                'degraded' => $degraded,
            ],
            subjectType: WhatsAppAccount::class,
            subjectId: $account->id,
            tenantId: $tenant->id,
        );

        return [
            'checked' => $phones->count(),
            'healthy' => $healthy,
            'degraded' => $degraded,
            'flagged' => $flagged,
        ];
    }

    /**
     * Consulta la info del provider de forma fail-safe para el monitoreo.
     *
     * @return ?PhoneNumberInfo null si el provider no pudo devolver info.
     */
    private function readPhoneInfo(WhatsAppAccount $account, WhatsAppPhoneNumber $phone): ?PhoneNumberInfo
    {
        $accessToken = $account->access_token;

        if ($accessToken === null || $accessToken === '') {
            Log::warning('whatsapp.phone.health_skipped', [
                'phone_id' => $phone->phone_id,
                'tenant_id' => $account->tenant_id,
                'reason' => 'no_token',
            ]);

            return null;
        }

        try {
            return $this->provider->getPhoneNumberInfo($accessToken, $phone->phone_id);
        } catch (WhatsAppException $exception) {
            Log::warning('whatsapp.phone.health_error', [
                'phone_id' => $phone->phone_id,
                'tenant_id' => $account->tenant_id,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function noteHealth(string $rating): void
    {
        $key = strtolower(str_replace('-', '_', $rating));

        $this->metrics?->increment('whatsapp.phone.health.rating.'.$key);
    }
}
