import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import QuestionNodeConfig from './components/panels/config/QuestionNodeConfig.vue';
import type { NodeConfigContext } from './flowEditorTypes';

const context: NodeConfigContext = { tenantId: 'tenant-1', flowId: 'flow-1', readOnly: false };

const mountConfig = (modelValue: Record<string, unknown> | null = null) =>
    mount(QuestionNodeConfig, {
        props: { modelValue, context },
    });

describe('QuestionNodeConfig', () => {
    it('conserva type/default al editar el prompt (UNIDAD 5)', async () => {
        const wrapper = mountConfig({
            text: '',
            prompt: '¿Edad?',
            field: 'edad',
            type: 'integer',
            default: '18',
        });

        await wrapper.get('textarea[placeholder="¿Cómo te llamás?"]').setValue('¿Cuántos años tenés?');

        const emitted = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        const last = emitted.at(-1)![0];

        expect(last.prompt).toBe('¿Cuántos años tenés?');
        expect(last.type).toBe('integer');
        expect(last.default).toBe('18');
        expect(last.field).toBe('edad');
    });

    it('emite type/default editables y default vacío como null', async () => {
        const wrapper = mountConfig(null);

        await wrapper.get('textarea[placeholder="¿Cómo te llamás?"]').setValue('¿Nombre?');
        await wrapper.get('input[placeholder="nombre"]').setValue('nombre');
        await wrapper.get('select').setValue('string');
        await wrapper.get('input[placeholder="Se usa si el cliente no responde"]').setValue('Anónimo');

        const emitted = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        const last = emitted.at(-1)![0];

        expect(last.type).toBe('string');
        expect(last.default).toBe('Anónimo');

        await wrapper.get('input[placeholder="Se usa si el cliente no responde"]').setValue('');

        const cleared = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        expect(cleared.at(-1)![0].default).toBeNull();
    });
});
