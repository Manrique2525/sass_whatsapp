import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MessageComposer from './MessageComposer.vue';

const mountComposer = (props: { sending?: boolean; disabled?: boolean } = {}) =>
    mount(MessageComposer, {
        props: { sending: false, disabled: false, ...props },
    });

describe('MessageComposer', () => {
    it('emite send con el texto recortado y preserva draft hasta que sending cambie', async () => {
        const wrapper = mountComposer();
        await wrapper.get('textarea').setValue('  Hola mundo  ');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('send')).toEqual([['Hola mundo']]);

        expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('  Hola mundo  ');

        await wrapper.setProps({ sending: true });
        await wrapper.setProps({ sending: false });

        expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('');
    });

    it('emite send al presionar Enter', async () => {
        const wrapper = mountComposer();
        await wrapper.get('textarea').setValue('Hola');
        await wrapper.get('textarea').trigger('keydown', { key: 'Enter', shiftKey: false });

        expect(wrapper.emitted('send')).toEqual([['Hola']]);
    });

    it('no emite send con Shift+Enter (salto de linea)', async () => {
        const wrapper = mountComposer();
        await wrapper.get('textarea').setValue('Hola');
        await wrapper.get('textarea').trigger('keydown', { key: 'Enter', shiftKey: true });

        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('no emite send con texto vacio', async () => {
        const wrapper = mountComposer();
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('no emite send si esta deshabilitado', async () => {
        const wrapper = mountComposer({ disabled: true });
        await wrapper.get('textarea').setValue('Hola');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('deshabilita el boton mientras envia', async () => {
        const wrapper = mountComposer({ sending: true });
        await wrapper.get('textarea').setValue('Hola');

        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
