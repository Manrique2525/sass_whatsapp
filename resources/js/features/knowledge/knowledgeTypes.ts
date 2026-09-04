/**
 * Tipos de Knowledge Base (FASE 17 U3.5). Espejo de `KnowledgeBaseResource` backend.
 *
 * Solo lo necesario para el selector del AI node. NO incluye documents,
 * chunks, embeddings, storage ni datos internos.
 */

export interface KnowledgeBase {
    id: string;
    name: string;
    description: string | null;
    documents_count?: number;
    created_at: string;
    updated_at: string;
}

export interface KnowledgeBaseListResponse {
    knowledge_bases: KnowledgeBase[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export interface KnowledgeDocument {
    id: string;
    knowledge_base_id: string;
    original_filename: string;
    mime_type: string;
    file_size: number;
    status: string;
    chunk_count: number | null;
    total_tokens: number | null;
    processed_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface KnowledgeDocumentListResponse {
    documents: KnowledgeDocument[];
    meta: KnowledgeBaseListResponse['meta'];
}
