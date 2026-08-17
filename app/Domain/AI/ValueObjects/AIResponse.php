<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObjects;

/**
 * Resultado de una llamada al proveedor de IA (FASE 16).
 *
 * VO inmutable que encapsula la respuesta del proveedor incluyendo
 * contenido generado y métricas de uso de tokens. Diseñado para
 * telemetría sin acoplar al formato específico de ningún proveedor.
 */
final readonly class AIResponse
{
    /**
     * @param  string  $content  Texto generado por el modelo.
     * @param  string  $provider  Nombre del proveedor (e.g. 'openai').
     * @param  string  $model  Modelo utilizado (e.g. 'gpt-4o-mini').
     * @param  int  $inputTokens  Tokens consumidos en el prompt.
     * @param  int  $outputTokens  Tokens generados en la respuesta.
     * @param  int  $totalTokens  Suma de input + output tokens.
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
    ) {}
}
