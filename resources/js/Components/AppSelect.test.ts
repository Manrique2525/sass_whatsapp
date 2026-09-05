import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppSelect from '@/Components/AppSelect.vue';

const options = [
    { value: '', label: 'Todos' },
    { value: 7, label: 'Siete' },
    { value: 'active', label: 'Activa' },
];

describe('AppSelect', () => {
    it('preserves the exact selected value type', async () => {
        const wrapper = mount(AppSelect, { props: { modelValue: '', options } });

        await wrapper.get('button.app-select-trigger').trigger('click');
        await wrapper.get('[role="option"]:nth-child(2)').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([7]);
    });

    it('supports searching and keyboard selection', async () => {
        const wrapper = mount(AppSelect, { props: { modelValue: '', options, searchable: true } });

        await wrapper.get('button.app-select-trigger').trigger('click');
        await wrapper.get('input[aria-label="Buscar opciones"]').setValue('act');
        await wrapper.get('input[aria-label="Buscar opciones"]').trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['active']);
    });

    it('preserves multiple values as an array', async () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: [7], options, multiple: true },
        });

        await wrapper.get('button.app-select-trigger').trigger('click');
        await wrapper.get('[role="option"]:nth-child(3)').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([[7, 'active']]);
    });
});
