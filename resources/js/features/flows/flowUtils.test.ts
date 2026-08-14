import { describe, expect, it } from 'vitest';
import {
    buildChatbotQuery,
    buildExecutionQuery,
    buildFlowQuery,
    extractErrorMessage,
    findStartNode,
    flowStatusLabel,
    isImplementedNodeType,
    nodeConfigSummary,
    nodeTypeLabel,
    triggerTypeLabel,
} from './flowUtils';

describe('flowStatusLabel', () => {
    it('mapea los tres estados a español', () => {
        expect(flowStatusLabel('draft')).toBe('Borrador');
        expect(flowStatusLabel('published')).toBe('Publicado');
        expect(flowStatusLabel('inactive')).toBe('Inactivo');
    });

    it('devuelve el valor si el estado es desconocido', () => {
        expect(flowStatusLabel('unknown' as 'draft')).toBe('unknown');
    });
});

describe('nodeTypeLabel', () => {
    it('mapea todos los tipos implementados', () => {
        expect(nodeTypeLabel('message')).toBe('Mensaje');
        expect(nodeTypeLabel('buttons')).toBe('Botones');
        expect(nodeTypeLabel('question')).toBe('Pregunta');
        expect(nodeTypeLabel('condition')).toBe('Condición');
        expect(nodeTypeLabel('delay')).toBe('Espera');
        expect(nodeTypeLabel('tag')).toBe('Etiqueta');
        expect(nodeTypeLabel('webhook')).toBe('Webhook');
        expect(nodeTypeLabel('human')).toBe('Transferir a humano');
        expect(nodeTypeLabel('end')).toBe('Fin');
        expect(nodeTypeLabel('ai')).toBe('IA');
    });
});

describe('triggerTypeLabel', () => {
    it('mapea los tipos de trigger', () => {
        expect(triggerTypeLabel('keyword')).toBe('Palabra clave');
        expect(triggerTypeLabel('new_message')).toBe('Nuevo mensaje');
        expect(triggerTypeLabel('start')).toBe('Primer mensaje');
        expect(triggerTypeLabel('schedule')).toBe('Programado');
        expect(triggerTypeLabel('webhook')).toBe('Webhook externo');
    });
});

describe('nodeConfigSummary', () => {
    it('mensaje: texto plano', () => {
        expect(nodeConfigSummary('message', { text: 'Hola' })).toBe('Hola');
    });

    it('botones: texto + títulos de opciones', () => {
        expect(nodeConfigSummary('buttons', { text: 'Elegí:', buttons: [{ title: 'Sí' }, { title: 'No' }] })).toBe(
            'Elegí: — Opciones: Sí · No',
        );
    });

    it('pregunta: prompt + campo', () => {
        expect(nodeConfigSummary('question', { prompt: 'Tu nombre', field: 'nombre' })).toBe('Tu nombre — campo "nombre"');
    });

    it('condición: reglas en lenguaje simple', () => {
        expect(
            nodeConfigSummary('condition', {
                rules: [{ field: 'edad', operator: '>=', value: '18' }, { field: 'plan', operator: '==', value: 'pro' }],
            }),
        ).toBe('edad >= 18 Y plan == pro');
    });

    it('espera: segundos', () => {
        expect(nodeConfigSummary('delay', { seconds: 30 })).toBe('Espera 30 s');
    });

    it('etiqueta: lista de tags', () => {
        expect(nodeConfigSummary('tag', { tags: ['vip', 'nuevo'] })).toBe('vip, nuevo');
    });

    it('webhook: método + URL', () => {
        expect(nodeConfigSummary('webhook', { method: 'POST', url: 'https://x.com/hook' })).toBe('POST https://x.com/hook');
    });

    it('config nulo o tipo sin contenido devuelve cadena vacía', () => {
        expect(nodeConfigSummary('message', null)).toBe('');
        expect(nodeConfigSummary('message', undefined)).toBe('');
        expect(nodeConfigSummary('end', {})).toBe('');
        expect(nodeConfigSummary('human', { nota: 'x' })).toBe('');
    });
});

describe('buildChatbotQuery', () => {
    it('omite filtros vacíos', () => {
        expect(buildChatbotQuery({})).toEqual({});
        expect(buildChatbotQuery({ search: '  ' })).toEqual({});
    });

    it('incluye búsqueda, página y tamaño cuando corresponden', () => {
        expect(buildChatbotQuery({ search: ' ventas ', page: 2, perPage: 20 })).toEqual({
            search: 'ventas',
            page: '2',
            per_page: '20',
        });
        expect(buildChatbotQuery({ page: 1 })).toEqual({});
    });
});

describe('buildFlowQuery', () => {
    it('omite estado vacío y página 1', () => {
        expect(buildFlowQuery({ status: '', page: 1 })).toEqual({});
    });

    it('incluye estado y paginación', () => {
        expect(buildFlowQuery({ status: 'published', page: 3, perPage: 10 })).toEqual({
            status: 'published',
            page: '3',
            per_page: '10',
        });
    });
});

describe('buildExecutionQuery', () => {
    it('omite filtros vacíos', () => {
        expect(buildExecutionQuery({})).toEqual({});
        expect(buildExecutionQuery({ status: '', flow_id: '', chatbot_id: '' })).toEqual({});
    });

    it('combina todos los filtros', () => {
        expect(buildExecutionQuery({ status: 'running', flow_id: 'f1', chatbot_id: 'c1', page: 2, perPage: 50 })).toEqual({
            status: 'running',
            flow_id: 'f1',
            chatbot_id: 'c1',
            page: '2',
            per_page: '50',
        });
    });
});

describe('extractErrorMessage', () => {
    it('extrae message del error estándar de la API', () => {
        expect(extractErrorMessage({ response: { data: { message: 'No autorizado.' } } }, 'fb')).toBe('No autorizado.');
    });

    it('usa el fallback cuando el error no tiene el formato esperado', () => {
        expect(extractErrorMessage(null, 'fb')).toBe('fb');
        expect(extractErrorMessage('boom', 'fb')).toBe('fb');
        expect(extractErrorMessage({ response: {} }, 'fb')).toBe('fb');
    });
});

describe('findStartNode', () => {
    const nodes = [
        { id: 'a', type: 'message' as const, type_label: 'Mensaje', name: 'Saludo', position_x: 0, position_y: 0, config: null, is_start: false },
        { id: 'b', type: 'message' as const, type_label: 'Mensaje', name: 'Inicio', position_x: 0, position_y: 0, config: null, is_start: true },
    ];

    it('devuelve el nodo con is_start', () => {
        expect(findStartNode(nodes)?.name).toBe('Inicio');
    });

    it('devuelve null sin nodos o sin nodo de inicio', () => {
        expect(findStartNode([])).toBeNull();
        expect(findStartNode(undefined)).toBeNull();
        expect(findStartNode([nodes[0]])).toBeNull();
    });
});

describe('isImplementedNodeType', () => {
    it('marca ai como pendiente de fases futuras', () => {
        expect(isImplementedNodeType('ai')).toBe(false);
        expect(isImplementedNodeType('message')).toBe(true);
        expect(isImplementedNodeType('condition')).toBe(true);
    });
});
