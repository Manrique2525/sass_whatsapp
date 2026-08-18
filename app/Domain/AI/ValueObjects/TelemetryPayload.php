<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObjects;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * Payload seguro de telemetría AI (FASE 16 U4).
 *
 * VO inmutable que encapsula SOLO campos no-PII para logs de uso.
 * Garantiza que nunca contenga: prompt, system_prompt, response content,
 * contact data, business data, o custom.secret.
 *
 * Safe schema (resultado de toArray()):
 * {operation, provider, model, input_tokens, output_tokens, total_tokens,
 *  latency_ms, success, error_code, fallback_used}
 */
final readonly class TelemetryPayload
{
    private function __construct(
        public string $operation,
        public string $provider,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
        public int $latencyMs,
        public bool $success,
        public ?string $errorCode,
        public bool $fallbackUsed,
    ) {}

    /**
     * Crea payload para una respuesta exitosa del provider.
     */
    public static function fromResponse(
        AIResponse $response,
        int $latencyMs,
        bool $fallbackUsed = false,
    ): self {
        return new self(
            operation: 'generate',
            provider: $response->provider,
            model: $response->model,
            inputTokens: max(0, $response->inputTokens),
            outputTokens: max(0, $response->outputTokens),
            totalTokens: max(0, $response->totalTokens),
            latencyMs: $latencyMs,
            success: true,
            errorCode: null,
            fallbackUsed: $fallbackUsed,
        );
    }

    /**
     * Crea payload para una respuesta con error del provider.
     *
     * @param  AIErrorCode|null  $errorCode  Código de dominio del error, si disponible.
     */
    public static function fromError(
        ?AIErrorCode $errorCode,
        int $latencyMs,
        bool $fallbackUsed = true,
    ): self {
        return new self(
            operation: 'generate',
            provider: '',
            model: '',
            inputTokens: null,
            outputTokens: null,
            totalTokens: null,
            latencyMs: $latencyMs,
            success: false,
            errorCode: $errorCode?->value,
            fallbackUsed: $fallbackUsed,
        );
    }

    /**
     * Serializa a array seguro para guardar en FlowExecutionLog.payload.
     *
     * @return array{operation: string, provider: string, model: string,
     *               input_tokens: ?int, output_tokens: ?int, total_tokens: ?int,
     *               latency_ms: int, success: bool, error_code: ?string,
     *               fallback_used: bool}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'latency_ms' => $this->latencyMs,
            'success' => $this->success,
            'error_code' => $this->errorCode,
            'fallback_used' => $this->fallbackUsed,
        ];
    }
}
