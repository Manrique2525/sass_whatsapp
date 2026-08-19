<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTextTooLargeException;

/**
 * Normalización determinista de texto extraído (FASE 17 U2.3).
 *
 * Servicio puro y determinista. Sin AI, sin DB, sin tenant.
 *
 * Responsabilidades:
 * - UTF-8 valid
 * - Unicode NFC (si intl disponible)
 * - CRLF/CR → LF
 * - Remove NUL
 * - Strip disallowed control chars
 * - Normalize excessive spaces
 * - Preserve paragraph boundaries
 * - Trim
 */
final class TextNormalizer
{
    public function normalize(string $input): string
    {
        $text = $this->toNfc($input);

        $text = $this->normalizeLineEndings($text);

        $text = $this->removeNullBytes($text);

        $text = $this->stripControlChars($text);

        $text = $this->normalizeWhitespace($text);

        $text = trim($text);

        return $text;
    }

    public function normalizeAndValidate(string $input, int $maxSize): string
    {
        $text = $this->normalize($input);

        $charCount = mb_strlen($text, 'UTF-8');

        if ($charCount > $maxSize) {
            throw new DocumentTextTooLargeException($maxSize);
        }

        if ($charCount === 0) {
            throw new DocumentExtractionFailedException('extracción produjo texto vacío');
        }

        return $text;
    }

    private function toNfc(string $text): string
    {
        if (! class_exists(\Normalizer::class)) {
            return $text;
        }

        if (\Normalizer::isNormalized($text, \Normalizer::FORM_C)) {
            return $text;
        }

        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);

        return $normalized !== false ? $normalized : $text;
    }

    private function normalizeLineEndings(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        return $text;
    }

    private function removeNullBytes(string $text): string
    {
        return str_replace("\0", '', $text);
    }

    private function stripControlChars(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    }

    private function normalizeWhitespace(string $text): string
    {
        $lines = explode("\n", $text);

        $lines = array_map(function (string $line): string {
            $line = preg_replace('/[^\S\n]+/', ' ', $line);

            return $line !== null ? trim($line) : '';
        }, $lines);

        $result = [];
        $prevEmpty = false;

        foreach ($lines as $line) {
            if ($line === '') {
                if (! $prevEmpty) {
                    $result[] = '';
                    $prevEmpty = true;
                }
            } else {
                $result[] = $line;
                $prevEmpty = false;
            }
        }

        return implode("\n", $result);
    }
}
