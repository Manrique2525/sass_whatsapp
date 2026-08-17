<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObjects;

/**
 * Solicitud de generación de respuesta IA (FASE 16).
 *
 * VO inmutable que encapsula todos los datos necesarios para una llamada
 * al proveedor de IA. El dominio construye este objeto; el provider lo consume.
 */
final readonly class AIRequest
{
    /**
     * @param  string  $prompt  Prompt del usuario con variables ya resueltas.
     * @param  string|null  $systemPrompt  Instrucciones de sistema del negocio.
     * @param  string  $model  Modelo a utilizar (override del default del provider).
     * @param  float  $temperature  Sampling temperature (0.0 - 2.0).
     * @param  int  $maxTokens  Límite máximo de tokens de salida.
     */
    public function __construct(
        public string $prompt,
        public ?string $systemPrompt = null,
        public string $model = '',
        public float $temperature = 0.7,
        public int $maxTokens = 500,
    ) {}
}
