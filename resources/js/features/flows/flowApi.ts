import type { Flow } from './flowTypes';
import type { ApiErrorPayload, FlowDraftPayload, FlowValidationResponse } from './flowEditorTypes';

/**
 * Cliente de API del editor de flujos (FASE 12). Reutiliza EXCLUSIVAMENTE los
 * endpoints de FASE 11: GET/PUT del flujo, validate, publish y deactivate.
 * Los errores se normalizan a `ApiErrorPayload` ({status, code, message}) para
 * que el editor distinga FLOW_CONFLICT, FLOW_INVALID, TENANT_NOT_ACTIVE, etc.
 */

function normalizeError(err: unknown, fallbackMessage: string): ApiErrorPayload {
    const response =
        typeof err === 'object' && err !== null && 'response' in err && err.response
            ? (err.response as { status?: number; data?: Record<string, unknown> })
            : null;

    const data = response?.data ?? null;
    const message = typeof data?.message === 'string' ? data.message : fallbackMessage;
    const code = typeof data?.code === 'string' ? data.code : 'ERROR';
    const errors = Array.isArray(data?.errors) ? data.errors.map(String) : undefined;

    return { status: response?.status ?? 0, code, message, errors };
}

async function unwrapFlow(promise: Promise<{ data: { flow: Flow } }>): Promise<Flow> {
    const res = await promise;

    return res.data.flow;
}

export async function fetchFlow(tenantId: string, flowId: string): Promise<Flow> {
    try {
        return await unwrapFlow(window.axios.get(`/api/v1/tenants/${tenantId}/flows/${flowId}`));
    } catch (err) {
        throw normalizeError(err, 'No se pudo cargar el flujo.');
    }
}

export async function saveDraft(tenantId: string, flowId: string, payload: FlowDraftPayload): Promise<Flow> {
    try {
        return await unwrapFlow(window.axios.put(`/api/v1/tenants/${tenantId}/flows/${flowId}/draft`, payload));
    } catch (err) {
        throw normalizeError(err, 'No se pudo guardar el borrador.');
    }
}

export async function validateFlow(tenantId: string, flowId: string): Promise<FlowValidationResponse> {
    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/flows/${flowId}/validate`);

        return { valid: res.data.valid === true, errors: Array.isArray(res.data.errors) ? res.data.errors.map(String) : [] };
    } catch (err) {
        throw normalizeError(err, 'No se pudo validar el flujo.');
    }
}

export async function publishFlow(tenantId: string, flowId: string): Promise<Flow> {
    try {
        return await unwrapFlow(window.axios.post(`/api/v1/tenants/${tenantId}/flows/${flowId}/publish`));
    } catch (err) {
        throw normalizeError(err, 'No se pudo publicar el flujo.');
    }
}

export async function deactivateFlow(tenantId: string, flowId: string): Promise<Flow> {
    try {
        return await unwrapFlow(window.axios.post(`/api/v1/tenants/${tenantId}/flows/${flowId}/deactivate`));
    } catch (err) {
        throw normalizeError(err, 'No se pudo desactivar el flujo.');
    }
}

export async function updateFlowMetadata(tenantId: string, flowId: string, data: { name: string; description: string | null }): Promise<Flow> {
    try {
        return await unwrapFlow(window.axios.patch(`/api/v1/tenants/${tenantId}/flows/${flowId}`, data));
    } catch (err) {
        throw normalizeError(err, 'No se pudo actualizar el flujo.');
    }
}
