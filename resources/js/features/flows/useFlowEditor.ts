import { computed, ref } from 'vue';
import { applyNodeChanges, applyEdgeChanges, MarkerType } from '@vue-flow/core';
import type { Connection, EdgeChange, NodeChange } from '@vue-flow/core';
import {
    apiToGraph,
    canCreateConnection,
    createEditorNode,
    edgeIdFor,
    graphSignature,
    graphToDraft,
} from './flowAdapter';
import {
    deactivateFlow,
    fetchFlow,
    publishFlow,
    saveDraft,
    updateFlowMetadata,
    validateFlow,
} from './flowApi';
import type {
    ApiErrorPayload,
    ConflictInfo,
    EditorFlowStatus,
    EditorLoadState,
    EditorPublishState,
    EditorSaveState,
    FlowDraftPayload,
    FlowEditorContext,
    FlowEditorEdge,
    FlowEditorNode,
    FlowEditorSelection,
    FlowValidationResponse,
} from './flowEditorTypes';
import { localGraphIssues, mapBackendErrors } from './flowValidation';
import type { Flow, FlowNodeType } from './flowTypes';
import { useEditorHistory } from './useEditorHistory';

/**
 * Controller del editor visual de flujos (FASE 12, ADR-040/041/042).
 *
 * - Estado reactivo de carga/guardado/publicación/conflicto/validación.
 * - Traducción API ↔ Vue Flow vía `flowAdapter` (el backend es la autoridad).
 * - Lock optimista: `base_updated_at` se reenvía en cada `PUT /draft`; un 409
 *   `FLOW_CONFLICT` abre el modal (recargar / seguir editando / sobrescribir
 *   explícito). Jamás se sobrescribe en silencio.
 * - `dirty` reactivo: compara el grafo serializado contra el último guardado.
 */
export function useFlowEditor(context: FlowEditorContext) {
    const { tenantId, chatbotId, flowId, canManage } = context;

    const flow = ref<Flow | null>(null);
    const nodes = ref<FlowEditorNode[]>([]);
    const edges = ref<FlowEditorEdge[]>([]);

    const loadState = ref<EditorLoadState>('loading');
    const saveState = ref<EditorSaveState>('idle');
    const publishState = ref<EditorPublishState>('idle');
    const error = ref<string | null>(null);
    const connectError = ref<string | null>(null);
    const conflict = ref<ConflictInfo | null>(null);
    const validation = ref<FlowValidationResponse | null>(null);

    const selected = ref<FlowEditorSelection | null>(null);
    const centerRequest = ref<{ nodeId: string; nonce: number } | null>(null);

    const flowName = ref('');
    const flowDescription = ref<string>('');

    const baseUpdatedAt = ref<string | null>(null);
    const savedSignature = ref('');
    const savedMeta = ref({ name: '', description: '' });

    const history = useEditorHistory();

    const flowStatus = computed<EditorFlowStatus>(() => flow.value?.status ?? 'draft');
    const canEdit = computed(() => canManage && flowStatus.value !== 'published');
    const readOnly = computed(() => !canEdit.value);
    const empty = computed(() => nodes.value.length === 0);

    const graphDirty = computed(() => graphSignature(nodes.value, edges.value) !== savedSignature.value);
    const metaDirty = computed(
        () => flowName.value !== savedMeta.value.name || flowDescription.value !== savedMeta.value.description,
    );
    const dirty = computed(() => graphDirty.value || metaDirty.value);

    const validationIssues = computed(() => {
        if (validation.value && !validation.value.valid) {
            return mapBackendErrors(validation.value.errors, nodes.value);
        }

        return localGraphIssues(nodes.value, edges.value);
    });

    function currentSnapshot(): { nodes: FlowEditorNode[]; edges: FlowEditorEdge[] } {
        return JSON.parse(JSON.stringify({ nodes: nodes.value, edges: edges.value }));
    }

    function applyFlow(updated: Flow): void {
        flow.value = updated;
        flowName.value = updated.name;
        flowDescription.value = updated.description ?? '';
        savedMeta.value = { name: updated.name, description: updated.description ?? '' };
        baseUpdatedAt.value = updated.updated_at;
        savedSignature.value = graphSignature(nodes.value, edges.value);
        validation.value = null;
        connectError.value = null;
    }

    async function load(): Promise<void> {
        loadState.value = 'loading';
        error.value = null;

        try {
            const loaded = await fetchFlow(tenantId, flowId);
            const { nodes: apiNodes, edges: apiEdges } = apiToGraph(loaded.nodes, loaded.connections);

            nodes.value = apiNodes;
            edges.value = apiEdges;
            selected.value = null;
            applyFlow(loaded);
            loadState.value = 'ready';
        } catch (err) {
            const apiError = err as ApiErrorPayload;
            error.value = apiError.message;
            loadState.value = 'error';
        }
    }

    async function reloadFromServer(): Promise<void> {
        conflict.value = null;
        await load();
    }

    function clearConflict(): void {
        conflict.value = null;
        connectError.value = null;
    }

    function mutate(fn: () => void): void {
        if (readOnly.value) {
            return;
        }

        history.push(currentSnapshot());
        fn();
    }

    function focusNode(nodeId: string): void {
        for (const n of nodes.value) {
            n.selected = n.id === nodeId;
        }
        selected.value = { kind: 'node', id: nodeId };
        centerRequest.value = { nodeId, nonce: (centerRequest.value?.nonce ?? 0) + 1 };
    }

    // ------------------------------------------------------------- Vue Flow

    const dragSnapshotTaken = ref(false);

    function onNodesChange(changes: NodeChange[]): void {
        const mutating = changes.some((change) =>
            ['position', 'add', 'remove', 'replace'].includes(change.type),
        );

        if (mutating) {
            const isDrag = changes.some((change) => change.type === 'position' && change.dragging === true);
            if (isDrag) {
                if (!dragSnapshotTaken.value) {
                    history.push(currentSnapshot());
                    dragSnapshotTaken.value = true;
                }
            } else {
                history.push(currentSnapshot());
            }
        }

        nodes.value = applyNodeChanges(
            changes as never,
            nodes.value as never,
        ) as unknown as FlowEditorNode[];
        syncSelection();
    }

    function onNodeDragStop(): void {
        dragSnapshotTaken.value = false;
    }

    function onEdgesChange(changes: EdgeChange[]): void {
        const mutating = changes.some((change) => ['add', 'remove', 'replace'].includes(change.type));
        if (mutating) {
            history.push(currentSnapshot());
        }

        edges.value = applyEdgeChanges(
            changes as never,
            edges.value as never,
        ) as unknown as FlowEditorEdge[];
        syncSelection();
    }

    /**
     * Reconstruye `selected` a partir del flag `selected` que Vue Flow propaga
     * en los cambios de selección (no hay evento `selection-change` en v1.48;
     * la selección llega como cambios `select` de nodos/aristas).
     */
    function syncSelection(): void {
        const selectedNodes = nodes.value.filter((node) => node.selected === true);
        const selectedEdges = edges.value.filter((edge) => edge.selected === true);

        if (selectedNodes.length === 1 && selectedEdges.length === 0) {
            selected.value = { kind: 'node', id: selectedNodes[0].id };
        } else if (selectedNodes.length === 0 && selectedEdges.length === 1) {
            selected.value = { kind: 'edge', id: selectedEdges[0].id };
        } else if (selectedNodes.length === 0 && selectedEdges.length === 0) {
            selected.value = null;
        }
    }

    function onConnect(connection: Connection): void {
        connectError.value = null;

        if (readOnly.value) {
            return;
        }

        const verdict = canCreateConnection(connection, nodes.value, edges.value);
        if (!verdict.ok) {
            connectError.value = verdict.reason ?? 'Conexión inválida.';
            return;
        }

        const label = connection.sourceHandle ?? null;
        const edge: FlowEditorEdge = {
            id: edgeIdFor(connection.source, connection.target, label),
            source: connection.source,
            target: connection.target,
            label: label ?? undefined,
            sourceHandle: label,
            animated: true,
            markerEnd: MarkerType.ArrowClosed,
        };

        mutate(() => {
            edges.value = [...edges.value, edge];
        });
    }

    function clearSelection(): void {
        for (const n of nodes.value) {
            n.selected = false;
        }
        for (const e of edges.value) {
            e.selected = false;
        }
        selected.value = null;
    }

    // ------------------------------------------------------- Acciones de grafo

    function addNode(type: FlowNodeType, position: { x: number; y: number }): FlowEditorNode | null {
        if (readOnly.value || type === 'ai') {
            return null;
        }

        const node = createEditorNode(type, crypto.randomUUID(), { x: Math.round(position.x), y: Math.round(position.y) });
        mutate(() => {
            nodes.value = [...nodes.value, node];
        });

        return node;
    }

    function removeNodes(ids: string[]): void {
        if (readOnly.value) {
            return;
        }

        const idSet = new Set(ids);
        mutate(() => {
            nodes.value = nodes.value.filter((node) => !idSet.has(node.id));
            edges.value = edges.value.filter((edge) => !idSet.has(edge.source) || !idSet.has(edge.target));
        });

        if (selected.value?.kind === 'node' && idSet.has(selected.value.id)) {
            selected.value = null;
        }
    }

    function removeNode(id: string): void {
        removeNodes([id]);
    }

    function duplicateNode(id: string): FlowEditorNode | null {
        if (readOnly.value) {
            return null;
        }

        const source = nodes.value.find((node) => node.id === id);
        if (!source) {
            return null;
        }

        const copy = createEditorNode(
            source.data.type,
            crypto.randomUUID(),
            { x: source.position.x + 40, y: source.position.y + 40 },
            JSON.parse(JSON.stringify(source.data.config)),
            `${source.data.name} (copia)`,
        );

        mutate(() => {
            nodes.value = [...nodes.value, copy];
        });

        return copy;
    }

    function removeEdge(id: string): void {
        if (readOnly.value) {
            return;
        }

        mutate(() => {
            edges.value = edges.value.filter((edge) => edge.id !== id);
        });
        if (selected.value?.kind === 'edge' && selected.value.id === id) {
            selected.value = null;
        }
    }

    function updateNodeConfig(id: string, config: Record<string, unknown>): void {
        mutate(() => {
            const node = nodes.value.find((node) => node.id === id);
            if (node) {
                node.data.config = JSON.parse(JSON.stringify(config));
            }
        });
    }

    function updateNodeName(id: string, name: string): void {
        mutate(() => {
            const node = nodes.value.find((node) => node.id === id);
            if (node) {
                node.data.name = name;
            }
        });
    }

    function updateNodePosition(id: string, position: { x: number; y: number }): void {
        mutate(() => {
            const node = nodes.value.find((node) => node.id === id);
            if (node) {
                node.position = { x: Math.round(position.x), y: Math.round(position.y) };
            }
        });
    }

    function setStartNode(id: string): void {
        if (readOnly.value) {
            return;
        }

        const node = nodes.value.find((node) => node.id === id);
        if (!node || isTerminalNode(node.data.type)) {
            return;
        }

        mutate(() => {
            for (const n of nodes.value) {
                n.data.isStart = n.id === id;
            }
        });
    }

    function clearStartNode(id: string): void {
        if (readOnly.value) {
            return;
        }

        mutate(() => {
            const node = nodes.value.find((node) => node.id === id);
            if (node) {
                node.data.isStart = false;
            }
        });
    }

    function updateFlowMeta(name: string, description: string): void {
        if (readOnly.value) {
            return;
        }

        flowName.value = name;
        flowDescription.value = description;
    }

    // ------------------------------------------------------------- Guardar

    async function save(): Promise<boolean> {
        if (readOnly.value) {
            return false;
        }

        if (!dirty.value) {
            saveState.value = 'idle';
            return true;
        }

        saveState.value = 'saving';
        error.value = null;

        try {
            let updated = flow.value;

            if (metaDirty.value && updated) {
                updated = await updateFlowMetadata(tenantId, flowId, {
                    name: flowName.value.trim() || updated.name,
                    description: flowDescription.value.trim() === '' ? null : flowDescription.value.trim(),
                });
                baseUpdatedAt.value = updated.updated_at;
            }

            if (graphDirty.value) {
                const payload: FlowDraftPayload = graphToDraft(nodes.value, edges.value, baseUpdatedAt.value);
                updated = await saveDraft(tenantId, flowId, payload);
            }

            if (updated) {
                applyFlow(updated);
            }

            saveState.value = 'saved';
            history.clear();
            return true;
        } catch (err) {
            const apiError = err as ApiErrorPayload;

            if (apiError.code === 'FLOW_CONFLICT') {
                conflict.value = { message: apiError.message, serverUpdatedAt: null };
                saveState.value = 'idle';
            } else if (apiError.code === 'FLOW_INVALID') {
                validation.value = { valid: false, errors: apiError.errors ?? [] };
                saveState.value = 'error';
                error.value = apiError.message;
            } else {
                saveState.value = 'error';
                error.value = apiError.message;
            }

            return false;
        }
    }

    async function saveOverriding(): Promise<boolean> {
        if (readOnly.value || !flow.value) {
            return false;
        }

        saveState.value = 'saving';
        error.value = null;

        try {
            const payload: FlowDraftPayload = graphToDraft(nodes.value, edges.value, null);
            const updated = await saveDraft(tenantId, flowId, payload);
            applyFlow(updated);
            saveState.value = 'saved';
            conflict.value = null;
            history.clear();
            return true;
        } catch (err) {
            const apiError = err as ApiErrorPayload;
            saveState.value = 'error';
            error.value = apiError.message;
            return false;
        }
    }

    // ---------------------------------------------------------- Validar / publicar

    async function validate(): Promise<void> {
        if (!flow.value) {
            return;
        }

        if (dirty.value) {
            const ok = await save();
            if (!ok) {
                return;
            }
        }

        try {
            const result = await validateFlow(tenantId, flowId);
            validation.value = result;
            error.value = null;
        } catch (err) {
            const apiError = err as ApiErrorPayload;
            error.value = apiError.message;
        }
    }

    async function publish(): Promise<void> {
        if (readOnly.value || !flow.value) {
            return;
        }

        if (dirty.value) {
            const ok = await save();
            if (!ok) {
                return;
            }
        }

        publishState.value = 'publishing';
        error.value = null;
        validation.value = null;

        try {
            const published = await publishFlow(tenantId, flowId);
            applyFlow(published);
            publishState.value = 'published';
        } catch (err) {
            const apiError = err as ApiErrorPayload;

            if (apiError.code === 'FLOW_INVALID') {
                validation.value = { valid: false, errors: apiError.errors ?? [] };
            } else if (apiError.code === 'FLOW_CONFLICT') {
                conflict.value = { message: apiError.message, serverUpdatedAt: null };
            }

            publishState.value = 'error';
            error.value = apiError.message;
        }
    }

    async function deactivate(): Promise<void> {
        if (!flow.value) {
            return;
        }

        publishState.value = 'deactivating';
        error.value = null;

        try {
            const deactivated = await deactivateFlow(tenantId, flowId);
            applyFlow(deactivated);
            publishState.value = 'inactive';
        } catch (err) {
            const apiError = err as ApiErrorPayload;
            publishState.value = 'error';
            error.value = apiError.message;
        }
    }

    // ------------------------------------------------------------- Deshacer

    function undo(): void {
        const snapshot = history.undo(currentSnapshot());
        if (snapshot) {
            nodes.value = snapshot.nodes;
            edges.value = snapshot.edges;
        }
    }

    function redo(): void {
        const snapshot = history.redo(currentSnapshot());
        if (snapshot) {
            nodes.value = snapshot.nodes;
            edges.value = snapshot.edges;
        }
    }

    function isStartNode(id: string): boolean {
        return nodes.value.some((node) => node.id === id && node.data.isStart);
    }

    return {
        // contexto
        tenantId,
        chatbotId,
        flowId,
        canManage,

        // estado
        flow,
        flowName,
        flowDescription,
        flowStatus,
        nodes,
        edges,
        selected,
        centerRequest,
        loadState,
        saveState,
        publishState,
        error,
        connectError,
        conflict,
        validation,
        validationIssues,
        baseUpdatedAt,
        readOnly,
        dirty,
        empty,
        canUndo: history.canUndo,
        canRedo: history.canRedo,

        // ciclo de vida
        load,
        reloadFromServer,
        clearConflict,

        // grafo
        addNode,
        removeNode,
        removeNodes,
        duplicateNode,
        removeEdge,
        updateNodeConfig,
        updateNodeName,
        updateNodePosition,
        setStartNode,
        clearStartNode,
        updateFlowMeta,
        isStartNode,
        focusNode,
        clearSelection,

        // guardar / validar / publicar
        save,
        saveOverriding,
        validate,
        publish,
        deactivate,

        // vue flow
        onNodesChange,
        onEdgesChange,
        onConnect,
        onNodeDragStop,

        // deshacer
        undo,
        redo,
    };
}

function isTerminalNode(type: FlowNodeType): boolean {
    return type === 'end' || type === 'human';
}

export type FlowEditorController = ReturnType<typeof useFlowEditor>;
