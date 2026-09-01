import { mount } from '@vue/test-utils';
import { Position } from '@vue-flow/core';
import { describe, expect, it } from 'vitest';
import FlowEdge from './FlowEdge.vue';

describe('FlowEdge', () => {
    it('recalculates the hit path when Vue Flow updates node coordinates', async () => {
        const wrapper = mount(FlowEdge, {
            props: {
                id: 'edge-1',
                source: 'source',
                target: 'target',
                sourceNode: {} as never,
                targetNode: {} as never,
                type: 'default',
                sourceX: 0,
                sourceY: 0,
                targetX: 260,
                targetY: 0,
                sourcePosition: Position.Right,
                targetPosition: Position.Left,
                selected: false,
                animated: false,
                markerStart: '',
                markerEnd: '',
                data: {},
                events: {} as never,
            },
        });

        const initialPath = wrapper.find('.vue-flow__edge-path').attributes('d');

        await wrapper.setProps({ sourceY: 120, targetY: 120 });

        expect(wrapper.find('.vue-flow__edge-path').attributes('d')).not.toBe(initialPath);
    });
});
