import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useFlowEditor } from './useFlowEditor';
import { CONDITION_FALSE, CONDITION_TRUE } from './flowAdapter';
import type { Flow } from './flowTypes';

function makeFlow(overrides: Partial<Flow> = {}): Flow {
    return {
        id: 'flow-1',
        chatbot_id: 'chatbot-1',
        name: 'Mi flujo',
        description: null,
        status: 'draft',
        status_label: 'Borrador',
        config: null,
        nodes: [
            {
                id: 'start',
                type: 'message',
                type_label: 'Mensaje',
                name: 'Inicio',
                position_x: 0,
                position_y: 0,
                config: { text: 'Hola' },
                is_start: true,
            },
        ],
        connections: [],
        triggers: [],
        triggers_count: 0,
        created_at: '2026-08-14T12:00:00.000000Z',
        updated_at: '2026-08-14T12:00:00.000000Z',
        ...overrides,
    };
}

const context = { tenantId: 'tenant-1', chatbotId: 'chatbot-1', flowId: 'flow-1', canManage: true };

function installAxios(getImpl?: () => unknown): {
    get: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
} {
    const http = { get: vi.fn(), put: vi.fn(), post: vi.fn(), patch: vi.fn() } as unknown as Window['axios'];
    window.axios = http;

    if (getImpl) {
        (window.axios as unknown as { get: ReturnType<typeof vi.fn> }).get.mockImplementation(getImpl);
    }

    return http as unknown as { get: ReturnType<typeof vi.fn>; put: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
}

function rejectWith(status: number, data: Record<string, unknown>): Promise<never> {
    return Promise.reject({ response: { status, data } });
}

describe('useFlowEditor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('load trae el flujo y deja el estado limpio', async () => {
        const http = installAxios(() => Promise.resolve({ data: { flow: makeFlow() } }));
        const editor = useFlowEditor(context);

        expect(editor.loadState.value).toBe('loading');
        await editor.load();

        expect(http.get).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/flows/flow-1');
        expect(editor.loadState.value).toBe('ready');
        expect(editor.nodes.value).toHaveLength(1);
        expect(editor.nodes.value[0].data.isStart).toBe(true);
        expect(editor.dirty.value).toBe(false);
        expect(editor.error.value).toBeNull();
    });

    it('load falla y expone el error de la API', async () => {
        installAxios(() => Promise.reject({ response: { status: 404, data: { message: 'No encontrado', code: 'NOT_FOUND' } } }));
        const editor = useFlowEditor(context);

        await editor.load();

        expect(editor.loadState.value).toBe('error');
        expect(editor.error.value).toBe('No encontrado');
    });

    it('addNode marca dirty y no permite crear ai', async () => {
        installAxios(() => Promise.resolve({ data: { flow: makeFlow() } }));
        const editor = useFlowEditor(context);
        await editor.load();

        const created = editor.addNode('message', { x: 1.4, y: 2.6 });

        expect(created).not.toBeNull();
        expect(editor.nodes.value).toHaveLength(2);
        expect(editor.dirty.value).toBe(true);
        expect(editor.addNode('ai', { x: 0, y: 0 })).toBeNull();
    });

    it('save envía el draft con base_updated_at y limpia el estado', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow() } });
        http.put.mockResolvedValue({ data: { flow: makeFlow({ updated_at: '2026-08-14T12:01:00.000000Z' }) } });
        const editor = useFlowEditor(context);
        await editor.load();
        editor.addNode('message', { x: 0, y: 0 });

        const saved = await editor.save();

        expect(saved).toBe(true);
        const [url, payload] = http.put.mock.calls[0] as [string, { base_updated_at: string }];
        expect(url).toBe('/api/v1/tenants/tenant-1/flows/flow-1/draft');
        expect(payload.base_updated_at).toBe('2026-08-14T12:00:00.000000Z');
        expect(editor.dirty.value).toBe(false);
        expect(editor.saveState.value).toBe('saved');
        expect(editor.canUndo.value).toBe(false);
    });

    it('save con 409 FLOW_CONFLICT abre el conflicto sin sobrescribir', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow() } });
        http.put.mockImplementation(() => rejectWith(409, { code: 'FLOW_CONFLICT', message: 'Cambios en conflicto' }));
        const editor = useFlowEditor(context);
        await editor.load();
        editor.addNode('message', { x: 0, y: 0 });

        const saved = await editor.save();

        expect(saved).toBe(false);
        expect(editor.conflict.value?.message).toBe('Cambios en conflicto');
        expect(editor.saveState.value).toBe('idle');
        expect(editor.dirty.value).toBe(true);
    });

    it('saveOverriding reenvía sin base_updated_at y resuelve el conflicto', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow() } });
        http.put.mockImplementation((_url: string, payload: { base_updated_at: string | undefined }) => {
            if (payload.base_updated_at === undefined) {
                return Promise.resolve({ data: { flow: makeFlow({ updated_at: '2026-08-14T12:02:00.000000Z' }) } });
            }

            return rejectWith(409, { code: 'FLOW_CONFLICT', message: 'Cambios en conflicto' });
        });
        const editor = useFlowEditor(context);
        await editor.load();
        editor.addNode('message', { x: 0, y: 0 });
        await editor.save();
        expect(editor.conflict.value).not.toBeNull();

        const overridden = await editor.saveOverriding();

        expect(overridden).toBe(true);
        expect(editor.conflict.value).toBeNull();
        expect(editor.dirty.value).toBe(false);
    });

    it('publish con FLOW_INVALID carga los errores de validación', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow() } });
        http.post.mockImplementation(() =>
            rejectWith(422, {
                code: 'FLOW_INVALID',
                message: 'El flujo no es válido',
                errors: ['El nodo "Inicio" no tiene conexión saliente.'],
            }),
        );
        const editor = useFlowEditor(context);
        await editor.load();

        await editor.publish();

        expect(editor.validation.value?.valid).toBe(false);
        expect(editor.validation.value?.errors).toContain('El nodo "Inicio" no tiene conexión saliente.');
        expect(editor.validationIssues.value[0].nodeId).toBe('start');
        expect(editor.publishState.value).toBe('error');
    });

    it('onConnect rechaza conexiones inválidas sin mutar el grafo', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({
            data: {
                flow: makeFlow({
                    nodes: [
                        {
                            id: 'start',
                            type: 'message',
                            type_label: 'Mensaje',
                            name: 'Inicio',
                            position_x: 0,
                            position_y: 0,
                            config: { text: 'Hola' },
                            is_start: true,
                        },
                        {
                            id: 'end',
                            type: 'end',
                            type_label: 'Fin',
                            name: 'Fin',
                            position_x: 100,
                            position_y: 0,
                            config: {},
                            is_start: false,
                        },
                    ],
                }),
            },
        });
        const editor = useFlowEditor(context);
        await editor.load();
        const edgesBefore = editor.edges.value.length;

        editor.onConnect({ source: 'end', target: 'start', sourceHandle: null, targetHandle: null });

        expect(editor.connectError.value).not.toBeNull();
        expect(editor.edges.value).toHaveLength(edgesBefore);
    });

    it('onConnect agrega aristas válidas de condición con su rama', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({
            data: {
                flow: makeFlow({
                    nodes: [
                        {
                            id: 'cond',
                            type: 'condition',
                            type_label: 'Condición',
                            name: 'Condición',
                            position_x: 0,
                            position_y: 0,
                            config: { rules: [{ field: 'x', operator: 'equals', value: '1' }] },
                            is_start: true,
                        },
                        {
                            id: 'n1',
                            type: 'message',
                            type_label: 'Mensaje',
                            name: 'Sí',
                            position_x: 100,
                            position_y: 0,
                            config: { text: 'Sí' },
                            is_start: false,
                        },
                    ],
                }),
            },
        });
        const editor = useFlowEditor(context);
        await editor.load();

        editor.onConnect({ source: 'cond', target: 'n1', sourceHandle: CONDITION_TRUE, targetHandle: null });

        expect(editor.edges.value).toHaveLength(1);
        expect(editor.edges.value[0].sourceHandle).toBe(CONDITION_TRUE);
        expect(editor.edges.value[0].label).toBe(CONDITION_TRUE);
        expect(editor.dirty.value).toBe(true);

        const duplicate = editor.onConnect({ source: 'cond', target: 'n1', sourceHandle: CONDITION_TRUE, targetHandle: null });
        expect(editor.edges.value).toHaveLength(1);
        expect(duplicate).toBeUndefined();
        expect(editor.connectError.value).not.toBeNull();

        editor.onConnect({ source: 'cond', target: 'n1', sourceHandle: CONDITION_FALSE, targetHandle: null });
        expect(editor.edges.value).toHaveLength(2);
    });

    it('undo/redo restaura el grafo', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow() } });
        const editor = useFlowEditor(context);
        await editor.load();
        editor.addNode('message', { x: 0, y: 0 });
        expect(editor.nodes.value).toHaveLength(2);

        editor.undo();
        expect(editor.nodes.value).toHaveLength(1);

        editor.redo();
        expect(editor.nodes.value).toHaveLength(2);
    });

    it('updateNodeConfig redondea posiciones y no toca nodos de solo lectura', async () => {
        const http = installAxios();
        http.get.mockResolvedValue({ data: { flow: makeFlow({ status: 'published', status_label: 'Publicado' }) } });
        const editor = useFlowEditor(context);
        await editor.load();

        expect(editor.readOnly.value).toBe(true);
        const added = editor.addNode('message', { x: 0, y: 0 });
        expect(added).toBeNull();
        expect(editor.nodes.value).toHaveLength(1);
    });
});
