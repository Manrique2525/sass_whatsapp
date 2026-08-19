<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Extractors;

use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\ValueObjects\DocumentTextExtractorInterface;
use App\Domain\KnowledgeBase\ValueObjects\ExtractedText;

/**
 * Extracción segura de texto plano (FASE 17 U2.3).
 *
 * Validaciones: UTF-8, BOM removal, null bytes, binary detection,
 * control chars. Conserva párrafos, newlines útiles, tabs relevantes.
 */
final class PlainTextExtractor implements DocumentTextExtractorInterface
{
    public function extract(string $content, array $context = []): ExtractedText
    {
        if ($content === '') {
            return new ExtractedText('', 0, ['format' => 'txt']);
        }

        $content = $this->removeBom($content);

        $this->assertNotBinary($content);

        $content = $this->ensureUtf8($content);

        $content = $this->removeControlChars($content);

        $characterCount = mb_strlen($content, 'UTF-8');

        return new ExtractedText(
            text: $content,
            characterCount: $characterCount,
            metadata: ['format' => 'txt'],
        );
    }

    private function removeBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    private function assertNotBinary(string $content): void
    {
        if (str_contains($content, "\0")) {
            throw new DocumentExtractionFailedException('archivo contiene null bytes');
        }

        $sample = substr($content, 0, 8192);
        $nullCount = substr_count($sample, "\0");

        if ($nullCount > 0) {
            throw new DocumentExtractionFailedException('archivo binario detectado');
        }

        $nonPrintable = preg_match_all('/[\x00-\x08\x0E-\x1F\x7F]/', $sample);

        if ($nonPrintable > strlen($sample) * 0.1) {
            throw new DocumentExtractionFailedException('archivo binario detectado');
        }
    }

    private function ensureUtf8(string $content): string
    {
        if ($this->isValidUtf8($content)) {
            return $content;
        }

        $cleaned = @mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        if ($this->isValidUtf8($cleaned)) {
            return $cleaned;
        }

        throw new DocumentExtractionFailedException('archivo no es UTF-8 válido');
    }

    private function isValidUtf8(string $content): bool
    {
        return preg_match('/^[\x{0000}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/u', $content) === 1;
    }

    private function removeControlChars(string $content): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content) ?? $content;
    }
}
