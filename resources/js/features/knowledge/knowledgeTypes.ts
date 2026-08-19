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
