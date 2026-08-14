export type ConversationStatus = 'open' | 'pending' | 'resolved' | 'archived';

import type { Message } from '@/features/messages/messageTypes';

export interface ConversationContact {
    id: string;
    phone: string;
    name: string;
    email: string | null;
    avatar_url: string | null;
    metadata: Record<string, unknown> | null;
    provider_contact_id: string | null;
    last_interaction_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ConversationAgent {
    id: number;
    name: string;
    email: string;
}

export interface Conversation {
    id: string;
    status: ConversationStatus;
    status_label: string;
    contact: ConversationContact | null;
    agent: ConversationAgent | null;
    last_message_at: string | null;
    last_interaction_at: string | null;
    auto_assigned: boolean;
    bot_paused: boolean;
    context: Record<string, unknown> | null;
    flow_execution_id: string | null;
    last_message: Message | null;
    created_at: string;
    updated_at: string;
}

export interface TenantMember {
    id: number;
    user: {
        id: number;
        name: string;
        email: string;
    };
    role: string;
}

export interface ConversationFilters {
    search?: string;
    status?: ConversationStatus | '';
    agent_id?: number | '';
    page?: number;
    perPage?: number;
}

export const CONVERSATION_STATUS_META: Record<ConversationStatus, { label: string; badge: string; dot: string }> = {
    open: { label: 'Abierta', badge: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    pending: { label: 'Pendiente', badge: 'bg-amber-50 text-amber-700', dot: 'bg-amber-500' },
    resolved: { label: 'Resuelta', badge: 'bg-sky-50 text-sky-700', dot: 'bg-sky-500' },
    archived: { label: 'Archivada', badge: 'bg-zinc-100 text-zinc-600', dot: 'bg-zinc-400' },
};

/**
 * Construye los query params del listado. Omite filtros vacíos. El backend
 * valida `status` contra la máquina de estados (Enum) y acota `per_page`.
 */
export function buildConversationQuery(filters: ConversationFilters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.search !== undefined && filters.search.trim() !== '') {
        params.search = filters.search.trim();
    }

    if (filters.status !== undefined && filters.status !== '') {
        params.status = filters.status;
    }

    if (filters.agent_id !== undefined && filters.agent_id !== '') {
        params.agent_id = String(filters.agent_id);
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.page = String(filters.page);
    }

    if (filters.perPage !== undefined && filters.perPage > 0) {
        params.per_page = String(filters.perPage);
    }

    return params;
}

/**
 * Formatea la última interacción para la tabla (null → "—").
 */
export function formatLastInteraction(conversation: Conversation): string {
    if (conversation.last_interaction_at === null) {
        return '—';
    }

    const date = new Date(conversation.last_interaction_at);

    if (Number.isNaN(date.getTime())) {
        return conversation.last_interaction_at;
    }

    return date.toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Solo una conversación abierta/pendiente se puede cerrar.
 */
export function canClose(status: ConversationStatus): boolean {
    return status === 'open' || status === 'pending';
}

/**
 * Solo una conversación resuelta/archivada se puede reabrir.
 */
export function canReopen(status: ConversationStatus): boolean {
    return status === 'resolved' || status === 'archived';
}

/**
 * Extrae `message` del body de error de la API o devuelve el fallback
 * (mismo formato de error estándar del backend).
 */
export function extractErrorMessage(err: unknown, fallback: string): string {
    if (
        typeof err === 'object' &&
        err !== null &&
        'response' in err &&
        typeof err.response === 'object' &&
        err.response !== null &&
        'data' in err.response &&
        typeof err.response.data === 'object' &&
        err.response.data !== null &&
        'message' in err.response.data &&
        typeof err.response.data.message === 'string'
    ) {
        return err.response.data.message;
    }

    return fallback;
}
