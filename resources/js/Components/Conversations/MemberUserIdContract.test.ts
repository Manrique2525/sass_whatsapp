import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChatHeader from '@/Components/Conversations/ChatHeader.vue';
import ConversationFilters from '@/Components/Conversations/ConversationFilters.vue';
import type { Conversation, TenantMember } from '@/features/conversations/conversationUtils';

const member: TenantMember = {
    id: 901,
    user: {
        id: 42,
        name: 'Agente Uno',
        email: 'agent@example.test',
    },
    role: 'agent',
};

const conversation: Conversation = {
    id: 'conversation-1',
    status: 'open',
    status_label: 'Abierta',
    contact: null,
    agent: null,
    last_message_at: null,
    last_interaction_at: null,
    auto_assigned: false,
    bot_paused: false,
    context: null,
    flow_execution_id: null,
    last_message: null,
    handoff_requested_at: null,
    created_at: '2026-08-17T00:00:00Z',
    updated_at: '2026-08-17T00:00:00Z',
};

describe('conversation member user id contract', () => {
    it('assign y transfer emiten users.id, no tenant_users.id', async () => {
        const wrapper = mount(ChatHeader, {
            props: {
                conversation,
                members: [member],
                canManage: true,
                canAssign: true,
                canClaim: true,
                currentUserId: 99,
                acting: false,
            },
        });

        expect(wrapper.find('option[value="42"]').exists()).toBe(true);
        expect(wrapper.find('option[value="901"]').exists()).toBe(false);

        await wrapper.find('select').setValue('42');

        expect(wrapper.emitted('assign')).toEqual([[42]]);
    });

    it('el filtro agent_id también usa users.id', () => {
        const wrapper = mount(ConversationFilters, {
            props: {
                members: [member],
                modelValue: { search: '', status: '', agent_id: '' },
            },
        });

        expect(wrapper.find('option[value="42"]').exists()).toBe(true);
        expect(wrapper.find('option[value="901"]').exists()).toBe(false);
    });
});
