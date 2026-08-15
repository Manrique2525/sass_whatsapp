import { describe, expect, it } from 'vitest';
import {
    CONDITION_OPERATORS,
    configIssuesForNode,
    isValidVariableKey,
    localGraphIssues,
    mapBackendErrors,
    nodeConfigValid,
} from './flowValidation';
import { createEditorNode } from './flowAdapter';
import type { FlowEditorEdge } from './flowEditorTypes';

describe('configIssuesForNode', () => {
    it('message requiere texto', () => {
        expect(configIssuesForNode('message', {})).toContain('Falta el texto del mensaje.');
        expect(configIssuesForNode('message', { text: '  ' })).toContain('Falta el texto del mensaje.');
        expect(configIssuesForNode('message', { text: 'Hola' })).toHaveLength(0);
    });

    it('buttons valida rango de botones, ids y títulos', () => {
        expect(configIssuesForNode('buttons', { text: 'x', buttons: [] })).toContain('Se requieren entre 1 y 3 botones.');
        expect(configIssuesForNode('buttons', { text: 'x', buttons: [{ id: 'a', title: 'A' }, { id: 'a', title: 'B' }] })).toContain(
            'Los ids de los botones no pueden repetirse.',
        );
        expect(configIssuesForNode('buttons', { text: 'x', buttons: [{ id: 'a', title: 'A' }] })).toHaveLength(0);
    });

    it('question exige prompt y campo válido', () => {
        expect(configIssuesForNode('question', { prompt: '¿Nombre?', field: 'nombre' })).toHaveLength(0);
        expect(configIssuesForNode('question', { prompt: '', field: 'nombre' })).toContain('Falta la pregunta (prompt).');
        expect(configIssuesForNode('question', { prompt: '¿Nombre?', field: 'mal campo' })).toContain(
            'El campo debe ser un nombre de variable válido (ej: nombre).',
        );
    });

    it('question valida el campo en minúsculas estricto (fix C8)', () => {
        const invalid = ['Nombre', '_nombre', '__proto__', 'prototype', 'constructor', 'mal campo', ''];

        for (const field of invalid) {
            expect(configIssuesForNode('question', { prompt: '¿X?', field })).toContain(
                'El campo debe ser un nombre de variable válido (ej: nombre).',
            );
        }

        expect(configIssuesForNode('question', { prompt: '¿X?', field: 'nombre' })).toHaveLength(0);
        expect(configIssuesForNode('question', { prompt: '¿X?', field: 'nombre_1' })).toHaveLength(0);
        expect(configIssuesForNode('question', { prompt: '¿X?', field: 'nombre123' })).toHaveLength(0);
    });

    it('isValidVariableKey acepta snake_case y rechaza claves peligrosas', () => {
        expect(isValidVariableKey('nombre')).toBe(true);
        expect(isValidVariableKey('nombre_1')).toBe(true);
        expect(isValidVariableKey('nombre123')).toBe(true);

        expect(isValidVariableKey('Nombre')).toBe(false);
        expect(isValidVariableKey('_nombre')).toBe(false);
        expect(isValidVariableKey('nombre ')).toBe(false);
        expect(isValidVariableKey('__proto__')).toBe(false);
        expect(isValidVariableKey('prototype')).toBe(false);
        expect(isValidVariableKey('constructor')).toBe(false);
        expect(isValidVariableKey('a'.repeat(65))).toBe(false);
    });

    it('condition valida reglas y operadores', () => {
        expect(configIssuesForNode('condition', { rules: [{ field: 'x', operator: 'equals', value: '1' }] })).toHaveLength(0);
        expect(configIssuesForNode('condition', { rules: [] })).toContain('Se requiere al menos una regla.');
        expect(configIssuesForNode('condition', { rules: [{ field: '', operator: 'equals', value: '1' }] })).toContain(
            'Cada regla requiere un campo (field).',
        );
        expect(configIssuesForNode('condition', { rules: [{ field: 'x', operator: 'bogus', value: '1' }] })).toContain(
            'Operador desconocido: "bogus".',
        );
    });

    it('los operadores sin valor no exigen value', () => {
        const exists = CONDITION_OPERATORS.find((op) => op.value === 'exists');

        expect(exists?.needsValue).toBe(false);
        expect(configIssuesForNode('condition', { rules: [{ field: 'x', operator: 'exists' }] })).toHaveLength(0);
    });

    it('delay valida enteros 1..3600', () => {
        expect(configIssuesForNode('delay', { seconds: 5 })).toHaveLength(0);
        expect(configIssuesForNode('delay', { seconds: 0 })).toContain('Los segundos deben ser un entero entre 1 y 3600.');
        expect(configIssuesForNode('delay', { seconds: 1.5 })).toContain('Los segundos deben ser un entero entre 1 y 3600.');
    });

    it('tag valida cantidad y contenido', () => {
        expect(configIssuesForNode('tag', { tags: ['vip'] })).toHaveLength(0);
        expect(configIssuesForNode('tag', { tags: [''] })).toContain('Las etiquetas no pueden estar vacías.');
        expect(configIssuesForNode('tag', { tags: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'] })).toContain(
            'Se requieren entre 1 y 10 etiquetas.',
        );
    });

    it('webhook valida URL http(s) y método', () => {
        expect(configIssuesForNode('webhook', { url: 'https://x.com', method: 'POST' })).toHaveLength(0);
        expect(configIssuesForNode('webhook', { url: 'ftp://x.com', method: 'POST' })).toContain(
            'Se requiere una URL http(s) válida.',
        );
        expect(configIssuesForNode('webhook', { url: 'https://x.com', method: 'OPTIONS' })).toContain('Método HTTP inválido.');
    });

    it('end y ai no exigen configuración', () => {
        expect(configIssuesForNode('end', {})).toHaveLength(0);
        expect(configIssuesForNode('ai', {})).toHaveLength(0);
    });
});

describe('nodeConfigValid', () => {
    it('es true solo cuando no hay issues', () => {
        expect(nodeConfigValid('message', { text: 'ok' })).toBe(true);
        expect(nodeConfigValid('message', {})).toBe(false);
    });
});

describe('localGraphIssues', () => {
    function msg(id: string): ReturnType<typeof createEditorNode> {
        const node = createEditorNode('message', id, { x: 0, y: 0 }, { text: 'Hola' }, id);
        node.data.isStart = id === 'start';

        return node;
    }

    it('exige exactamente un nodo de inicio', () => {
        expect(localGraphIssues([msg('solo')], []).some((i) => i.code === 'START_REQUIRED')).toBe(true);

        const twoStarts = [msg('a'), msg('b')];
        twoStarts.forEach((n) => (n.data.isStart = true));

        expect(localGraphIssues(twoStarts, []).some((i) => i.code === 'START_REQUIRED')).toBe(true);
    });

    it('marca nodos terminales con salidas y condiciones sin ramas', () => {
        const end = createEditorNode('end', 'end', { x: 0, y: 0 }, {}, 'Fin');
        const other = msg('o');
        const edges: FlowEditorEdge[] = [{ id: 'e', source: 'end', target: 'o' }];

        expect(localGraphIssues([end, other], edges).some((i) => i.code === 'TERMINAL_OUTGOING')).toBe(true);

        const cond = createEditorNode('condition', 'cond', { x: 0, y: 0 }, { rules: [] }, 'Condición');
        cond.data.isStart = true;

        expect(localGraphIssues([cond], []).some((i) => i.code === 'CONDITION_BRANCHES')).toBe(true);
    });

    it('pide conexión saliente a nodos no-terminales', () => {
        const node = msg('n');
        node.data.isStart = true;

        expect(localGraphIssues([node], []).some((i) => i.code === 'OUTGOING_REQUIRED')).toBe(true);
    });

    it('avisa cuando falta un nodo Fin', () => {
        const node = msg('n');
        node.data.isStart = true;

        expect(localGraphIssues([node], []).some((i) => i.code === 'END_MISSING')).toBe(true);
    });
});

describe('mapBackendErrors', () => {
    it('resuelve el nodo por nombre', () => {
        const node = createEditorNode('message', 'n1', { x: 0, y: 0 }, {}, 'Mensaje de bienvenida');
        const issues = mapBackendErrors(['El nodo "Mensaje de bienvenida" no tiene conexión saliente.'], [node]);

        expect(issues[0].nodeId).toBe('n1');
        expect(issues[0].severity).toBe('error');
    });

    it('deja nodeId null cuando no encuentra el nodo', () => {
        const issues = mapBackendErrors(['El flujo debe tener un nodo de inicio.'], []);

        expect(issues[0].nodeId).toBeNull();
        expect(issues[0].code).toBe('BACKEND');
    });
});
