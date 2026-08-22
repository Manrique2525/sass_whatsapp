export type NotificationType = 'handoff_requested' | 'conversation_assigned';

export type NotificationPriority = 'high' | 'normal' | 'low';

export interface Notification {
    id: string;
    type: NotificationType;
    priority: NotificationPriority;
    title: string;
    body: string;
    data: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
}

export interface NotificationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface NotificationListResponse {
    notifications: Notification[];
    meta: NotificationMeta;
    counts: {
        unread: number;
    };
}

export interface UnreadCountResponse {
    unread_count: number;
}

export interface MarkReadResponse {
    notification: Notification;
}

export interface MarkAllReadResponse {
    message: string;
    updated: number;
    counts: {
        unread: number;
    };
}

export interface NotificationPreference {
    email_notifications_enabled: boolean;
}

export type NotificationFilters = {
    read_status?: 'all' | 'unread' | 'read';
    per_page?: number;
};
