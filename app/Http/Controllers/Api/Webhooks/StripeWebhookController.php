<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Application\Billing\Services\StripeWebhookService;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook endpoint (FASE 24 U3, ADR-094).
 *
 * Public endpoint — NO auth, NO CSRF, NO tenant middleware.
 * Authenticated by Stripe-Signature header verified via BillingProviderInterface.
 *
 * HTTP response contract:
 * - Invalid signature → 400 (Stripe won't retry)
 * - Valid + processed → 200 {"received": true}
 * - Valid + duplicate → 200 {"received": true}
 * - Valid + ignored event → 200 {"received": true}
 * - Valid + transient failure → 500 (Stripe will retry)
 */
final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly StripeWebhookService $webhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $sigHeader = $request->header('Stripe-Signature', '');

        if ($sigHeader === '') {
            Log::warning('stripe.webhook.missing_signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Missing Stripe-Signature header.'], 400);
        }

        $rawPayload = $request->getContent();

        try {
            $event = $this->provider->constructWebhookEvent($rawPayload, $sigHeader);
        } catch (BillingProviderException $e) {
            Log::warning('stripe.webhook.invalid_signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        try {
            $this->webhookService->handle($event);
        } catch (\Throwable $e) {
            Log::error('stripe.webhook.transient_error', [
                'provider_event_id' => $event->eventId,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            // Return 500 to let Stripe retry
            return response()->json(['message' => 'Internal error.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
