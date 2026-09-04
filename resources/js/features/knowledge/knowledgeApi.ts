import type { KnowledgeBase, KnowledgeBaseListResponse, KnowledgeDocumentListResponse } from './knowledgeTypes';

/**
 * Cliente API de Knowledge Base para el selector del AI node y la gestión
 * operativa del workspace.
 */

function normalizeError(err: unknown, fallbackMessage: string): { status: number; code: string; message: string } {
    const response =
        typeof err === 'object' && err !== null && 'response' in err && err.response
            ? (err.response as { status?: number; data?: Record<string, unknown> })
            : null;

    const data = response?.data ?? null;

    return {
        status: response?.status ?? 0,
        code: typeof data?.code === 'string' ? data.code : 'ERROR',
        message: typeof data?.message === 'string' ? data.message : fallbackMessage,
    };
}

/**
 * Lista knowledge bases del tenant (paginado). Requiere permiso `knowledge.view`.
 *
 * GET /api/v1/tenants/{tenantId}/knowledge-bases
 */
export async function fetchKnowledgeBases(tenantId: string, params?: { search?: string; per_page?: number }): Promise<KnowledgeBaseListResponse> {
    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/knowledge-bases`, { params });

        return res.data as KnowledgeBaseListResponse;
    } catch (err) {
        throw normalizeError(err, 'No se pudieron cargar las bases de conocimiento.');
    }
}

export async function createKnowledgeBase(tenantId: string, payload: { name: string; description?: string }): Promise<KnowledgeBase> {
    const res = await window.axios.post(`/api/v1/tenants/${tenantId}/knowledge-bases`, payload);
    return res.data.knowledge_base as KnowledgeBase;
}

export async function fetchKnowledgeDocuments(tenantId: string, knowledgeBaseId: string): Promise<KnowledgeDocumentListResponse> {
    const res = await window.axios.get(`/api/v1/tenants/${tenantId}/knowledge-bases/${knowledgeBaseId}/documents`);
    return res.data as KnowledgeDocumentListResponse;
}

export async function uploadKnowledgeDocument(tenantId: string, knowledgeBaseId: string, file: File): Promise<void> {
    const formData = new FormData();
    formData.append('file', file);
    await window.axios.post(`/api/v1/tenants/${tenantId}/knowledge-bases/${knowledgeBaseId}/documents`, formData);
}
