<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Extractors;

use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\ValueObjects\DocumentTextExtractorInterface;

/**
 * Resolución de extractors por MIME type (FASE 17 U2.3).
 *
 * Registry simple sin service locator global.
 */
final class DocumentTextExtractorFactory
{
    /** @var array<string, DocumentTextExtractorInterface> */
    private array $registry;

    public function __construct(
        PlainTextExtractor $plainText,
        DocxTextExtractor $docxText,
        PdfTextExtractor $pdfText,
    ) {
        $this->registry = [
            'text/plain' => $plainText,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $docxText,
            'application/zip' => $docxText, // DOCX finfo bypass
            'application/pdf' => $pdfText,
        ];
    }

    public function resolve(string $mimeType): DocumentTextExtractorInterface
    {
        $extractor = $this->registry[$mimeType] ?? null;

        if ($extractor === null) {
            throw new DocumentExtractionFailedException(
                "No hay extractor disponible para MIME \"{$mimeType}\""
            );
        }

        return $extractor;
    }

    /**
     * @return string[]
     */
    public function supportedMimeTypes(): array
    {
        return array_keys($this->registry);
    }
}
