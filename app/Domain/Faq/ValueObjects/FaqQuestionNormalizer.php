<?php

declare(strict_types=1);

namespace App\Domain\Faq\ValueObjects;

/**
 * Normalizador canónico de preguntas FAQ (FASE 18, ADR-069).
 *
 * Genera la representación canónica de una pregunta para:
 * - unicidad en DB (normalized_question)
 * - matching futuro del inbound contra FAQs almacenadas
 *
 * Contrato de normalización (MVP):
 * - trim
 * - Unicode lowercase (mb_strtolower UTF-8)
 * - NFC normalization (Normalizer::normalize)
 * - remover puntuación interrogativa/exclamativa final: ¿ ? ¡ ! . , : ;
 * - colapsar whitespace múltiple a un solo espacio
 *
 * Preserva INTENCIONALMENTE:
 * - acentos (á ≠ a)
 * - ñ (ñ ≠ n)
 * - emoji
 *
 * NO elimina stopwords.
 * NO hace accent folding.
 */
final class FaqQuestionNormalizer
{
    /**
     * Puntuación superficial a eliminar al inicio y final de la pregunta.
     *
     * Se eliminan SOLO en los bordes de la cadena ya trimmeada, NO en el
     * medio. Ejemplo: "¿Cómo estás? ¡Bien!" → "cómo estás? ¡bien"
     * se convierte en "cómo estás? ¡bien" (solo se remueve el ¿ inicial
     * y ! final).
     */
    private const EDGE_PUNCTUATION = '¿?!¡.,:;';

    public function normalize(string $question): string
    {
        $result = $question;

        // 1. Trim
        $result = trim($result);

        if ($result === '') {
            return '';
        }

        // 2. Unicode NFC normalization
        $result = \Normalizer::normalize($result, \Normalizer::FORM_C) ?? $result;

        // 3. Unicode lowercase
        $result = mb_strtolower($result, 'UTF-8');

        // 4. Remove edge punctuation (start and end only)
        $result = $this->stripEdgePunctuation($result);

        // 5. Collapse whitespace
        $result = preg_replace('/\s+/u', ' ', $result);

        return trim($result);
    }

    private function stripEdgePunctuation(string $text): string
    {
        $len = mb_strlen($text, 'UTF-8');

        if ($len === 0) {
            return $text;
        }

        // Strip from start
        $start = 0;
        while ($start < $len && str_contains(self::EDGE_PUNCTUATION, mb_substr($text, $start, 1, 'UTF-8'))) {
            $start++;
        }

        // Strip from end
        $end = $len;
        while ($end > $start && str_contains(self::EDGE_PUNCTUATION, mb_substr($text, $end - 1, 1, 'UTF-8'))) {
            $end--;
        }

        if ($start === 0 && $end === $len) {
            return $text;
        }

        return mb_substr($text, $start, $end - $start, 'UTF-8');
    }
}
