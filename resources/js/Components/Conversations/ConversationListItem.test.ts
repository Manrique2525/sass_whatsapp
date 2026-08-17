import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ConversationListItem from '@/Components/Conversations/ConversationListItem.vue';
import type { Conversation } from '@/features/conversations/conversationUtils';

const baseConv = (overrides: Partial<Conversation> = {}): Conversation => ({
    id: 'conv-1',
    status: 'open',
    status_label: 'Abierta',
    contact: { id: 'c1', phone: '+549111', name: 'Test Contact', email: null, avatar_url: null, metadata: null, provider_contact_id: null, last_interaction_at: null, created_at: '', updated_at: '' },
    agent: null,
    last_message_at: null,
    last_interaction_at: null,
    auto_assigned: false,
    bot_paused: false,
    handoff_requested_at: null,
    context: null,
    flow_execution_id: null,
    last_message: null,
    created_at: '',
    updated_at: '',
    ...overrides,
});

const mountItem = (overrides: Partial<Conversation> = {}) =>
    mount(ConversationListItem, {
        props: {
            conversation: baseConv(overrides),
            active: false,
        },
    });

describe('ConversationListItem — handoff indicators', () => {
    it('UI-21: muestra "Requiere agente" cuando unassigned handoff', () => {
        const wrapper = mountItem({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: null,
            bot_paused: true,
        });

        expect(wrapper.text()).toContain('Requiere agente');
    });

    it('UI-22: muestra nombre del agente cuando humanActive', () => {
        const wrapper = mountItem({
            bot_paused: true,
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: { id: 1, name: 'Agente A', email: 'a@test.com' },
        });

        expect(wrapper.text()).toContain('Agente A');
    });

    it('UI-23: tiene borde amber cuando unassigned handoff', () => {
        const wrapper = mountItem({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: null,
            bot_paused: true,
        });

        expect(wrapper.classes()).toContain('border-l-2');
        expect(wrapper.classes()).toContain('border-l-amber-400');
    });

    it('UI-24: no tiene borde amber cuando no es unassigned', () => {
        const wrapper = mountItem({ handoff_requested_at: null, agent: null });

        expect(wrapper.classes()).not.toContain('border-l-amber-400');
    });
});
