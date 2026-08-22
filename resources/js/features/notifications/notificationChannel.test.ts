import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ref, nextTick } from 'vue';
import type { Notification } from './notificationTypes';

// ─── Mock Echo ─────────────────────────────────────────────────────────────

const mockListeners: Record<string, ((payload: unknown) => void)[]> = {};
const mockStopListening = vi.fn();
const mockChannel = vi.fn(() => ({
    listen: vi.fn((event: string, cb: (payload: unknown) => void) => {
        if (!mockListeners[event]) {
            mockListeners[event] = [];
        }
        mockListeners[event].push(cb);
        return { listen: vi.fn() };
    }),
    stopListening: mockStopListening,
}));

vi.mock('@/features/realtime/echo', () => ({
    initEcho: vi.fn(() => ({
        channel: mockChannel,
        private: mockChannel,
    })),
    isRealtimeEnabled: vi.fn(() => true),
}));

import { useNotificationChannel } from './useNotificationChannel';
import { prependNotification, dedupeNotifications } from './notificationUtils';

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

beforeEach(() => {
    Object.keys(mockListeners).forEach((key) => delete mockListeners[key]);
    mockStopListening.mockClear();
    mockChannel.mockClear();
});

describe('useNotificationChannel', () => {
    // NOTIF-V19: subscribes correct channel
    it('NOTIF-V19: subscribes to personal notification channel', () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        expect(mockChannel).toHaveBeenCalledWith('tenant.tenant-1.users.1.notifications');
    });

    // NOTIF-V20: event prepended
    it('NOTIF-V20: notification event prepended to list', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);
        const handler = vi.fn();

        const { notifications } = useNotificationChannel(tenantId, userId, {
            onNotificationCreated: handler,
        });

        const notif = makeNotification({ id: 'n1' });
        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: notif }),
        );

        await nextTick();

        expect(notifications.value).toHaveLength(1);
        expect(notifications.value![0].id).toBe('n1');
        expect(handler).toHaveBeenCalledWith(notif);
    });

    // NOTIF-V21: duplicate event ignored
    it('NOTIF-V21: duplicate notification event is ignored', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        const { notifications } = useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        const notif = makeNotification({ id: 'n1' });

        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: notif }),
        );
        await nextTick();

        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: notif }),
        );
        await nextTick();

        expect(notifications.value).toHaveLength(1);
    });

    // NOTIF-V22: unread increment via handler
    it('NOTIF-V22: handler receives notification for unread tracking', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);
        const handler = vi.fn();

        useNotificationChannel(tenantId, userId, {
            onNotificationCreated: handler,
        });

        const notif = makeNotification({ id: 'n1', read_at: null });

        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: notif }),
        );
        await nextTick();

        expect(handler).toHaveBeenCalledTimes(1);
    });

    // NOTIF-V23: tenant switch leaves old channel
    it('NOTIF-V23: tenant switch stops listening on old channel', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        tenantId.value = 'tenant-2';
        await nextTick();

        expect(mockStopListening).toHaveBeenCalled();
    });

    // NOTIF-V24: tenant switch clears state
    it('NOTIF-V24: tenant switch clears notifications list', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        const { notifications } = useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        const notif = makeNotification({ id: 'n1' });
        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: notif }),
        );
        await nextTick();
        expect(notifications.value).toHaveLength(1);

        tenantId.value = 'tenant-2';
        await nextTick();
        expect(notifications.value).toHaveLength(0);
    });

    // NOTIF-V25: unmount leaves channel
    it('NOTIF-V25: unmount stops channel listener', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        expect(mockStopListening).not.toHaveBeenCalled();
    });

    // NOTIF-V26: no duplicate subscription
    it('NOTIF-V26: resubscribe cleans previous listeners', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);

        useNotificationChannel(tenantId, userId, {
            onNotificationCreated: vi.fn(),
        });

        tenantId.value = 'tenant-2';
        await nextTick();

        expect(mockStopListening).toHaveBeenCalled();
    });

    // NOTIF-V27: wrong-shape event ignored safely
    it('NOTIF-V27: malformed notification payload is ignored', async () => {
        const tenantId = ref('tenant-1');
        const userId = ref(1);
        const handler = vi.fn();

        const { notifications } = useNotificationChannel(tenantId, userId, {
            onNotificationCreated: handler,
        });

        mockListeners['.NotificationCreated']?.forEach((cb) =>
            cb({ notification: null }),
        );
        await nextTick();

        expect(notifications.value).toHaveLength(0);
        expect(handler).not.toHaveBeenCalled();
    });

    // NOTIF-V28: no polling
    it('NOTIF-V28: composable does not set up polling intervals', () => {
        const setIntervalSpy = vi.spyOn(global, 'setInterval');

        useNotificationChannel(ref('t1'), ref(1), {
            onNotificationCreated: vi.fn(),
        });

        expect(setIntervalSpy).not.toHaveBeenCalled();
        setIntervalSpy.mockRestore();
    });
});

describe('notificationUtils (additional)', () => {
    it('prependNotification maintains correct order with multiple', () => {
        let list: Notification[] = [];
        list = prependNotification(list, makeNotification({ id: 'n1' }));
        list = prependNotification(list, makeNotification({ id: 'n2' }));
        list = prependNotification(list, makeNotification({ id: 'n3' }));

        expect(list.map((n) => n.id)).toEqual(['n3', 'n2', 'n1']);
    });

    it('dedupeNotifications returns original array reference for duplicates', () => {
        const original = [makeNotification({ id: 'n1' })];
        const result = dedupeNotifications(original, makeNotification({ id: 'n1' }));

        expect(result).toBe(original);
    });
});
