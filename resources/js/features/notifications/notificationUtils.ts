import type { Notification, NotificationPriority, NotificationType } from './notificationTypes';

export function isUnread(notification: Notification): boolean {
    return notification.read_at === null;
}

export function notificationTypeLabel(type: NotificationType): string {
    const labels: Record<NotificationType, string> = {
        handoff_requested: 'Atención requerida',
        conversation_assigned: 'Conversación asignada',
    };

    return labels[type] ?? type;
}

export function notificationPriorityLabel(priority: NotificationPriority): string {
    const labels: Record<NotificationPriority, string> = {
        high: 'Alta',
        normal: 'Normal',
        low: 'Baja',
    };

    return labels[priority] ?? priority;
}

export function formatUnreadBadge(count: number): string {
    if (count <= 0) {
        return '';
    }

    if (count >= 100) {
        return '99+';
    }

    return String(count);
}

export function shouldShowBadge(count: number): boolean {
    return count > 0;
}

export function dedupeNotifications(list: Notification[], incoming: Notification): Notification[] {
    if (list.some((n) => n.id === incoming.id)) {
        return list;
    }

    return [incoming, ...list];
}

export function prependNotification(list: Notification[], notification: Notification): Notification[] {
    return dedupeNotifications(list, notification);
}

export function markNotificationInList(list: Notification[], notificationId: string): Notification[] {
    return list.map((n) => {
        if (n.id === notificationId && n.read_at === null) {
            return { ...n, read_at: new Date().toISOString() };
        }

        return n;
    });
}

export function markAllInListAsRead(list: Notification[]): Notification[] {
    const now = new Date().toISOString();

    return list.map((n) => {
        if (n.read_at === null) {
            return { ...n, read_at: now };
        }

        return n;
    });
}

export function countUnread(list: Notification[]): number {
    return list.filter((n) => n.read_at === null).length;
}

export function safeNotificationPayload(data: unknown): boolean {
    if (data === null || data === undefined || typeof data !== 'object') {
        return true;
    }

    const forbidden = ['tenant_id', 'user_id', 'phone', 'email', 'contact_name', 'message_body'];

    for (const key of forbidden) {
        if (key in (data as Record<string, unknown>)) {
            return false;
        }
    }

    return true;
}
