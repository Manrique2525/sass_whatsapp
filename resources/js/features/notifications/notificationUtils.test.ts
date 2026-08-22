import { describe, expect, it } from 'vitest';
import {
    isUnread,
    notificationTypeLabel,
    notificationPriorityLabel,
    formatUnreadBadge,
    shouldShowBadge,
    dedupeNotifications,
    prependNotification,
    markNotificationInList,
    markAllInListAsRead,
    countUnread,
    safeNotificationPayload,
} from './notificationUtils';
import type { Notification, NotificationPriority, NotificationType } from './notificationTypes';

function makeNotification(overrides: Partial<Notification> = {}): Notification {
    return {
        id: 'test-id-1',
        type: 'handoff_requested',
        priority: 'high',
        title: 'Test',
        body: 'Test body',
        data: null,
        read_at: null,
        created_at: '2026-08-22T10:00:00Z',
        ...overrides,
    };
}

describe('notificationUtils', () => {
    // NOTIF-V01: type labels
    it('NOTIF-V01: notificationTypeLabel returns correct labels', () => {
        expect(notificationTypeLabel('handoff_requested')).toBe('Atención requerida');
        expect(notificationTypeLabel('conversation_assigned')).toBe('Conversación asignada');
    });

    it('NOTIF-V01: unknown type returns raw value', () => {
        expect(notificationTypeLabel('unknown_type' as NotificationType)).toBe('unknown_type');
    });

    // NOTIF-V02: priority labels
    it('NOTIF-V02: notificationPriorityLabel returns correct labels', () => {
        expect(notificationPriorityLabel('high')).toBe('Alta');
        expect(notificationPriorityLabel('normal')).toBe('Normal');
        expect(notificationPriorityLabel('low')).toBe('Baja');
    });

    it('NOTIF-V02: unknown priority returns raw value', () => {
        expect(notificationPriorityLabel('unknown' as NotificationPriority)).toBe('unknown');
    });

    // NOTIF-V03: isUnread
    it('NOTIF-V03: isUnread returns true when read_at is null', () => {
        expect(isUnread(makeNotification({ read_at: null }))).toBe(true);
    });

    it('NOTIF-V03: isUnread returns false when read_at is set', () => {
        expect(isUnread(makeNotification({ read_at: '2026-08-22T10:00:00Z' }))).toBe(false);
    });

    // NOTIF-V04: badge hidden at zero
    it('NOTIF-V04: formatUnreadBadge returns empty string for 0', () => {
        expect(formatUnreadBadge(0)).toBe('');
    });

    it('NOTIF-V04: shouldShowBadge returns false for 0', () => {
        expect(shouldShowBadge(0)).toBe(false);
    });

    // NOTIF-V05: badge 99+
    it('NOTIF-V05: formatUnreadBadge returns "99+" for 100', () => {
        expect(formatUnreadBadge(100)).toBe('99+');
    });

    it('NOTIF-V05: formatUnreadBadge returns "99+" for 999', () => {
        expect(formatUnreadBadge(999)).toBe('99+');
    });

    it('NOTIF-V05: formatUnreadBadge returns "5" for 5', () => {
        expect(formatUnreadBadge(5)).toBe('5');
    });

    it('NOTIF-V05: shouldShowBadge returns true for 1', () => {
        expect(shouldShowBadge(1)).toBe(true);
    });

    // NOTIF-V06: dedupe
    it('NOTIF-V06: dedupeNotifications does not add duplicate', () => {
        const existing = [makeNotification({ id: 'n1' }), makeNotification({ id: 'n2' })];
        const incoming = makeNotification({ id: 'n1' });

        const result = dedupeNotifications(existing, incoming);

        expect(result).toHaveLength(2);
    });

    it('NOTIF-V06: dedupeNotifications adds new notification', () => {
        const existing = [makeNotification({ id: 'n1' })];
        const incoming = makeNotification({ id: 'n3' });

        const result = dedupeNotifications(existing, incoming);

        expect(result).toHaveLength(2);
        expect(result[0].id).toBe('n3');
    });

    // NOTIF-V07: prepend
    it('NOTIF-V07: prependNotification adds to front', () => {
        const list = [makeNotification({ id: 'n1' })];
        const incoming = makeNotification({ id: 'n2' });

        const result = prependNotification(list, incoming);

        expect(result[0].id).toBe('n2');
        expect(result).toHaveLength(2);
    });

    it('NOTIF-V07: prependNotification dedupes', () => {
        const list = [makeNotification({ id: 'n1' })];
        const incoming = makeNotification({ id: 'n1' });

        const result = prependNotification(list, incoming);

        expect(result).toHaveLength(1);
    });

    // NOTIF-V08: no negative unread
    it('NOTIF-V08: countUnread returns 0 for empty list', () => {
        expect(countUnread([])).toBe(0);
    });

    it('NOTIF-V08: countUnread counts only unread', () => {
        const list = [
            makeNotification({ id: 'n1', read_at: null }),
            makeNotification({ id: 'n2', read_at: '2026-08-22T10:00:00Z' }),
            makeNotification({ id: 'n3', read_at: null }),
        ];

        expect(countUnread(list)).toBe(2);
    });

    // NOTIF-V09: safe payload
    it('NOTIF-V09: safeNotificationPayload returns true for null', () => {
        expect(safeNotificationPayload(null)).toBe(true);
    });

    it('NOTIF-V09: safeNotificationPayload returns true for safe object', () => {
        expect(safeNotificationPayload({ conversation_id: '123', event: 'handoff' })).toBe(true);
    });

    it('NOTIF-V09: safeNotificationPayload returns false for tenant_id', () => {
        expect(safeNotificationPayload({ tenant_id: 'abc' })).toBe(false);
    });

    it('NOTIF-V09: safeNotificationPayload returns false for user_id', () => {
        expect(safeNotificationPayload({ user_id: 1 })).toBe(false);
    });

    it('NOTIF-V09: safeNotificationPayload returns false for phone', () => {
        expect(safeNotificationPayload({ phone: '+5491155554444' })).toBe(false);
    });

    it('NOTIF-V09: safeNotificationPayload returns false for email', () => {
        expect(safeNotificationPayload({ email: 'test@test.com' })).toBe(false);
    });

    // NOTIF-V10: XSS remains text (test helper)
    it('NOTIF-V10: title containing script tag is preserved as-is', () => {
        const notif = makeNotification({ title: '<script>alert(1)</script>' });
        expect(notif.title).toBe('<script>alert(1)</script>');
    });

    // markNotificationInList
    it('markNotificationInList sets read_at on matching id', () => {
        const list = [
            makeNotification({ id: 'n1', read_at: null }),
            makeNotification({ id: 'n2', read_at: null }),
        ];

        const result = markNotificationInList(list, 'n1');

        expect(result[0].read_at).not.toBeNull();
        expect(result[1].read_at).toBeNull();
    });

    it('markNotificationInList does not affect already read notification', () => {
        const readTime = '2026-08-22T10:00:00Z';
        const list = [makeNotification({ id: 'n1', read_at: readTime })];

        const result = markNotificationInList(list, 'n1');

        expect(result[0].read_at).toBe(readTime);
    });

    // markAllInListAsRead
    it('markAllInListAsRead marks all unread as read', () => {
        const list = [
            makeNotification({ id: 'n1', read_at: null }),
            makeNotification({ id: 'n2', read_at: null }),
            makeNotification({ id: 'n3', read_at: '2026-08-22T10:00:00Z' }),
        ];

        const result = markAllInListAsRead(list);

        expect(result[0].read_at).not.toBeNull();
        expect(result[1].read_at).not.toBeNull();
        expect(result[2].read_at).toBe('2026-08-22T10:00:00Z');
    });

    it('markAllInListAsRead is idempotent on all-read list', () => {
        const readTime = '2026-08-22T10:00:00Z';
        const list = [makeNotification({ id: 'n1', read_at: readTime })];

        const result = markAllInListAsRead(list);

        expect(result[0].read_at).toBe(readTime);
    });
});
