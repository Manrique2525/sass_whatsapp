<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Application\WhatsApp\Services\WhatsAppWebhookService;
use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;
use App\Domain\WhatsApp\Exceptions\WhatsAppWebhookInvalidException;
use App\Domain\WhatsApp\Exceptions\WhatsAppWebhookSignatureInvalidException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp (público, SIN auth Bearer).
 *
 * Autenticación: verificación GET por `hub.verify_token` y firma
 * `X-Hub-Signature-256` en POST. El dedupe lo hace `WhatsAppWebhookService`.
 *
 * - Firma inválida → 401 (Meta no reintentará eventos falsos).
 * - Payload malformado → 200 + log (nunca 500, que provocaría reenvíos).
 * - Evento válido → 200 rápido; el trabajo pesado va a la cola.
 */
final class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppWebhookService $webhookService) {}

    public function verify(Request $request): Response|JsonResponse
    {
        $challenge = $this->webhookService->verify($request->query());

        if ($challenge === null) {
            return response()->json([
                'message' => 'Verificación de webhook rechazada.',
                'code' => WhatsAppErrorCode::WebhookInvalid->value,
            ], 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->webhookService->handle($request);
        } catch (WhatsAppWebhookSignatureInvalidException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode()->value,
            ], $e->status());
        } catch (WhatsAppWebhookInvalidException $e) {
            Log::warning('whatsapp.webhook_invalid_payload', [
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'ok'], 200);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
