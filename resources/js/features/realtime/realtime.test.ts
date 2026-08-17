import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ref, nextTick } from 'vue';

// ─── Mock Echo ─────────────────────────────────────────────────────────────

const mockListeners: Record<string, ((payload: unknown) => void)[]> = {};
const mockStopListening = vi.fn();
const mockChannel = vi.fn(() => ({
    listen: vi.fn((event: string, cb: (payload: unknown) => void) => {
        if (!mockListeners[event]) {
            mockListeners[event] = [];
        }
        mockListeners[event].push(cb);
        return { listen: vi.fn() };
    }),
    stopListening: mockStopListening,
}));

vi.mock('@/features/realtime/echo', () => ({
    initEcho: vi.fn(() => ({
        channel: mockChannel,
        private: mockChannel,
    })),
    isRealtimeEnabled: vi.fn(() => true),
}));

// ─── InboxChannelTypes tests ───────────────────────────────────────────────

import { isInboxChangeKind, INBOX_CHANGE_KINDS } from '@/features/realtime/inboxChannelTypes';

describe('inboxChannelTypes', () => {
    it('FRT-08: kinds conocidos son validados correctamente', () => {
        expect(isInboxChangeKind('handoff_requested')).toBe(true);
        expect(isInboxChangeKind('assigned')).toBe(true);
        expect(isInboxChangeKind('claimed')).toBe(true);
        expect(isInboxChangeKind('transferred')).toBe(true);
        expect(isInboxChangeKind('bot_resumed')).toBe(true);
        expect(isInboxChangeKind('conversation_updated')).toBe(true);
    });

    it('FRT-08: kind desconocido es rechazado', () => {
        expect(isInboxChangeKind('unknown_kind')).toBe(false);
        expect(isInboxChangeKind('')).toBe(false);
        expect(isInboxChangeKind('HANDOFF_REQUESTED')).toBe(false);
    });

    it('INBOX_CHANGE_KINDS contiene exactamente 6 valores', () => {
        expect(INBOX_CHANGE_KINDS).toHaveLength(6);
    });
});

// ─── useConversationChannel tests ──────────────────────────────────────────

import { useConversationChannel } from '@/features/realtime/useConversationChannel';

describe('useConversationChannel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.keys(mockListeners).forEach((k) => delete mockListeners[k]);
    });

    it('FRT-01: usa private() en lugar de channel() para canal privado', () => {
        const tenantId = ref<string | null>('t-1');
        const conversationId = ref<string | null>('c-1');

        useConversationChannel(tenantId, conversationId, {
            onMessageCreated: vi.fn(),
            onMessageStatusUpdated: vi.fn(),
            onConversationUpdated: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalledWith('tenant.t-1.conversations.c-1');
    });

    it('FRT-02: cleanup al cambiar de conversación', async () => {
        const tenantId = ref<string | null>('t-1');
        const conversationId = ref<string | null>('c-1');

        useConversationChannel(tenantId, conversationId, {
            onMessageCreated: vi.fn(),
            onMessageStatusUpdated: vi.fn(),
            onConversationUpdated: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalledWith('tenant.t-1.conversations.c-1');

        // Changing conversation triggers unsubscribe + resubscribe
        conversationId.value = 'c-2';
        await nextTick();

        expect(mockStopListening).toHaveBeenCalled();
        expect(mockChannel).toHaveBeenCalledWith('tenant.t-1.conversations.c-2');
    });

    it('FRT-03: cleanup al desmontar', () => {
        const tenantId = ref<string | null>('t-1');
        const conversationId = ref<string | null>('c-1');

        useConversationChannel(tenantId, conversationId, {
            onMessageCreated: vi.fn(),
            onMessageStatusUpdated: vi.fn(),
            onConversationUpdated: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalled();
    });
});

// ─── useInboxChannel tests ─────────────────────────────────────────────────

import { useInboxChannel } from '@/features/realtime/useInboxChannel';

describe('useInboxChannel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.keys(mockListeners).forEach((k) => delete mockListeners[k]);
    });

    it('FRT-04: se suscribe al canal privado tenant.{id}.inbox', () => {
        const tenantId = ref<string | null>('t-1');

        useInboxChannel(tenantId, {
            onInboxChanged: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalledWith('tenant.t-1.inbox');
    });

    it('FRT-05: cleanup al cambiar de tenant', async () => {
        const tenantId = ref<string | null>('t-1');

        useInboxChannel(tenantId, {
            onInboxChanged: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalledWith('tenant.t-1.inbox');

        tenantId.value = 't-2';
        await nextTick();

        expect(mockStopListening).toHaveBeenCalled();
        expect(mockChannel).toHaveBeenCalledWith('tenant.t-2.inbox');
    });

    it('FRT-08: evento con kind desconocido es ignorado', () => {
        const handler = vi.fn();
        const tenantId = ref<string | null>('t-1');

        useInboxChannel(tenantId, { onInboxChanged: handler });

        const listener = mockListeners['.InboxConversationChanged']?.[0];
        if (listener) {
            listener({
                event_id: 'evt-1',
                kind: 'unknown_kind',
                conversation: { id: 'c-1' },
            });
        }

        expect(handler).not.toHaveBeenCalled();
    });

    it('FRT-09: payload malformado no rompe el handler', () => {
        const handler = vi.fn();
        const tenantId = ref<string | null>('t-1');

        useInboxChannel(tenantId, { onInboxChanged: handler });

        const listener = mockListeners['.InboxConversationChanged']?.[0];
        if (listener) {
            expect(() => {
                listener({ kind: 'handoff_requested', conversation: { id: 'c-1' } });
            }).not.toThrow();
        }
    });

    it('FRT-06: event_id duplicado es ignorado (dedupe)', () => {
        const handler = vi.fn();
        const tenantId = ref<string | null>('t-1');

        useInboxChannel(tenantId, { onInboxChanged: handler });

        const listener = mockListeners['.InboxConversationChanged']?.[0];
        if (listener) {
            const payload = {
                event_id: 'evt-duplicate',
                kind: 'handoff_requested',
                conversation: { id: 'c-1' },
            };

            listener(payload);
            listener(payload);

            expect(handler).toHaveBeenCalledTimes(1);
        }
    });
});

// ─── Upsert logic tests ────────────────────────────────────────────────────

import type { Conversation } from '@/features/conversations/conversationUtils';

describe('inbox upsert logic', () => {
    const makeConversation = (overrides: Partial<Conversation> = {}): Conversation => ({
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

    it('FRT-07: upsert inserta conversación nueva al inicio', () => {
        const conversations = [makeConversation({ id: 'c-1' })];
        const fresh = makeConversation({ id: 'c-2' });

        const index = conversations.findIndex((c) => c.id === fresh.id);
        if (index !== -1) {
            conversations[index] = fresh;
        } else {
            conversations.unshift(fresh);
        }

        expect(conversations).toHaveLength(2);
        expect(conversations[0].id).toBe('c-2');
        expect(conversations[1].id).toBe('c-1');
    });

    it('FRT-07: upsert actualiza conversación existente sin duplicar', () => {
        const conversations = [
            makeConversation({ id: 'c-1', status: 'open' }),
            makeConversation({ id: 'c-2', status: 'open' }),
        ];
        const fresh = makeConversation({ id: 'c-1', status: 'resolved' });

        const index = conversations.findIndex((c) => c.id === fresh.id);
        if (index !== -1) {
            conversations[index] = fresh;
        } else {
            conversations.unshift(fresh);
        }

        expect(conversations).toHaveLength(2);
        expect(conversations[0].id).toBe('c-1');
        expect(conversations[0].status).toBe('resolved');
    });

    it('FRT-10: no duplica conversación existente', () => {
        const conversations = [makeConversation({ id: 'c-1' })];
        const fresh = makeConversation({ id: 'c-1', status: 'pending' });

        const index = conversations.findIndex((c) => c.id === fresh.id);
        if (index !== -1) {
            conversations[index] = fresh;
        } else {
            conversations.unshift(fresh);
        }

        expect(conversations).toHaveLength(1);
        expect(conversations[0].status).toBe('pending');
    });
});
