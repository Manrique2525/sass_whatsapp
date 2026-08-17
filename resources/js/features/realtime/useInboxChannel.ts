import type { Channel } from 'laravel-echo';
import { onBeforeUnmount, ref, watch, type WatchSource } from 'vue';
import type { Conversation } from '@/features/conversations/conversationUtils';
import { isInboxChangeKind, type InboxConversationChangeKind } from './inboxChannelTypes';
import { initEcho } from './echo';

export interface InboxChannelHandlers {
    onInboxChanged: (conversation: Conversation, kind: InboxConversationChangeKind, eventId: string) => void;
}

/**
 * Suscribe al canal privado tenant-wide (`private-tenant.{id}.inbox`).
 *
 * Recibe `InboxConversationChanged` para upsert en la lista del Inbox
 * sin depender de polling (ADR-053).
 *
 * - Deduplica por `event_id`.
 * - Cleanup: abandona el canal al desmontar o cambiar de tenant.
 * - Maneja reconnect sin duplicar listeners.
 * - No guarda en localStorage ni implementa notification center.
 */
export function useInboxChannel(
    tenantId: WatchSource<string | null>,
    handlers: InboxChannelHandlers,
): void {
    let channel: Channel | null = null;
    const seenEventIds = ref<Set<string>>(new Set());
    const MAX_SEEN = 500;

    const unsubscribe = (): void => {
        if (channel === null) {
            return;
        }

        channel.stopListening('.InboxConversationChanged');
        channel = null;
    };

    const subscribe = (tenant: string | null): void => {
        unsubscribe();

        if (tenant === null) {
            return;
        }

        const instance = initEcho();

        if (instance === null) {
            return;
        }

        const next = instance.private(`tenant.${tenant}.inbox`);
        channel = next;

        next.listen('.InboxConversationChanged', (payload: {
            event_id: string;
            kind: string;
            conversation: Conversation;
        }) => {
            if (!isInboxChangeKind(payload.kind)) {
                return;
            }

            if (seenEventIds.value.has(payload.event_id)) {
                return;
            }

            seenEventIds.value.add(payload.event_id);

            if (seenEventIds.value.size > MAX_SEEN) {
                const ids = Array.from(seenEventIds.value);
                seenEventIds.value = new Set(ids.slice(-MAX_SEEN));
            }

            handlers.onInboxChanged(payload.conversation, payload.kind, payload.event_id);
        });
    };

    watch(
        tenantId,
        (tenant) => {
            seenEventIds.value = new Set();
            subscribe(tenant);
        },
        { immediate: true },
    );

    onBeforeUnmount(unsubscribe);
}
