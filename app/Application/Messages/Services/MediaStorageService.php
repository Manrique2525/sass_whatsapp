<?php

declare(strict_types=1);

namespace App\Application\Messages\Services;

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\Messages\Enums\MessageMediaProcessingStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\MessageMedia;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Exceptions\WhatsAppMediaDownloadException;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\ValueObjects\MediaDownload;
use App\Domain\WhatsApp\ValueObjects\MediaMetadata;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Procesa y almacena un asset de media de forma segura y aislada por tenant
 * (FASE 31 U5, ADR-121).
 *
 * Invariantes de seguridad:
 * - La URL de descarga proviene SOLO del look-up del provider (nunca del cliente)
 *   y la descarga pasa por el `SecureDownloader` (SSRF, redirecciones acotadas,
 *   sin reenvío de Token, tope de bytes).
 * - El media se almacena en una ruta interna opaca `tenant/{tenantId}/whatsapp/
 *   media/{uuid}`; el path NUNCA se deriva del nombre de archivo del usuario.
 * - `sha256`/`mime`/`size` son valores VALIDADOS del contenido real (no del
 *   header/webhook/filename): el mime se detecta por inspección (fileinfo) y se
 *   contrasta con la lista blanca; el sha256 se computa sobre los bytes que se
 *   persisten; size es el tamaño real.
 * - `original_filename` se guarda solo SANITIZADO (basename + sin separadores de
 *   ruta ni caracteres de control) y jamás como base del path de almacenamiento.
 */
final class MediaStorageService
{
    private const CHUNK_BYTES = 65536;

    public function __construct(
        private readonly WhatsAppProviderInterface $provider,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Descarga, valida y almacena el contenido del media.
     *
     * Implementa CAS `pending → processing` (idempotente: si otro worker ya
     * tomó el asset, no hace nada). En éxito marca `downloaded`; en fallo de
     * política (oversize/mime/ssrf/provider) marca `failed` con su código seguro.
     */
    public function process(MessageMedia $media): void
    {
        if ($media->processing_status->isTerminal()) {
            return;
        }

        $claimed = MessageMedia::query()
            ->withoutTenantScope()
            ->where('tenant_id', $media->tenant_id)
            ->whereKey($media->id)
            ->where('processing_status', MessageMediaProcessingStatus::Pending->value)
            ->update(['processing_status' => MessageMediaProcessingStatus::Processing->value]);

        if ($claimed === 0) {
            return;
        }

        $media->refresh();

        $account = WhatsAppAccount::query()
            ->withoutTenantScope()
            ->where('tenant_id', $media->tenant_id)
            ->first();

        $accessToken = $account?->isConnected() ? $account->access_token : null;

        if ($account === null || $accessToken === null || $accessToken === '' || $media->provider_media_id === null) {
            $this->markFailed($media, MessageMediaFailureReason::DownloadFailed);

            return;
        }

        try {
            $metadata = $this->provider->getMediaMetadata($accessToken, $media->provider_media_id);
            $this->assertDeclaredSafe($media, $metadata);

            $download = $this->provider->downloadMedia($accessToken, $metadata, $this->maxBytes($media));

            $this->persist($media, $download);
        } catch (WhatsAppMediaDownloadException $e) {
            $this->markFailed($media, $e->reason());
        } catch (Throwable) {
            $this->markFailed($media, MessageMediaFailureReason::DownloadFailed);
        }
    }

    /**
     * Valida la metadata declarada por el provider ANTES de descargar.
     */
    private function assertDeclaredSafe(MessageMedia $media, MediaMetadata $metadata): void
    {
        $maxBytes = $this->maxBytes($media);

        if ($metadata->fileSize !== null && $metadata->fileSize > $maxBytes) {
            throw new WhatsAppMediaDownloadException(
                'El media declarado supera el tamaño máximo.',
                MessageMediaFailureReason::Oversize,
            );
        }
    }

    private function persist(MessageMedia $media, MediaDownload $download): void
    {
        $resource = $download->resource;
        $maxBytes = $this->maxBytes($media);

        $hash = hash_init('sha256');
        $buffer = fopen('php://temp', 'w+b');
        $size = 0;

        try {
            while (! feof($resource)) {
                $chunk = fread($resource, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                hash_update($hash, $chunk);
                fwrite($buffer, $chunk);
            }

            $sha256 = hash_final($hash);

            if ($size > $maxBytes) {
                throw new WhatsAppMediaDownloadException(
                    'El media supera el tamaño máximo permitido.',
                    MessageMediaFailureReason::Oversize,
                );
            }

            rewind($buffer);

            $probe = stream_get_contents($buffer, 8192);
            rewind($buffer);

            $detectedMime = $this->detectMime($probe);

            if ($detectedMime === null || ! $this->isAllowedMime($detectedMime)) {
                throw new WhatsAppMediaDownloadException(
                    'Tipo de media no permitido.',
                    MessageMediaFailureReason::InvalidMime,
                );
            }

            $disk = $this->disk($media->tenant_id);

            $path = $this->storagePath($media->tenant_id, $media->id);

            $disk->writeStream($path, $buffer);
        } catch (Throwable $e) {
            if (is_resource($buffer)) {
                fclose($buffer);
            }

            if ($e instanceof WhatsAppMediaDownloadException) {
                throw $e;
            }

            throw new WhatsAppMediaDownloadException(
                'No se pudo almacenar el media.',
                MessageMediaFailureReason::StorageFailed,
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            if (is_resource($buffer)) {
                fclose($buffer);
            }
        }

        $media->forceFill([
            'storage_disk' => $disk->getConfig()['driver'] ?? 'local',
            'storage_path' => $path,
            'sha256' => $sha256,
            'mime' => $detectedMime,
            'size' => $size,
            'original_filename' => self::sanitizeFilename($media->original_filename),
            'processing_status' => MessageMediaProcessingStatus::Downloaded,
            'downloaded_at' => now(),
        ])->save();
    }

    private function disk(string $tenantId): Filesystem
    {
        $diskName = config('whatsapp.media_disk', 'local');

        return Storage::disk($diskName);
    }

    private function storagePath(string $tenantId, string $mediaId): string
    {
        return 'tenant/'.$tenantId.'/whatsapp/media/'.$mediaId;
    }

    private function maxBytes(MessageMedia $media): int
    {
        return $media->message?->type === MessageType::Document
            ? (int) config('whatsapp.media_document_max_bytes', 104857600)
            : (int) config('whatsapp.media_max_bytes', 10485760);
    }

    private function isAllowedMime(string $mime): bool
    {
        $allowed = config('whatsapp.media_allowed_mime_types', []);

        return is_array($allowed) && in_array(strtolower($mime), $allowed, true);
    }

    private function detectMime(string $buffer): ?string
    {
        if ($buffer === '') {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mime = $finfo->buffer($buffer);

        if (! is_string($mime) || $mime === '') {
            return null;
        }

        return strtolower(trim($mime));
    }

    public static function sanitizeFilename(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $filename = mb_strtolower(basename(str_replace('\\', '/', $filename)));

        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';

        $sanitized = preg_replace('/[^a-z0-9._-]+/u', '_', $sanitized) ?? '';

        $sanitized = trim($sanitized, ' ._');

        $sanitized = substr($sanitized, 0, 255);

        return $sanitized === '' ? null : $sanitized;
    }

    private function markFailed(MessageMedia $media, MessageMediaFailureReason $reason): void
    {
        $media->forceFill([
            'processing_status' => MessageMediaProcessingStatus::Failed,
            'failure_reason' => $reason->value,
        ])->save();
    }

    /**
     * Información de entrega de un media para el endpoint de descarga.
     *
     * Autoriza `whatsapp.view` y devuelve null si el media no pertenece al
     * tenant autorizado, no está `downloaded` o no tiene almacenamiento interno
     * (el controller responde 404). NUNCA se expone `storage_disk`/`storage_path`
     * al cliente: solo se usan internamente para servir el archivo.
     *
     * @return array{disk: string, path: string, filename: string, mime: string}|null
     */
    public function deliveryInfoForUser(User $user, Tenant $tenant, string $mediaId): ?array
    {
        $this->authorization->authorize($user, TenantPermission::ViewWhatsApp, $tenant);

        $media = MessageMedia::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($mediaId)
            ->first();

        if ($media === null
            || $media->processing_status !== MessageMediaProcessingStatus::Downloaded
            || $media->storage_disk === null
            || $media->storage_path === null
            || $media->storage_path === '') {
            return null;
        }

        return [
            'disk' => $media->storage_disk,
            'path' => $media->storage_path,
            'filename' => $media->original_filename ?? 'media-'.$media->id,
            'mime' => $media->mime ?? 'application/octet-stream',
        ];
    }
}
