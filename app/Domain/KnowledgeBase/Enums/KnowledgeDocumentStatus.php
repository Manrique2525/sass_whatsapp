<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Enums;

/**
 * Estados del procesamiento de un documento knowledge (FASE 17).
 *
 * uploaded  → archivo subido a S3, pendiente de procesamiento
 * processing → job de procesamiento en curso (extracción + chunking)
 * ready     → chunks generados y persistidos, documento queryable.
 *             Embedding = NULL (U3 materializará embeddings para retrieval semántico).
 *             ready = ingestion/chunking complete.
 * failed    → error durante el procesamiento (error_message sanitizado explica el motivo)
 */
enum KnowledgeDocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Pending processing',
            self::Processing => 'Processing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
