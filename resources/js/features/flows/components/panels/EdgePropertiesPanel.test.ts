import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import EdgePropertiesPanel from './EdgePropertiesPanel.vue';
import type { FlowEditorController } from '../../useFlowEditor';

function makeEditor(sourceType: 'message' | 'condition', label?: string, sourceHandle?: string): FlowEditorController {
    return {
        selected: ref({ kind: 'edge', id: 'edge-1' }),
        edges: ref([{ id: 'edge-1', source: 'source', target: 'target', label, sourceHandle }]),
        nodes: ref([
            {
                id: 'source',
                type: sourceType,
                data: { type: sourceType, typeLabel: sourceType, name: 'Origen', isStart: false, config: {} },
                position: { x: 0, y: 0 },
            },
            {
                id: 'target',
                type: 'end',
                data: { type: 'end', typeLabel: 'Fin', name: 'Destino', isStart: false, config: {} },
                position: { x: 100, y: 0 },
            },
        ]),
        readOnly: ref(false),
        removeEdge: vi.fn(),
        updateEdgeLabel: vi.fn(),
    } as unknown as FlowEditorController;
}

describe('EdgePropertiesPanel', () => {
    it('does not expose free-text editing for a normal edge', () => {
        const editor = makeEditor('message');
        const wrapper = mount(EdgePropertiesPanel, { props: { editor } });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.text()).toContain('Salida determinista sin etiqueta.');
    });

    it('shows the condition branch without allowing arbitrary values', () => {
        const editor = makeEditor('condition', 'true', 'true');
        const wrapper = mount(EdgePropertiesPanel, { props: { editor } });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.text()).toContain('true');
        expect(wrapper.text()).not.toContain('otra etiqueta');
    });

    it('offers explicit cleanup for a legacy invalid normal label', async () => {
        const editor = makeEditor('message', 'Inicial');
        const wrapper = mount(EdgePropertiesPanel, { props: { editor } });

        await wrapper.get('button').trigger('click');

        expect(editor.updateEdgeLabel).toHaveBeenCalledWith('edge-1', '');
    });
});
