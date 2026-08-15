import { describe, expect, it } from 'vitest';
import { MarkerType } from '@vue-flow/core';
import {
    CONDITION_TRUE,
    apiConnectionToEdge,
    apiToGraph,
    canCreateConnection,
    createEditorNode,
    edgeIdFor,
    editorEdgeToApi,
    graphSignature,
    graphToDraft,
} from './flowAdapter';
import type { FlowConnection, FlowNode } from './flowTypes';
import type { FlowEditorEdge, FlowEditorNode } from './flowEditorTypes';

function messageNode(id: string, x = 0, y = 0): FlowEditorNode {
    return createEditorNode('message', id, { x, y }, { text: 'Hola' }, 'Mensaje');
}

function startNode(id: string): FlowEditorNode {
    const node = createEditorNode('message', id, { x: 0, y: 0 }, { text: 'Hola' }, 'Inicio');
    node.data.isStart = true;

    return node;
}

describe('edgeIdFor', () => {
    it('genera ids deterministas con la rama', () => {
        expect(edgeIdFor('a', 'b', 'true')).toBe('e-a-b-true');
        expect(edgeIdFor('a', 'b', null)).toBe('e-a-b-');
    });
});

describe('apiToGraph / graphToDraft', () => {
    it('traduce nodos y conexiones de la API y vuelve sin pérdida', () => {
        const apiNodes: FlowNode[] = [
            {
                id: 'n1',
                type: 'message',
                type_label: 'Mensaje',
                name: 'Inicio',
                position_x: 0,
                position_y: 0,
                config: { text: 'Hola' },
                is_start: true,
            },
            {
                id: 'n2',
                type: 'message',
                type_label: 'Mensaje',
                name: 'Mensaje',
                position_x: 10,
                position_y: 20,
                config: { text: 'Chau' },
                is_start: false,
            },
        ];
        const apiConnection: FlowConnection = {
            id: 'c1',
            source_node_id: 'n1',
            target_node_id: 'n2',
            label: null,
        };

        const { nodes, edges } = apiToGraph(apiNodes, [apiConnection]);

        expect(nodes).toHaveLength(2);
        expect(nodes[0].data.isStart).toBe(true);
        expect(edges).toHaveLength(1);
        expect(edges[0].source).toBe('n1');
        expect(edges[0].target).toBe('n2');
        expect(edges[0].markerEnd).toBe(MarkerType.ArrowClosed);

        const draft = graphToDraft(nodes, edges, '2026-08-14T12:00:00.000000Z');
        expect(draft.base_updated_at).toBe('2026-08-14T12:00:00.000000Z');
        expect(draft.nodes).toHaveLength(2);
        expect(draft.connections).toEqual([
            { id: 'e-n1-n2-', source_node_id: 'n1', target_node_id: 'n2', label: null },
        ]);
        expect(draft.nodes[1].position_x).toBe(10);
        expect(draft.nodes[1].position_y).toBe(20);
    });

    it('no envía base_updated_at cuando es null', () => {
        const apiNode: FlowNode = {
            id: 'n1',
            type: 'message',
            type_label: 'Mensaje',
            name: 'Mensaje',
            position_x: 0,
            position_y: 0,
            config: { text: 'Hola' },
            is_start: false,
        };
        const { nodes, edges } = apiToGraph([apiNode], []);
        const draft = graphToDraft(nodes, edges, null);

        expect(draft.base_updated_at).toBeUndefined();
    });

    it('las posiciones fraccionarias se redondean a enteros', () => {
        const node = messageNode('n1');
        node.position = { x: 10.6, y: 3.2 };
        const draft = graphToDraft([node], [], null);

        expect(draft.nodes[0].position_x).toBe(11);
        expect(draft.nodes[0].position_y).toBe(3);
    });

    it('las conexiones de condición conservan la rama en label/sourceHandle', () => {
        const edges: FlowEditorEdge[] = [
            apiConnectionToEdge({ id: 'c', source_node_id: 'cond', target_node_id: 'n1', label: CONDITION_TRUE }),
        ];
        const api = edges.map(editorEdgeToApi);

        expect(api[0].label).toBe('true');
    });
});

describe('createEditorNode', () => {
    it('usa DEFAULT_NODE_CONFIG cuando no se pasa config (fix C7)', () => {
        const buttons = createEditorNode('buttons', 'b1', { x: 0, y: 0 });
        expect(buttons.data.config).toEqual({ text: '', buttons: [{ id: 'opcion_1', title: '' }] });

        const question = createEditorNode('question', 'q1', { x: 0, y: 0 });
        expect(question.data.config).toEqual({ text: '', prompt: '', field: '' });
    });

    it('respeta el config explícito', () => {
        const node = createEditorNode('message', 'm1', { x: 0, y: 0 }, { text: 'Hola' });

        expect(node.data.config).toEqual({ text: 'Hola' });
    });
});

describe('graphSignature', () => {
    it('no depende del orden de nodos/aristas', () => {
        const a = graphSignature([startNode('b'), messageNode('a')], []);
        const b = graphSignature([messageNode('a'), startNode('b')], []);

        expect(a).toBe(b);
    });

    it('cambia si cambia una posición', () => {
        const before = graphSignature([messageNode('a', 0, 0)], []);
        const moved = messageNode('a', 5, 0);

        expect(graphSignature([moved], [])).not.toBe(before);
    });
});

describe('canCreateConnection', () => {
    it('rechaza self-loops', () => {
        const node = messageNode('n1');
        const verdict = canCreateConnection(
            { source: 'n1', target: 'n1', sourceHandle: null, targetHandle: null },
            [node],
            [],
        );

        expect(verdict.ok).toBe(false);
    });

    it('rechaza conexiones hacia el nodo de inicio', () => {
        const start = startNode('s');
        const other = messageNode('o');
        const verdict = canCreateConnection(
            { source: 'o', target: 's', sourceHandle: null, targetHandle: null },
            [start, other],
            [],
        );

        expect(verdict.ok).toBe(false);
    });

    it('rechaza salidas de nodos terminales', () => {
        const end = createEditorNode('end', 'end', { x: 0, y: 0 }, {}, 'Fin');
        const other = messageNode('o');
        const verdict = canCreateConnection(
            { source: 'end', target: 'o', sourceHandle: null, targetHandle: null },
            [end, other],
            [],
        );

        expect(verdict.ok).toBe(false);
    });

    it('una condición exige la rama true/false', () => {
        const cond = createEditorNode('condition', 'cond', { x: 0, y: 0 }, { rules: [] }, 'Condición');
        const other = messageNode('o');
        const wrongHandle = canCreateConnection(
            { source: 'cond', target: 'o', sourceHandle: 'left', targetHandle: null },
            [cond, other],
            [],
        );

        expect(wrongHandle.ok).toBe(false);

        const ok = canCreateConnection(
            { source: 'cond', target: 'o', sourceHandle: CONDITION_TRUE, targetHandle: null },
            [cond, other],
            [],
        );

        expect(ok.ok).toBe(true);
    });

    it('rechaza una segunda conexión en la misma rama de la condición', () => {
        const cond = createEditorNode('condition', 'cond', { x: 0, y: 0 }, { rules: [] }, 'Condición');
        const a = messageNode('a');
        const b = messageNode('b');
        const existing: FlowEditorEdge[] = [
            {
                id: edgeIdFor('cond', 'a', CONDITION_TRUE),
                source: 'cond',
                target: 'a',
                sourceHandle: CONDITION_TRUE,
                label: CONDITION_TRUE,
            },
        ];
        const verdict = canCreateConnection(
            { source: 'cond', target: 'b', sourceHandle: CONDITION_TRUE, targetHandle: null },
            [cond, a, b],
            existing,
        );

        expect(verdict.ok).toBe(false);
    });

    it('rechaza más de una salida en nodos no-condición', () => {
        const msg = messageNode('m');
        const a = messageNode('a');
        const b = messageNode('b');
        const existing: FlowEditorEdge[] = [
            { id: edgeIdFor('m', 'a', null), source: 'm', target: 'a' },
        ];
        const verdict = canCreateConnection(
            { source: 'm', target: 'b', sourceHandle: null, targetHandle: null },
            [msg, a, b],
            existing,
        );

        expect(verdict.ok).toBe(false);
    });
});
