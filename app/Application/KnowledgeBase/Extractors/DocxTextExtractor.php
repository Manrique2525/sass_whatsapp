<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Extractors;

use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\ValueObjects\DocumentTextExtractorInterface;
use App\Domain\KnowledgeBase\ValueObjects\ExtractedText;
use DOMDocument;
use DOMXPath;
use ZipArchive;

/**
 * Extracción segura de texto desde DOCX (FASE 17 U2.3).
 *
 * Nativo: ZipArchive + DOMDocument. Sin PHPWord.
 *
 * Seguridad:
 * - ZIP válido con entry count limitado
 * - Uncompressed size limitado + compression ratio check
 * - No Zip Slip (nombres de entry sanitizados)
 * - Lectura solo de word/document.xml (y opcionalmente headers/footers)
 * - No macros, no embedded objects, no XXE
 * - DOM sin network access
 * - Temp files cleanup en finally
 */
final class DocxTextExtractor implements DocumentTextExtractorInterface
{
    private const DOCUMENT_PATH = 'word/document.xml';

    public function extract(string $content, array $context = []): ExtractedText
    {
        $tmpFile = $this->createTempFile($content);

        try {
            $zip = $this->openZip($tmpFile);
            $this->validateZipEntries($zip);
            $documentXml = $this->readDocumentXml($zip, $tmpFile);
            $text = $this->extractTextFromXml($documentXml);

            $zip->close();

            $characterCount = mb_strlen($text, 'UTF-8');

            return new ExtractedText(
                text: $text,
                characterCount: $characterCount,
                metadata: ['format' => 'docx'],
            );
        } finally {
            $this->cleanupTempFile($tmpFile);
        }
    }

    private function createTempFile(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kb_docx_');

        if ($tmpFile === false) {
            throw new DocumentExtractionFailedException('no se pudo crear archivo temporal');
        }

        $written = file_put_contents($tmpFile, $content);

        if ($written === false) {
            @unlink($tmpFile);
            throw new DocumentExtractionFailedException('no se pudo escribir archivo temporal');
        }

        return $tmpFile;
    }

    private function openZip(string $tmpFile): ZipArchive
    {
        $zip = new ZipArchive;
        $opened = $zip->open($tmpFile);

        if ($opened !== true) {
            throw new DocumentExtractionFailedException('DOCX no es un ZIP válido');
        }

        return $zip;
    }

    private function validateZipEntries(ZipArchive $zip): void
    {
        $config = config('knowledge.extraction');

        $maxEntries = $config['docx_max_zip_entries'] ?? 500;
        $maxUncompressed = $config['docx_max_uncompressed_bytes'] ?? 50 * 1024 * 1024;
        $maxRatio = $config['docx_max_compression_ratio'] ?? 100;

        $numEntries = $zip->numFiles;

        if ($numEntries < 1 || $numEntries > $maxEntries) {
            $zip->close();
            throw new DocumentExtractionFailedException(
                "DOCX tiene {$numEntries} entradas (máximo: {$maxEntries})"
            );
        }

        $totalUncompressed = 0;

        for ($i = 0; $i < $numEntries; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            if (str_starts_with($name, '../') || str_starts_with($name, '..\\') || $name === '..' || str_contains($name, '/../') || str_contains($name, '\\..\\')) {
                $zip->close();
                throw new DocumentExtractionFailedException('DOCX contiene rutas peligrosas');
            }

            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $compressedSize = $stat['comp_size'];
            $uncompressedSize = $stat['size'];

            $totalUncompressed += $uncompressedSize;

            if ($totalUncompressed > $maxUncompressed) {
                $zip->close();
                throw new DocumentExtractionFailedException(
                    'DOCX excede tamaño uncompressed máximo'
                );
            }

            if ($compressedSize > 0 && $uncompressedSize > 0) {
                $ratio = $uncompressedSize / $compressedSize;

                if ($ratio > $maxRatio) {
                    $zip->close();
                    throw new DocumentExtractionFailedException(
                        'DOCX excede ratio de compresión máximo (posible ZIP bomb)'
                    );
                }
            }
        }
    }

    private function readDocumentXml(ZipArchive $zip, string $tmpFile): string
    {
        $xml = $zip->getFromName(self::DOCUMENT_PATH);

        if ($xml === false) {
            $zip->close();
            throw new DocumentExtractionFailedException(
                'DOCX no contiene word/document.xml'
            );
        }

        return $xml;
    }

    private function extractTextFromXml(string $xml): string
    {
        $this->assertNoDoctypeEntities($xml);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');

        $dom->substituteEntities = false;

        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        if (! $loaded) {
            libxml_clear_errors();
            throw new DocumentExtractionFailedException('DOCX contiene XML malformado');
        }

        libxml_clear_errors();

        $this->assertNoExternalEntities($dom);

        $xpath = new DOMXPath($dom);

        $textParts = [];

        $nodes = $xpath->query('//w:t | //w:tab | //w:br');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $lastParent = null;

        foreach ($nodes as $node) {
            $nodeName = $node->nodeName;

            if ($nodeName === 'w:tab') {
                $textParts[] = "\t";

                continue;
            }

            if ($nodeName === 'w:br') {
                $textParts[] = "\n";

                continue;
            }

            $currentParent = $node->parentNode;

            if ($lastParent !== null && $currentParent !== $lastParent) {
                $paragraphNode = $this->findAncestor($node, 'w:p');

                if ($paragraphNode !== null) {
                    $textParts[] = "\n";
                }
            }

            $textParts[] = $node->textContent;
            $lastParent = $currentParent;
        }

        $text = implode('', $textParts);

        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function assertNoDoctypeEntities(string $xml): void
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new DocumentExtractionFailedException(
                'DOCX contiene declaración DOCTYPE no permitida'
            );
        }

        if (preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new DocumentExtractionFailedException(
                'DOCX contiene definición de entidad no permitida'
            );
        }

        if (preg_match('/<!DOCTYPE[^>]*SYSTEM/i', $xml) === 1) {
            throw new DocumentExtractionFailedException(
                'DOCX contiene referencia DOCTYPE SYSTEM no permitida'
            );
        }
    }

    private function assertNoExternalEntities(DOMDocument $dom): void
    {
        $xp = new DOMXPath($dom);

        $entities = $xp->query('//entity');

        if ($entities !== false && $entities->length > 0) {
            throw new DocumentExtractionFailedException(
                'DOCX contiene entidades externas no permitidas'
            );
        }

        $systemIds = $xp->query('//@SYSTEM');
        if ($systemIds !== false && $systemIds->length > 0) {
            throw new DocumentExtractionFailedException(
                'DOCX contiene referencias SYSTEM no permitidas'
            );
        }
    }

    private function findAncestor(\DOMNode $node, string $tagName): ?\DOMNode
    {
        $current = $node->parentNode;

        while ($current !== null) {
            if ($current->nodeName === $tagName) {
                return $current;
            }
            $current = $current->parentNode;
        }

        return null;
    }

    private function cleanupTempFile(string $tmpFile): void
    {
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}
