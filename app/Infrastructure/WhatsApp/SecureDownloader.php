<?php

declare(strict_types=1);

namespace App\Infrastructure\WhatsApp;

use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\WhatsApp\Exceptions\WhatsAppMediaDownloadException;
use App\Domain\WhatsApp\ValueObjects\MediaDownload;
use App\Infrastructure\WhatsApp\Security\SsrUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Descarga segura de contenido remoto con protección SSRF (FASE 31 U5).
 *
 * Único punto donde la app abre una URL externa para media. La URL proviene
 * SOLO del look-up de Meta (nunca de un cliente):
 *
 * - Valida el destino inicial y CADA redirección con `SsrUrlGuard`.
 * - Redirecciones acotadas (`maxRedirects`); si se excede → reject.
 * - No reenvía tokens de autorización: la URL de descarga de Meta es una URL
 *   firmada temporal, no un endpoint de Graph del APP.
 * - Descarga en streaming (php://temp) con tope de bytes; si se supera el tope
 *   antes de terminar → `oversize` (no se carga en memoria arbitraria).
 */
final class SecureDownloader
{
    public function __construct(
        private readonly SsrUrlGuard $guard = new SsrUrlGuard,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 15,
        private readonly int $chunkBytes = 65536,
    ) {}

    public function download(string $url, int $maxBytes): MediaDownload
    {
        return $this->downloadWithRedirectBudget($url, $maxBytes, config('whatsapp.media_max_redirects', 3));
    }

    private function downloadWithRedirectBudget(string $url, int $maxBytes, int $redirectBudget): MediaDownload
    {
        $this->guard->assertSafe($url);

        if ($redirectBudget < 0) {
            throw new WhatsAppMediaDownloadException(
                'Demasiadas redirecciones.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        $response = $this->fetch($url);

        if ($response->redirect()) {
            if ($redirectBudget === 0) {
                throw new WhatsAppMediaDownloadException(
                    'Demasiadas redirecciones.',
                    MessageMediaFailureReason::SsrfRejected,
                );
            }

            $next = $this->redirectTarget($response, $url);

            return $this->downloadWithRedirectBudget($next, $maxBytes, $redirectBudget - 1);
        }

        if ($response->serverError()) {
            throw new WhatsAppMediaDownloadException(
                'Error de servidor al descargar el media.',
                MessageMediaFailureReason::DownloadFailed,
            );
        }

        if (! $response->successful()) {
            $this->guard->assertSafe($url);

            throw new WhatsAppMediaDownloadException(
                'La descarga del media no fue exitosa.',
                MessageMediaFailureReason::DownloadFailed,
            );
        }

        return $this->buffer($response, $maxBytes);
    }

    private function fetch(string $url): Response
    {
        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'http_errors' => false,
            ])->get($url);
        } catch (ConnectionException $e) {
            throw new WhatsAppMediaDownloadException(
                'Error de conexión al descargar el media.',
                MessageMediaFailureReason::DownloadFailed,
            );
        }
    }

    private function redirectTarget(Response $response, string $currentUrl): string
    {
        $location = $response->header('Location');

        if ($location === '') {
            throw new WhatsAppMediaDownloadException(
                'Redirección sin destino.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        return $this->absoluteUrl($currentUrl, $location);
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new WhatsAppMediaDownloadException(
                'Redirección inválida.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        $path = str_starts_with($location, '/')
            ? $location
            : rtrim($parts['path'] ?? '/', '/').'/'.$location;

        return $scheme.'://'.$host.$path;
    }

    private function buffer(Response $response, int $maxBytes): MediaDownload
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
        } catch (\Throwable) {
            throw new WhatsAppMediaDownloadException(
                'No se pudo leer el contenido del media.',
                MessageMediaFailureReason::DownloadFailed,
            );
        }

        $buffer = fopen('php://temp', 'w+b');
        $size = 0;
        $contentType = $response->header('Content-Type');

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read($this->chunkBytes);

                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);

                if ($size > $maxBytes) {
                    fclose($buffer);

                    throw new WhatsAppMediaDownloadException(
                        'El media supera el tamaño máximo permitido.',
                        MessageMediaFailureReason::Oversize,
                    );
                }

                fwrite($buffer, $chunk);
            }
        } catch (\Throwable $e) {
            if (is_resource($buffer)) {
                fclose($buffer);
            }

            if ($e instanceof WhatsAppMediaDownloadException) {
                throw $e;
            }

            throw new WhatsAppMediaDownloadException(
                'Error al leer el contenido del media.',
                MessageMediaFailureReason::DownloadFailed,
            );
        }

        rewind($buffer);

        $contentType = $contentType !== '' ? strtolower(trim($contentType)) : null;

        return new MediaDownload($buffer, $size, $contentType);
    }
}
