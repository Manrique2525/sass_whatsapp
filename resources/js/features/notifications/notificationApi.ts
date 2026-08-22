import type {
    MarkAllReadResponse,
    MarkReadResponse,
    NotificationFilters,
    NotificationListResponse,
    NotificationPreference,
    UnreadCountResponse,
} from './notificationTypes';

export async function fetchNotifications(tenantId: string, filters?: NotificationFilters): Promise<NotificationListResponse> {
    const params: Record<string, string | number> = {};

    if (filters?.read_status !== undefined && filters.read_status !== 'all') {
        params.read_status = filters.read_status;
    }

    if (filters?.per_page !== undefined) {
        params.per_page = filters.per_page;
    }

    const res = await window.axios.get(`/api/v1/tenants/${tenantId}/notifications`, { params });

    return res.data;
}

export async function fetchUnreadCount(tenantId: string): Promise<UnreadCountResponse> {
    const res = await window.axios.get(`/api/v1/tenants/${tenantId}/notifications/unread-count`);

    return res.data;
}

export async function markNotificationRead(tenantId: string, notificationId: string): Promise<MarkReadResponse> {
    const res = await window.axios.patch(`/api/v1/tenants/${tenantId}/notifications/${notificationId}/read`);

    return res.data;
}

export async function markAllNotificationsRead(tenantId: string): Promise<MarkAllReadResponse> {
    const res = await window.axios.post(`/api/v1/tenants/${tenantId}/notifications/read-all`);

    return res.data;
}

export async function fetchNotificationPreference(tenantId: string): Promise<NotificationPreference> {
    const res = await window.axios.get(`/api/v1/tenants/${tenantId}/notification-preferences`);

    return res.data;
}

export async function updateNotificationPreference(
    tenantId: string,
    emailEnabled: boolean,
): Promise<{ message: string; email_notifications_enabled: boolean }> {
    const res = await window.axios.patch(`/api/v1/tenants/${tenantId}/notification-preferences`, {
        email_notifications_enabled: emailEnabled,
    });

    return res.data;
}
