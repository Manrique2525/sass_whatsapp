export type MessageDirection = 'inbound' | 'outbound';

export type MessageStatus = 'pending' | 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

export type MessageType =
    | 'text'
    | 'image'
    | 'audio'
    | 'video'
    | 'document'
    | 'location'
    | 'interactive'
    | 'template';

export interface Message {
    id: string;
    conversation_id: string;
    provider_message_id: string | null;
    direction: MessageDirection;
    type: MessageType;
    status: MessageStatus;
    body: string | null;
    media_url: string | null;
    media_mime: string | null;
    media_size: number | null;
    metadata: Record<string, unknown> | null;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    failed_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface MessagePagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface MessageCreatedPayload {
    message: Message;
}

export interface MessageStatusUpdatedPayload {
    message: Message;
    previous_status: string;
}
