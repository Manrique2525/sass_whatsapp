<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Domain\KnowledgeBase\Exceptions\DocumentInvalidFileException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTooLargeException;
use App\Domain\KnowledgeBase\Exceptions\DocumentUnsupportedTypeException;
use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Validación de seguridad para uploads de documentos knowledge (FASE 17 U2.2).
 *
 * Capa de defensa en profundidad: extensión, MIME server-side, magic bytes,
 * tamaño, DOCX structure, filename safety, empty file, binary masquerading.
 *
 * NO extrae texto. NO parsea PDF más allá de signature. NO parsea DOCX completo.
 */
final class DocumentUploadValidator
{
    /**
     * Magic bytes conocidos por extensión.
     *
     * @var array<string, list{string, int}>
     */
    private const MAGIC_BYTES = [
        'pdf' => ['%PDF', 0],
        'docx' => ['PK', 0],
    ];

    public function validate(UploadedFile $file, array $config): void
    {
        $this->validateFileSize($file, $config['max_file_size']);
        $this->validateFilename($file);
        $this->validateExtension($file, $config['allowed_extensions']);
        $this->validateMimeType($file, $config['allowed_mime_types']);
        $this->validateMagicBytes($file);
        $this->validateNotEmpty($file);

        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'docx') {
            $this->validateDocxStructure($file);
        }

        if ($extension === 'txt') {
            $this->validateTextFile($file);
        }
    }

    private function validateFileSize(UploadedFile $file, int $maxBytes): void
    {
        if ($file->getSize() > $maxBytes) {
            throw new DocumentTooLargeException($maxBytes);
        }
    }

    private function validateFilename(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();

        if (str_contains($name, "\0")) {
            throw new DocumentInvalidFileException('nombre contiene null bytes');
        }

        if (preg_match('#[/\\\\]#', $name) === 1) {
            throw new DocumentInvalidFileException('nombre contiene separadores de ruta');
        }

        if (preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new DocumentInvalidFileException('nombre contiene caracteres de control');
        }
    }

    private function validateExtension(UploadedFile $file, array $allowed): void
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === '' || ! in_array($ext, $allowed, true)) {
            throw new DocumentUnsupportedTypeException($ext ?: '(sin extensión)');
        }
    }

    private function validateMimeType(UploadedFile $file, array $allowed): void
    {
        $serverMime = $this->detectServerMime($file);
        $ext = strtolower($file->getClientOriginalExtension());

        if ($serverMime === false) {
            throw new DocumentUnsupportedTypeException('(MIME no detectado)');
        }

        if (in_array($serverMime, $allowed, true)) {
            return;
        }

        if ($ext === 'docx' && $serverMime === 'application/zip') {
            return;
        }

        throw new DocumentUnsupportedTypeException($serverMime);
    }

    private function validateMagicBytes(UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (! isset(self::MAGIC_BYTES[$ext])) {
            return;
        }

        [$expected, $offset] = self::MAGIC_BYTES[$ext];

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new DocumentInvalidFileException('no se puede leer el archivo');
        }

        $sample = fread($handle, $offset + strlen($expected) + 4);
        fclose($handle);

        if ($sample === false || substr($sample, $offset, strlen($expected)) !== $expected) {
            throw new DocumentInvalidFileException('magic bytes no coinciden con la extensión');
        }
    }

    private function validateNotEmpty(UploadedFile $file): void
    {
        if ($file->getSize() === 0) {
            throw new DocumentInvalidFileException('archivo vacío');
        }
    }

    private function validateDocxStructure(UploadedFile $file): void
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new DocumentInvalidFileException('no se puede acceder al archivo');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($realPath);

        if ($opened !== true) {
            throw new DocumentInvalidFileException('DOCX no es un ZIP válido');
        }

        $numEntries = $zip->numFiles;

        if ($numEntries < 1 || $numEntries > 500) {
            $zip->close();
            throw new DocumentInvalidFileException('DOCX tiene un número inesperado de entradas');
        }

        $hasContentTypes = false;
        $hasDocument = false;

        for ($i = 0; $i < $numEntries; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            if (str_starts_with($name, '../') || str_contains($name, '..')) {
                $zip->close();
                throw new DocumentInvalidFileException('DOCX contiene rutas peligrosas');
            }

            if ($name === '[Content_Types].xml') {
                $hasContentTypes = true;
            }

            if ($name === 'word/document.xml') {
                $hasDocument = true;
            }
        }

        $zip->close();

        if (! $hasContentTypes || ! $hasDocument) {
            throw new DocumentInvalidFileException('DOCX no contiene la estructura requerida');
        }
    }

    private function validateTextFile(UploadedFile $file): void
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new DocumentInvalidFileException('no se puede acceder al archivo');
        }

        $sample = file_get_contents($realPath, false, null, 0, 8192);

        if ($sample === false) {
            throw new DocumentInvalidFileException('no se puede leer el archivo');
        }

        if (str_contains($sample, "\0")) {
            throw new DocumentInvalidFileException('archivo binario detectado como texto');
        }

        $decoded = mb_convert_encoding($sample, 'UTF-8', 'UTF-8');

        if ($decoded === false || $decoded !== $sample) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $sample);

            if ($cleaned === false || $cleaned !== $sample) {
                throw new DocumentInvalidFileException('archivo no es UTF-8 válido');
            }
        }
    }

    private function detectServerMime(UploadedFile $file): string|false
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($realPath);
    }
}
