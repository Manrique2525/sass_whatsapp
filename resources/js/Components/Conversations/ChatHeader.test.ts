import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChatHeader from '@/Components/Conversations/ChatHeader.vue';
import type { Conversation, TenantMember } from '@/features/conversations/conversationUtils';

const memberA: TenantMember = {
    id: 100,
    user: { id: 1, name: 'Agente A', email: 'a@test.com' },
    role: 'agent',
};

const memberB: TenantMember = {
    id: 101,
    user: { id: 2, name: 'Agente B', email: 'b@test.com' },
    role: 'agent',
};

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

const mountHeader = (overrides: Partial<Conversation> = {}, props: Record<string, unknown> = {}) =>
    mount(ChatHeader, {
        props: {
            conversation: baseConv(overrides),
            members: [memberA, memberB],
            canManage: true,
            canAssign: true,
            canClaim: true,
            currentUserId: 1,
            acting: false,
            ...props,
        },
    });

describe('ChatHeader — handoff + claim', () => {
    it('UI-11: muestra boton Reclamar cuando es unassigned handoff', () => {
        const wrapper = mountHeader({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: null,
        });

        expect(wrapper.findAll('button').filter((b) => b.text().includes('Reclamar')).length).toBe(1);
    });

    it('UI-12: oculta Reclamar cuando canClaim es false', () => {
        const wrapper = mountHeader(
            { handoff_requested_at: '2026-08-15T10:00:00.000000Z', agent: null },
            { canClaim: false },
        );

        expect(wrapper.findAll('button').filter((b) => b.text().includes('Reclamar')).length).toBe(0);
    });

    it('UI-13: emite claim al hacer click', async () => {
        const wrapper = mountHeader({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: null,
        });

        const claimBtn = wrapper.findAll('button').find((b) => b.text().includes('Reclamar'));
        await claimBtn!.trigger('click');

        expect(wrapper.emitted('claim')).toHaveLength(1);
    });

    it('UI-14: muestra banner de handoff cuando es unassigned', () => {
        const wrapper = mountHeader({
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: null,
        });

        expect(wrapper.text()).toContain('requiere atencion humana');
    });
});

describe('ChatHeader — bot/human status labels', () => {
    it('UI-15: muestra "Atencion humana (vos)" cuando asignado a mi', () => {
        const wrapper = mountHeader({
            bot_paused: true,
            handoff_requested_at: '2026-08-15T10:00:00.000000Z',
            agent: { id: 1, name: 'Agente A', email: 'a@test.com' },
        });

        expect(wrapper.text()).toContain('Atencion humana (vos)');
    });

    it('UI-16: muestra nombre del agente cuando asignado a otro', () => {
        const wrapper = mountHeader(
            {
                bot_paused: true,
                handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                agent: { id: 2, name: 'Agente B', email: 'b@test.com' },
            },
            { currentUserId: 1 },
        );

        expect(wrapper.text()).toContain('Atencion humana (Agente B)');
    });

    it('UI-17: muestra "Bot activo" cuando no esta pausado', () => {
        const wrapper = mountHeader({ bot_paused: false });

        expect(wrapper.text()).toContain('Bot activo');
    });

    it('UI-18: muestra "Bot pausado manualmente" cuando pausado sin handoff', () => {
        const wrapper = mountHeader({ bot_paused: true, handoff_requested_at: null });

        expect(wrapper.text()).toContain('Bot pausado manualmente');
    });
});

describe('ChatHeader — assign/transfer dropdown', () => {
    it('UI-19: excluye al usuario actual del dropdown de asignar', () => {
        const wrapper = mountHeader({}, { currentUserId: 1 });

        const options = wrapper.findAll('select option');
        const agentIds = options.map((o) => (o.element as HTMLInputElement).value).filter((v) => v !== '');

        expect(agentIds).not.toContain('1');
        expect(agentIds).toContain('2');
    });

    it('UI-20: muestra "Transferir a..." cuando ya hay agente', () => {
        const wrapper = mountHeader({
            agent: { id: 1, name: 'Agente A', email: 'a@test.com' },
        });

        expect(wrapper.find('select').text()).toContain('Transferir a...');
    });
});
