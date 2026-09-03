<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Services;

use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateValidationException;

/**
 * Valida variables de envío contra el schema NORMALIZADO de componentes de un
 * template (FASE 31 U5, ADR-121).
 *
 * Soporta placeholders `{{N}}` en componentes BODY y HEADER (tipo TEXT). Reglas:
 * - El número de variables debe coincidir exactamente con el número de
 *   placeholders requeridos (faltan o sobran → rechazo, 0 llamadas a Meta).
 * - Cada variable debe ser escalar; si no → rechazo con `malformed`.
 *
 * Devuelve la lista de parámetros normalizada lista para `sendTemplate`.
 */
final class TemplateVariableValidator
{
    /**
     * @param  list<array<string, mixed>>|null  $components
     * @param  list<mixed>  $variables
     * @return list<array<string, string>>
     *
     * @throws WhatsAppTemplateValidationException
     */
    public function validate(?array $components, array $variables): array
    {
        $required = $this->requiredPlaceholders($components);

        if ($required === 0) {
            if ($variables !== []) {
                throw new WhatsAppTemplateValidationException(
                    'El template no requiere variables.',
                );
            }

            return [];
        }

        if (count($variables) !== $required) {
            throw new WhatsAppTemplateValidationException(
                "El template requiere {$required} variable(s) y se recibieron ".count($variables).'.',
            );
        }

        $parameters = [];

        foreach ($variables as $variable) {
            if (! is_string($variable) && ! is_int($variable) && ! is_float($variable) && ! is_bool($variable)) {
                throw new WhatsAppTemplateValidationException(
                    'Alguna variable del template es inválida.',
                );
            }

            $text = (string) $variable;

            $parameters[] = ['type' => 'text', 'text' => $text];
        }

        return $parameters;
    }

    /**
     * @param  list<array<string, mixed>>|null  $components
     */
    private function requiredPlaceholders(?array $components): int
    {
        if ($components === null) {
            return 0;
        }

        $max = 0;

        foreach ($components as $component) {
            if ($component['type'] !== 'BODY' && $component['type'] !== 'HEADER') {
                continue;
            }

            $text = isset($component['text']) && is_string($component['text']) ? $component['text'] : '';

            if (preg_match_all('/{{\s*(\d+)\s*}}/', $text, $matches)) {
                foreach ($matches[1] as $index) {
                    $max = max($max, (int) $index);
                }
            }
        }

        return $max;
    }
}
