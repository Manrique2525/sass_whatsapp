import type { Channel } from 'laravel-echo';
import { onBeforeUnmount, watch, type WatchSource } from 'vue';
import type { Conversation } from '@/features/conversations/conversationUtils';
import type { Message } from '@/features/messages/messageTypes';
import { initEcho } from './echo';

export interface ConversationChannelHandlers {
    onMessageCreated: (message: Message) => void;
    onMessageStatusUpdated: (message: Message, previousStatus: string) => void;
    onConversationUpdated: (conversation: Conversation) => void;
}

export function useConversationChannel(
    tenantId: WatchSource<string | null>,
    conversationId: WatchSource<string | null>,
    handlers: ConversationChannelHandlers,
): void {
    let channel: Channel | null = null;

    const unsubscribe = (): void => {
        if (channel === null) {
            return;
        }

        channel.stopListening('.MessageCreated');
        channel.stopListening('.MessageStatusUpdated');
        channel.stopListening('.ConversationUpdated');
        channel = null;
    };

    const subscribe = (tenant: string | null, conversation: string | null): void => {
        unsubscribe();

        if (tenant === null || conversation === null) {
            return;
        }

        const instance = initEcho();

        if (instance === null) {
            return;
        }

        const next = instance.channel(`tenant.${tenant}.conversations.${conversation}`);
        channel = next;

        next.listen('.MessageCreated', (payload: { message: Message }) => {
            handlers.onMessageCreated(payload.message);
        });
        next.listen('.MessageStatusUpdated', (payload: { message: Message; previous_status: string }) => {
            handlers.onMessageStatusUpdated(payload.message, payload.previous_status);
        });
        next.listen('.ConversationUpdated', (payload: { conversation: Conversation }) => {
            handlers.onConversationUpdated(payload.conversation);
        });
    };

    watch(
        [tenantId, conversationId],
        ([tenant, conversation]) => {
            subscribe(tenant, conversation);
        },
        { immediate: true },
    );

    onBeforeUnmount(unsubscribe);
}
