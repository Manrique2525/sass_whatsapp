import { MarkerType } from '@vue-flow/core';
import type { Connection } from '@vue-flow/core';
import type { FlowConnection, FlowNode, FlowNodeType } from './flowTypes';
import type { FlowDraftPayload, FlowEditorEdge, FlowEditorNode } from './flowEditorTypes';
import { nodeTypeLabel } from './flowUtils';

/**
 * Adaptador entre el grafo de Vue Flow (FASE 12) y el contrato de la API
 * (FASE 11). El backend es la autoridad: este adaptador solo traduce.
 *
 * Reglas de traducción:
 * - Los ids de nodo/arista son deterministas (el cliente los genera, UUID v4
 *   para nodos y `e-{source}-{target}-{label}` para aristas).
 * - Las posiciones se redondean a enteros (`position_x/y` son integer en el
 *   backend y `ReplaceDraftRequest` los exige).
 * - En nodos `condition` el label de la conexión ES el handle ('true'/'false').
 * - El webhook nunca envía `headers`/`payload` desde el frontend (ADR-044).
 * - `tenant_id` nunca viaja en el payload (el backend lo fija).
 */

export const CONDITION_TRUE = 'true';
export const CONDITION_FALSE = 'false';

export function edgeIdFor(source: string, target: string, label?: string | null): string {
    return `e-${source}-${target}-${label ?? ''}`;
}

export function isTerminalNodeType(type: FlowNodeType): boolean {
    return type === 'end' || type === 'human';
}

export function canNodeBeStart(type: FlowNodeType): boolean {
    return type !== 'ai' && !isTerminalNodeType(type);
}

export function apiNodeToEditor(node: FlowNode): FlowEditorNode {
    return {
        id: node.id,
        type: node.type,
        position: { x: node.position_x, y: node.position_y },
        data: {
            type: node.type,
            typeLabel: node.type_label,
            name: node.name,
            isStart: node.is_start,
            config: node.config,
        },
    };
}

export function editorNodeToApi(node: FlowEditorNode): FlowNode {
    return {
        id: node.id,
        type: node.data.type,
        type_label: node.data.typeLabel,
        name: node.data.name,
        position_x: Math.round(node.position.x),
        position_y: Math.round(node.position.y),
        config: node.data.config,
        is_start: node.data.isStart,
    };
}

export function apiConnectionToEdge(connection: FlowConnection): FlowEditorEdge {
    const label = connection.label ?? null;

    return {
        id: edgeIdFor(connection.source_node_id, connection.target_node_id, label),
        source: connection.source_node_id,
        target: connection.target_node_id,
        label: label ?? undefined,
        sourceHandle: label,
        animated: true,
        markerEnd: MarkerType.ArrowClosed,
    };
}

export function editorEdgeToApi(edge: FlowEditorEdge): FlowConnection {
    const label = edge.sourceHandle || edge.label || null;

    return {
        id: edge.id,
        source_node_id: edge.source,
        target_node_id: edge.target,
        label,
    };
}

export interface ApiGraph {
    nodes: FlowNode[];
    connections: FlowConnection[];
}

export function apiToGraph(apiNodes: FlowNode[] | undefined, apiConnections: FlowConnection[] | undefined): { nodes: FlowEditorNode[]; edges: FlowEditorEdge[] } {
    const nodes = (apiNodes ?? []).map(apiNodeToEditor);
    const edges = (apiConnections ?? []).map(apiConnectionToEdge);

    return { nodes, edges };
}

export function graphToDraft(
    nodes: FlowEditorNode[],
    edges: FlowEditorEdge[],
    baseUpdatedAt: string | null,
): FlowDraftPayload {
    return {
        base_updated_at: baseUpdatedAt ?? undefined,
        nodes: nodes.map(editorNodeToApi),
        connections: edges.map(editorEdgeToApi),
    };
}

/**
 * Compara dos grafos normalizados (por id de nodo y por id de arista) para
 * detectar cambios (usado por el dirty state del editor).
 */
export function graphSignature(nodes: FlowEditorNode[], edges: FlowEditorEdge[]): string {
    const sortedNodes = [...nodes].sort((a, b) => a.id.localeCompare(b.id)).map(editorNodeToApi);
    const sortedEdges = [...edges].sort((a, b) => a.id.localeCompare(b.id)).map(editorEdgeToApi);

    return JSON.stringify({ nodes: sortedNodes, edges: sortedEdges });
}

export function createEditorNode(type: FlowNodeType, id: string, position: { x: number; y: number }, config: Record<string, unknown> | null = null, name?: string): FlowEditorNode {
    return {
        id,
        type,
        position,
        data: {
            type,
            typeLabel: nodeTypeLabel(type),
            name: name ?? nodeTypeLabel(type),
            isStart: false,
            config,
        },
    };
}

export interface ConnectionVerdict {
    ok: boolean;
    reason?: string;
}

/**
 * Espejo UX de las reglas de grafo del `FlowValidator` (el backend sigue
 * siendo la autoridad): evita ciclos, self-loops, conexiones a un inicio,
 * más de una salida sin label, y las ramas true/false duplicadas.
 */
export function canCreateConnection(
    connection: Connection,
    nodes: FlowEditorNode[],
    edges: FlowEditorEdge[],
): ConnectionVerdict {
    const source = nodes.find((node) => node.id === connection.source);
    const target = nodes.find((node) => node.id === connection.target);

    if (!source || !target) {
        return { ok: false, reason: 'El nodo de origen o destino no existe.' };
    }

    if (connection.source === connection.target) {
        return { ok: false, reason: 'Un nodo no puede conectarse consigo mismo.' };
    }

    if (target.data.isStart) {
        return { ok: false, reason: 'El nodo de inicio no puede ser destino de una conexión.' };
    }

    if (isTerminalNodeType(source.data.type)) {
        return { ok: false, reason: 'Este nodo es terminal y no puede tener conexiones salientes.' };
    }

    if (source.data.type === 'condition') {
        const handle = String(connection.sourceHandle ?? '');
        if (handle !== CONDITION_TRUE && handle !== CONDITION_FALSE) {
            return { ok: false, reason: 'La condición exige conectar desde las ramas "true" o "false".' };
        }

        const existingOfBranch = edges.filter(
            (edge) => edge.source === source.id && (edge.sourceHandle || edge.label || '') === handle,
        );

        if (existingOfBranch.length > 0) {
            return { ok: false, reason: `La rama "${handle}" ya está conectada (solo una por rama).` };
        }

        return { ok: true };
    }

    const existingOutgoing = edges.filter((edge) => edge.source === source.id);
    if (existingOutgoing.length > 0) {
        return { ok: false, reason: 'Este nodo ya tiene una conexión saliente (solo una).' };
    }

    return { ok: true };
}
