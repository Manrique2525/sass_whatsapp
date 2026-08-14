import { describe, expect, it } from 'vitest';
import {
    buildConversationQuery,
    canClose,
    canReopen,
    formatLastInteraction,
    type Conversation,
} from '@/features/conversations/conversationUtils';

const conversation = (overrides: Partial<Conversation> = {}): Conversation => ({
    id: 'conv-1',
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
    created_at: '2026-08-15T10:00:00.000000Z',
    updated_at: '2026-08-15T10:00:00.000000Z',
    ...overrides,
});

describe('buildConversationQuery', () => {
    it('omite filtros vacíos', () => {
        expect(buildConversationQuery({ search: '', status: '', agent_id: '' })).toEqual({});
    });

    it('incluye filtros presentes', () => {
        expect(
            buildConversationQuery({
                search: '  ana  ',
                status: 'pending',
                agent_id: 7,
                page: 2,
                perPage: 25,
            }),
        ).toEqual({
            search: 'ana',
            status: 'pending',
            agent_id: '7',
            page: '2',
            per_page: '25',
        });
    });

    it('no envía page si es la primera', () => {
        expect(buildConversationQuery({ page: 1 })).toEqual({});
    });
});

describe('formatLastInteraction', () => {
    it('devuelve em dash si no hubo interacción', () => {
        expect(formatLastInteraction(conversation())).toBe('—');
    });

    it('formatea fechas válidas', () => {
        const formatted = formatLastInteraction(
            conversation({ last_interaction_at: '2026-08-14T15:30:00.000000Z' }),
        );

        expect(formatted).toMatch(/\d{2}\/\d{2}\/\d{2}, \d{2}:\d{2}/);
    });
});

describe('canClose / canReopen', () => {
    it('solo cierra abiertas o pendientes', () => {
        expect(canClose('open')).toBe(true);
        expect(canClose('pending')).toBe(true);
        expect(canClose('resolved')).toBe(false);
        expect(canClose('archived')).toBe(false);
    });

    it('solo reabre resueltas o archivadas', () => {
        expect(canReopen('resolved')).toBe(true);
        expect(canReopen('archived')).toBe(true);
        expect(canReopen('open')).toBe(false);
        expect(canReopen('pending')).toBe(false);
    });
});
