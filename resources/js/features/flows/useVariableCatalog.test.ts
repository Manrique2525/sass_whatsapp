import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useVariableCatalog } from './useVariableCatalog';
import type { VariableDefinition } from './flowTypes';

function variable(overrides: Partial<VariableDefinition>): VariableDefinition {
    return {
        key: 'contact.name',
        label: 'Nombre',
        namespace: 'contact',
        source: 'contact.name',
        type: 'string',
        default: null,
        writable: false,
        ...overrides,
    };
}

const variables: VariableDefinition[] = [
    variable({ key: 'contact.name', label: 'Nombre' }),
    variable({ key: 'contact.phone', label: 'Teléfono' }),
    variable({ key: 'business.name', label: 'Nombre del negocio', namespace: 'business' }),
    variable({ key: 'conversation.id', label: 'ID de conversación', namespace: 'conversation' }),
    variable({ key: 'custom.nombre', label: 'Pregunta', namespace: 'custom', source: 'question:Pregunta', writable: true }),
];

const context = { tenantId: 'tenant-1', flowId: 'flow-1' };

function installAxios(impl: () => unknown): { get: ReturnType<typeof vi.fn> } {
    const http = { get: vi.fn(impl) } as unknown as Window['axios'];

    window.axios = http;

    return http as unknown as { get: ReturnType<typeof vi.fn> };
}

describe('useVariableCatalog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('carga el catálogo una sola vez desde el endpoint correcto', async () => {
        const http = installAxios(() => Promise.resolve({ data: { variables } }));
        const catalog = useVariableCatalog(context);

        await catalog.load();
        await catalog.load();

        expect(http.get).toHaveBeenCalledTimes(1);
        expect(http.get).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/flows/flow-1/variables');
        expect(catalog.items.value).toHaveLength(5);
        expect(catalog.loading.value).toBe(false);
        expect(catalog.error.value).toBeNull();
    });

    it('agrupa por namespace en orden fijo (Map, sin objetos planos)', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const catalog = useVariableCatalog(context);
        await catalog.load();

        expect(catalog.groups.value.map((group) => group.namespace)).toEqual(['contact', 'business', 'conversation', 'custom']);
        expect(catalog.groups.value.find((group) => group.namespace === 'custom')?.items).toHaveLength(1);
    });

    it('es seguro ante claves que colisionan con el prototipo', async () => {
        const poisoned = [
            ...variables,
            variable({ key: 'custom.__proto__', label: 'Malo', namespace: 'custom' }),
            variable({ key: 'custom.constructor', label: 'Otro', namespace: 'custom' }),
        ];
        installAxios(() => Promise.resolve({ data: { variables: poisoned } }));
        const catalog = useVariableCatalog(context);
        await catalog.load();

        const customGroup = catalog.groups.value.find((group) => group.namespace === 'custom');
        expect(customGroup?.items.map((item) => item.key)).toEqual(['custom.nombre', 'custom.__proto__', 'custom.constructor']);
        expect(catalog.byKey('custom.__proto__')).not.toBeNull();
        expect(catalog.byKey('custom.inexistente')).toBeNull();
    });

    it('filtra por búsqueda en clave y label', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const catalog = useVariableCatalog(context);
        await catalog.load();

        catalog.search.value = 'nombre';
        expect(catalog.groups.value.flatMap((group) => group.items).map((item) => item.key)).toEqual([
            'contact.name',
            'business.name',
            'custom.nombre',
        ]);

        catalog.search.value = 'business';
        expect(catalog.groups.value.map((group) => group.namespace)).toEqual(['business']);
    });

    it('byKey devuelve la definición o null', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const catalog = useVariableCatalog(context);
        await catalog.load();

        expect(catalog.byKey('contact.name')?.label).toBe('Nombre');
        expect(catalog.byKey('nope')).toBeNull();
    });

    it('expone el error de la API sin romper el estado', async () => {
        installAxios(() =>
            Promise.reject({ response: { status: 403, data: { message: 'Sin permiso', code: 'PERMISSION_DENIED' } } }),
        );
        const catalog = useVariableCatalog(context);
        await catalog.load();

        expect(catalog.error.value).toBe('Sin permiso');
        expect(catalog.items.value).toEqual([]);
        expect(catalog.groups.value).toEqual([]);
        expect(catalog.loading.value).toBe(false);
    });

    it('catálogo vacío: sin grupos', async () => {
        installAxios(() => Promise.resolve({ data: { variables: [] } }));
        const catalog = useVariableCatalog(context);
        await catalog.load();

        expect(catalog.groups.value).toEqual([]);
    });
});
