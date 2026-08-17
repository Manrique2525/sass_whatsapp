import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ContactPanel from '@/Components/Conversations/ContactPanel.vue';
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

const mountPanel = (overrides: Partial<Conversation> = {}) =>
    mount(ContactPanel, {
        props: { conversation: baseConv(overrides) },
    });

describe('ContactPanel — bot/human status', () => {
    it('UI-HP-01: muestra "Humano" cuando humanActive', () => {
        const wrapper = mountPanel({
            bot_paused: true,
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
        });

        expect(wrapper.text()).toContain('Humano');
    });

    it('UI-HP-02: muestra "Bot activo" cuando no pausado', () => {
        const wrapper = mountPanel({ bot_paused: false });

        expect(wrapper.text()).toContain('Bot activo');
    });

    it('UI-HP-03: muestra "Bot pausado (manual)" cuando pausado sin handoff', () => {
        const wrapper = mountPanel({ bot_paused: true, handoff_requested_at: null });

        expect(wrapper.text()).toContain('Bot pausado (manual)');
    });

    it('UI-HP-04: muestra handoff_requested_at cuando presente', () => {
        const wrapper = mountPanel({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
        });

        expect(wrapper.text()).toContain('Handoff solicitado');
    });

    it('UI-HP-05: no muestra handoff_requested_at cuando es null', () => {
        const wrapper = mountPanel({ handoff_requested_at: null });

        expect(wrapper.text()).not.toContain('Handoff solicitado');
    });
});
