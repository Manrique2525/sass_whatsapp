/**
 * Tipos de cambio que alteran la lista del Inbox (canal privado tenant-wide).
 *
 * Mirror de `App\Domain\Conversations\Enums\InboxConversationChangeKind` (ADR-053).
 * El conjunto es cerrado: el frontend ignora kinds desconocidos.
 */
export const INBOX_CHANGE_KINDS = [
    'handoff_requested',
    'assigned',
    'claimed',
    'transferred',
    'bot_resumed',
    'conversation_updated',
] as const;

export type InboxConversationChangeKind = (typeof INBOX_CHANGE_KINDS)[number];

export function isInboxChangeKind(value: string): value is InboxConversationChangeKind {
    return (INBOX_CHANGE_KINDS as readonly string[]).includes(value);
}

/**
 * Payload del evento `InboxConversationChanged` recibido por WebSocket.
 */
export interface InboxConversationChangedPayload {
    event_id: string;
    kind: InboxConversationChangeKind;
    conversation: import('@/features/conversations/conversationUtils').Conversation;
}
