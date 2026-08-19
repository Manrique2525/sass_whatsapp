<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Contrato para extractores de texto de documentos knowledge (FASE 17 U2.3).
 *
 * Responsabilidad: extraer texto crudo desde el contenido binario del archivo.
 * NO persiste chunks, NO cambia status, NO llama AI, NO conoce tenant.
 */
interface DocumentTextExtractorInterface
{
    /**
     * Extrae texto del contenido binario del archivo.
     *
     * @param  string  $content  Contenido binario raw del archivo.
     * @param  array<string, mixed>  $context  Metadata opcional (filename, mime).
     */
    public function extract(string $content, array $context = []): ExtractedText;
}
