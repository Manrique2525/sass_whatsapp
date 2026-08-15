import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import VariablePicker from './components/VariablePicker.vue';
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
    variable({ key: 'business.name', label: 'Nombre del negocio', namespace: 'business' }),
    variable({ key: 'custom.nombre', label: 'Pregunta', namespace: 'custom', writable: true }),
];

function installAxios(impl: () => unknown): void {
    window.axios = { get: vi.fn(impl) } as unknown as Window['axios'];
}

const mountPicker = (props: { tenantId?: string; flowId?: string; disabled?: boolean } = {}) =>
    mount(VariablePicker, {
        props: { tenantId: 'tenant-1', flowId: 'flow-1', disabled: false, ...props },
    });

describe('VariablePicker', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('carga el catálogo y agrupa al abrir', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const wrapper = mountPicker();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('contact.name');
        expect(wrapper.text()).toContain('Nombre del negocio');
        expect(wrapper.text()).toContain('Personalizadas de este flujo');
    });

    it('emite select con la variable y se cierra', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const wrapper = mountPicker();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        const option = wrapper.findAll('button').find((button) => button.text().includes('contact.name'));
        expect(option).toBeDefined();
        await option!.trigger('click');

        const emitted = wrapper.emitted('select') as unknown as [VariableDefinition][];
        expect(emitted).toHaveLength(1);
        expect(emitted[0][0].key).toBe('contact.name');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
    });

    it('filtra por búsqueda', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const wrapper = mountPicker();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        await wrapper.get('input[type="text"]').setValue('negocio');

        expect(wrapper.text()).toContain('business.name');
        expect(wrapper.text()).not.toContain('custom.nombre');
    });

    it('muestra el estado vacío', async () => {
        installAxios(() => Promise.resolve({ data: { variables: [] } }));
        const wrapper = mountPicker();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Sin variables');
    });

    it('muestra el error de la API sin romper el picker', async () => {
        installAxios(() =>
            Promise.reject({ response: { status: 403, data: { message: 'Sin permiso', code: 'PERMISSION_DENIED' } } }),
        );
        const wrapper = mountPicker();

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Sin permiso');
    });

    it('no abre si está deshabilitado', async () => {
        installAxios(() => Promise.resolve({ data: { variables } }));
        const wrapper = mountPicker({ disabled: true });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    });
});
