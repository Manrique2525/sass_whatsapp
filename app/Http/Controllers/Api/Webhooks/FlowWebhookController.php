<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\StartFlowFromWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook público de flujos (FASE 14, UNIDAD 3, ADR-049).
 *
 * Endpoint público SIN auth Bearer. Autenticación por token en
 * `Authorization: Bearer {token}`. El tenant se resuelve EXCLUSIVAMENTE
 * desde el trigger (nunca del payload).
 *
 * Flujo: validación → resolución trigger + tenant → autenticación token →
 * resolución conversación → idempotencia → despacho job → 202.
 *
 * Respuestas de error genéricas: nunca revelan si el trigger existe, es
 * activo, o la razón exacta del fallo (previene enumeración).
 */
final class FlowWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    public function handle(Request $request, string $trigger): JsonResponse
    {
        $triggerModel = $this->resolveTrigger($trigger);

        if ($triggerModel === null) {
            return $this->genericUnauthorized();
        }

        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->genericUnauthorized();
        }

        if (! $this->validateToken($token, $triggerModel)) {
            return $this->genericUnauthorized();
        }

        $tenant = TenantContext::tenant();

        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            return $this->genericUnauthorized();
        }

        $flow = $triggerModel->flow;

        if ($flow === null || $flow->status !== FlowStatus::Published) {
            return $this->genericUnauthorized();
        }

        if ($flow->chatbot === null) {
            return $this->genericUnauthorized();
        }

        $payload = $this->validatePayload($request);

        if ($payload === null) {
            return response()->json([
                'message' => 'Payload inválido.',
                'code' => 'WEBHOOK_PAYLOAD_INVALID',
            ], 400);
        }

        $conversation = $this->resolveConversation($triggerModel, $payload);

        if ($conversation === null) {
            return response()->json([
                'message' => 'Conversación no resuelta.',
                'code' => 'WEBHOOK_CONVERSATION_NOT_FOUND',
            ], 400);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $lockKey = 'lock:webhook:idempotency:'.hash('sha256', $idempotencyKey);

            $lock = Cache::lock($lockKey, 60);

            if (! $lock->get()) {
                return response()->json([
                    'message' => 'Evento ya procesado.',
                    'code' => 'WEBHOOK_DUPLICATE',
                ], 409);
            }
        }

        $jobIdempotencyKey = $idempotencyKey ?? 'auto:'.(string) Str::uuid();

        dispatch(
            (new StartFlowFromWebhook(
                triggerId: $triggerModel->id,
                conversationId: $conversation->id,
                idempotencyKey: $jobIdempotencyKey,
                payload: $this->extractSafePayload($payload),
            ))->forTenant($tenant->id),
        );

        Log::info('flow.webhook_dispatched', [
            'trigger_id' => $triggerModel->id,
            'flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }

    private function resolveTrigger(string $triggerId): ?Trigger
    {
        if (! Str::isUuid($triggerId)) {
            return null;
        }

        $trigger = Trigger::query()
            ->withoutTenantScope()
            ->where('id', $triggerId)
            ->where('active', true)
            ->where('type', FlowTriggerType::Webhook->value)
            ->first();

        if ($trigger === null) {
            return null;
        }

        TenantContext::setId($trigger->tenant_id);

        return $trigger;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        if ($token === '' || ! preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return $token;
    }

    private function validateToken(string $token, Trigger $trigger): bool
    {
        $config = is_array($trigger->config) ? $trigger->config : [];
        $storedHash = $config['token_hash'] ?? null;

        if (! is_string($storedHash) || ! preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
            return false;
        }

        $providedHash = hash('sha256', $token);

        return hash_equals($storedHash, $providedHash);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validatePayload(Request $request): ?array
    {
        $content = $request->getContent();

        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }

        $payload = json_decode($content, true, 512);

        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveConversation(Trigger $trigger, array $payload): ?Conversation
    {
        $config = is_array($trigger->config) ? $trigger->config : [];
        $conversationBy = $config['conversation_by'] ?? null;

        if (! is_string($conversationBy)) {
            return null;
        }

        return match ($conversationBy) {
            'conversation_id' => $this->resolveByConversationId($trigger, $payload),
            'contact_id' => $this->resolveByContactId($trigger, $payload),
            'phone' => $this->resolveByPhone($trigger, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveByConversationId(Trigger $trigger, array $payload): ?Conversation
    {
        $conversationId = $payload['conversation_id'] ?? null;

        if (! is_string($conversationId) || ! Str::isUuid($conversationId)) {
            return null;
        }

        return Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $trigger->tenant_id)
            ->whereKey($conversationId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveByContactId(Trigger $trigger, array $payload): ?Conversation
    {
        $contactId = $payload['contact_id'] ?? null;

        if (! is_string($contactId) || ! Str::isUuid($contactId)) {
            return null;
        }

        $contact = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $trigger->tenant_id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            return null;
        }

        return Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $trigger->tenant_id)
            ->where('contact_id', $contact->id)
            ->where('status', '!=', 'resolved')
            ->orderByDesc('last_message_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveByPhone(Trigger $trigger, array $payload): ?Conversation
    {
        $phone = $payload['phone'] ?? null;

        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $normalized = ltrim(trim($phone), '+');

        $contact = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $trigger->tenant_id)
            ->whereRaw("REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ?", ['%'.$normalized.'%'])
            ->first();

        if ($contact === null) {
            return null;
        }

        return Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $trigger->tenant_id)
            ->where('contact_id', $contact->id)
            ->where('status', '!=', 'resolved')
            ->orderByDesc('last_message_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSafePayload(array $payload): array
    {
        $allowed = ['conversation_id', 'contact_id', 'phone', 'payload'];

        return array_intersect_key($payload, array_flip($allowed));
    }

    private function genericUnauthorized(): JsonResponse
    {
        return response()->json([
            'message' => 'No autorizado.',
            'code' => 'WEBHOOK_UNAUTHORIZED',
        ], 401);
    }
}
