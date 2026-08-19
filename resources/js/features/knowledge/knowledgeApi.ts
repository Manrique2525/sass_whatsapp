import type { KnowledgeBaseListResponse } from './knowledgeTypes';

/**
 * Cliente API mínimo de Knowledge Base (FASE 17 U3.5).
 *
 * Solo listado para el selector del AI node. NO incluye CRUD completo,
 * upload de documentos ni gestión.
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
