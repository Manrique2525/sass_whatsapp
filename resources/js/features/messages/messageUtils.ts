import type { Message, MessageStatus, MessageType } from './messageTypes';

export interface MessageDayGroup {
    key: string;
    label: string;
    items: Message[];
}

const MEDIA_LABEL: Partial<Record<MessageType, string>> = {
    image: '[imagen]',
    audio: '[audio]',
    video: '[video]',
    document: '[documento]',
    location: '[ubicacion]',
    interactive: '[interactivo]',
    template: '[plantilla]',
};

const STATUS_LABEL: Record<MessageStatus, string> = {
    pending: 'Pendiente',
    sending: 'Enviando',
    sent: 'Enviado',
    delivered: 'Entregado',
    read: 'Leido',
    failed: 'Error',
};

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function isSameDay(a: Date, b: Date): boolean {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

export function buildMessageQuery(page: number): Record<string, string> {
    const params: Record<string, string> = {};

    if (page > 1) {
        params.page = String(page);
    }

    return params;
}

export function isNearBottom(scrollTop: number, scrollHeight: number, clientHeight: number, threshold = 120): boolean {
    return scrollHeight - scrollTop - clientHeight <= threshold;
}

export function isOutbound(message: Message): boolean {
    return message.direction === 'outbound';
}

export function formatMessageTimestamp(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function dayKey(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function messageDayLabel(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (isSameDay(date, today)) {
        return 'Hoy';
    }

    if (isSameDay(date, yesterday)) {
        return 'Ayer';
    }

    return date.toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' });
}

export function groupMessagesByDay(messages: Message[]): MessageDayGroup[] {
    const groups: MessageDayGroup[] = [];

    for (const message of messages) {
        const key = dayKey(message.created_at);
        const last = groups.at(-1);

        if (last !== undefined && last.key === key) {
            last.items.push(message);
        } else {
            groups.push({ key, label: messageDayLabel(message.created_at), items: [message] });
        }
    }

    return groups;
}

export function mergeIncomingMessage(current: Message[], incoming: Message): Message[] {
    if (current.some((message) => message.id === incoming.id)) {
        return current;
    }

    return [...current, incoming].sort(compareMessagesChronologically);
}

/**
 * Orden total determinista de mensajes (FASE 32 U1).
 *
 * Orden cronológico ASC por `created_at`; si dos mensajes comparten el mismo
 * timestamp (segundo en la base de datos), se desempata por `id` (UUIDv7,
 * monótono en el tiempo) para que el mismo conjunto de mensajes produzca
 * SIEMPRE la misma secuencia — tanto en reload (backend `ORDER BY created_at,
 * id`) como en cada merge realtime. No representa orden de inserción en la DB.
 */
export function compareMessagesChronologically(a: Message, b: Message): number {
    const byCreatedAt = a.created_at.localeCompare(b.created_at);

    if (byCreatedAt !== 0) {
        return byCreatedAt;
    }

    return a.id.localeCompare(b.id);
}

export function applyMessageUpdate(current: Message[], updated: Message): Message[] {
    return current.map((message) => (message.id === updated.id ? updated : message));
}

export function messagePreview(message: Message | null): string {
    if (message === null) {
        return '';
    }

    if (message.body !== null && message.body.trim() !== '') {
        return message.body;
    }

    return MEDIA_LABEL[message.type] ?? '';
}

export function messageStatusLabel(status: MessageStatus): string {
    return STATUS_LABEL[status];
}
