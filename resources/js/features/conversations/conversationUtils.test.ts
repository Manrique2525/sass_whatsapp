import { describe, expect, it } from 'vitest';
import {
    buildConversationQuery,
    canClose,
    canReopen,
    formatLastInteraction,
    isHumanActive,
    isManualPause,
    isUnassignedHandoff,
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
    handoff_requested_at: null,
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

describe('isUnassignedHandoff', () => {
    it('UI-01: true cuando handoff_requested_at != null y agent == null', () => {
        expect(
            isUnassignedHandoff(
                conversation({
                    handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                    agent: null,
                }),
            ),
        ).toBe(true);
    });

    it('UI-02: false cuando hay agente asignado', () => {
        expect(
            isUnassignedHandoff(
                conversation({
                    handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                    agent: { id: 1, name: 'Agent', email: 'a@test.com' },
                }),
            ),
        ).toBe(false);
    });

    it('UI-03: false cuando no hay handoff', () => {
        expect(isUnassignedHandoff(conversation({ agent: null }))).toBe(false);
    });
});

describe('isHumanActive', () => {
    it('UI-04: true cuando bot_paused y handoff_requested_at != null', () => {
        expect(
            isHumanActive(
                conversation({
                    bot_paused: true,
                    handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                }),
            ),
        ).toBe(true);
    });

    it('UI-05: false cuando bot no esta pausado', () => {
        expect(
            isHumanActive(
                conversation({
                    bot_paused: false,
                    handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                }),
            ),
        ).toBe(false);
    });
});

describe('isManualPause', () => {
    it('UI-06: true cuando bot_paused y handoff_requested_at == null', () => {
        expect(isManualPause(conversation({ bot_paused: true }))).toBe(true);
    });

    it('UI-07: false cuando hay handoff', () => {
        expect(
            isManualPause(
                conversation({
                    bot_paused: true,
                    handoff_requested_at: '2026-08-15T10:00:00.000000Z',
                }),
            ),
        ).toBe(false);
    });

    it('UI-08: false cuando bot no esta pausado', () => {
        expect(isManualPause(conversation({ bot_paused: false }))).toBe(false);
    });
});

describe('buildConversationQuery scope', () => {
    it('UI-09: incluye scope cuando no es all', () => {
        expect(buildConversationQuery({ scope: 'mine' })).toEqual({ scope: 'mine' });
        expect(buildConversationQuery({ scope: 'unassigned' })).toEqual({ scope: 'unassigned' });
    });

    it('UI-10: omite scope cuando es all', () => {
        expect(buildConversationQuery({ scope: 'all' })).toEqual({});
    });
});
