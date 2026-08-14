import type { MarkerType } from '@vue-flow/core';
import type { FlowConnection, FlowNode, FlowNodeType, FlowStatus } from './flowTypes';

/**
 * Tipos del editor visual de flujos (FASE 12).
 *
 * El editor trabaja con el modelo interno (`FlowEditorNode` / `FlowEditorEdge`)
 * y traduce a/desde los contratos de la API (FASE 11) con `flowAdapter.ts`. El
 * payload de guardado respeta EXACTAMENTE `ReplaceDraftRequest` (backend): nunca
 * se envía `tenant_id` desde el frontend (el backend lo fija), las posiciones
 * van redondeadas a enteros y el webhook solo expone `method`+`url`.
 */

export interface FlowEditorNodeData {
    type: FlowNodeType;
    typeLabel: string;
    name: string;
    isStart: boolean;
    config: Record<string, unknown> | null;
}

/**
 * Nodo del editor. Modelo propio (no el `GraphNode` interno de Vue Flow) para
 * no acoplarnos a sus tipos; se castea en el límite del canvas.
 */
export interface FlowEditorNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    data: FlowEditorNodeData;
    selected?: boolean;
}

/**
 * Arista del editor. `sourceHandle`/`label` llevan la rama de condición
 * ('true'/'false') y son deterministas (`e-{source}-{target}-{label}`).
 */
export interface FlowEditorEdge {
    id: string;
    source: string;
    target: string;
    label?: string;
    sourceHandle?: string | null;
    animated?: boolean;
    markerEnd?: MarkerType;
    selected?: boolean;
}

/**
 * Payload de `PUT /draft`: espejo de `ReplaceDraftRequest` (FASE 11). Los
 * campos `id`, `type`, `name`, `position_x`, `position_y`, `config`, `is_start`
 * y `connections[]` son los que el backend valida.
 */
export interface FlowDraftPayload {
    base_updated_at?: string | null;
    nodes: FlowNode[];
    connections: FlowConnection[];
}

export interface ConflictInfo {
    message: string;
    serverUpdatedAt: string | null;
}

/** Un issue de validación mostrado en el panel inferior del editor. */
export interface EditorValidationIssue {
    nodeId: string | null;
    message: string;
    severity: 'error' | 'warning';
    code: string;
}

export interface FlowValidationResponse {
    valid: boolean;
    errors: string[];
}

export type EditorLoadState = 'loading' | 'ready' | 'error';
export type EditorSaveState = 'idle' | 'saving' | 'saved' | 'error';

export type EditorPublishState =
    | 'idle'
    | 'publishing'
    | 'deactivating'
    | 'published'
    | 'inactive'
    | 'error';

export interface FlowEditorSelection {
    kind: 'node' | 'edge';
    id: string;
}

export interface FlowEditorContext {
    tenantId: string;
    chatbotId: string;
    flowId: string;
    canManage: boolean;
}

export type ApiErrorCode =
    | 'FLOW_CONFLICT'
    | 'FLOW_PUBLISHED'
    | 'FLOW_ALREADY_PUBLISHED'
    | 'FLOW_INVALID'
    | 'FLOW_INVALID_STATE'
    | 'TENANT_NOT_ACTIVE'
    | 'PERMISSION_DENIED'
    | 'NO_TENANT'
    | 'VALIDATION_ERROR'
    | string;

export interface ApiErrorPayload {
    status: number;
    code: ApiErrorCode;
    message: string;
    errors?: string[];
}

/** Mapa de tipo de nodo → config por defecto al crearlo (espejo del backend). */
export const DEFAULT_NODE_CONFIG: Record<FlowNodeType, Record<string, unknown>> = {
    message: { text: '' },
    buttons: { text: '', buttons: [{ id: 'opcion_1', title: '' }] },
    question: { text: '', prompt: '', field: '' },
    condition: { rules: [{ field: '', operator: 'equals', value: '' }] },
    delay: { seconds: 5 },
    tag: { tags: [''] },
    webhook: { url: '', method: 'POST' },
    ai: {},
    human: { handoff_message: '' },
    end: {},
};

/** Estado del flujo para el encabezado del editor (espejo de `FlowStatus`). */
export type EditorFlowStatus = FlowStatus;

export interface EditorStatusMessage {
    state: 'idle' | 'dirty' | 'saving' | 'saved' | 'error';
    text: string;
}
