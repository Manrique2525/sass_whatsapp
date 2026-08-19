<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Extractors;

use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTextTooLargeException;
use App\Domain\KnowledgeBase\ValueObjects\DocumentTextExtractorInterface;
use App\Domain\KnowledgeBase\ValueObjects\ExtractedText;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Exception\EmptyPdfException;
use Smalot\PdfParser\Exception\MissingPdfHeaderException;
use Smalot\PdfParser\Parser;

/**
 * Extracción segura de texto desde PDF (FASE 17 U2.3).
 *
 * Usa smalot/pdfparser para extraer texto plano de documentos PDF.
 * El PDF ya pasó validación en U2.2 (ext, MIME, magic bytes, size).
 *
 * Responsabilidad:
 * - PDF → ExtractedText (texto + characterCount + metadata)
 * - NO normaliza, NO chunka, NO persiste, NO embeddings
 *
 * Seguridad:
 * - Temp file con cleanup en finally
 * - No expone excepciones internas del parser
 * - No logs de contenido extraído
 * - Falla cerrado ante PDF corrupto/inválido/protegido
 */
final class PdfTextExtractor implements DocumentTextExtractorInterface
{
    public function extract(string $content, array $context = []): ExtractedText
    {
        $tmpFile = $this->createTempFile($content);

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($tmpFile);

            $text = $this->extractTextFromPdf($pdf);

            $characterCount = mb_strlen($text, 'UTF-8');

            $maxSize = (int) config('knowledge.extraction.max_extracted_text_size', 500 * 1024);

            if ($characterCount > $maxSize) {
                throw new DocumentTextTooLargeException($maxSize);
            }

            return new ExtractedText(
                text: $text,
                characterCount: $characterCount,
                metadata: ['format' => 'pdf', 'pages' => count($pdf->getPages())],
            );
        } catch (DocumentTextTooLargeException $e) {
            throw $e;
        } catch (DocumentExtractionFailedException $e) {
            throw $e;
        } catch (MissingPdfHeaderException $e) {
            throw new DocumentExtractionFailedException('PDF no tiene cabecera válida: '.$e->getMessage());
        } catch (EmptyPdfException $e) {
            throw new DocumentExtractionFailedException('PDF está vacío: '.$e->getMessage());
        } catch (\Throwable $e) {
            throw new DocumentExtractionFailedException('Error al extraer texto del PDF: '.$e->getMessage());
        } finally {
            $this->cleanupTempFile($tmpFile);
        }
    }

    private function extractTextFromPdf(Document $pdf): string
    {
        $pages = $pdf->getPages();

        if (count($pages) === 0) {
            return '';
        }

        $textParts = [];

        foreach ($pages as $page) {
            $pageText = $page->getText();

            if ($pageText !== '') {
                $textParts[] = $pageText;
            }
        }

        return implode("\n\n", $textParts);
    }

    private function createTempFile(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'kb_pdf_');

        if ($tmpFile === false) {
            throw new DocumentExtractionFailedException('no se pudo crear archivo temporal para PDF');
        }

        $written = file_put_contents($tmpFile, $content);

        if ($written === false) {
            @unlink($tmpFile);
            throw new DocumentExtractionFailedException('no se pudo escribir archivo temporal para PDF');
        }

        return $tmpFile;
    }

    private function cleanupTempFile(string $tmpFile): void
    {
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}
