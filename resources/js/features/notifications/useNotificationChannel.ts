import type { Channel } from 'laravel-echo';
import { onBeforeUnmount, ref, watch, type WatchSource } from 'vue';
import { initEcho } from '@/features/realtime/echo';
import type { Notification } from './notificationTypes';
import { prependNotification } from './notificationUtils';

export interface NotificationChannelHandlers {
    onNotificationCreated: (notification: Notification) => void;
}

/**
 * Suscribe al canal privado personal (`private-tenant.{tenantId}.users.{userId}.notifications`).
 *
 * Escucha `NotificationCreated` para recibir notificaciones en tiempo real
 * sin polling (FASE 22 U5).
 *
 * - Deduplica por id.
 * - Cleanup: abandona el canal al desmontar o cambiar de tenant.
 * - Maneja reconnect sin duplicar listeners.
 */
export function useNotificationChannel(
    tenantId: WatchSource<string | null>,
    userId: WatchSource<number | null>,
    handlers: NotificationChannelHandlers,
): { notifications: ReturnType<typeof ref<Notification[]>> } {
    let channel: Channel | null = null;
    const seenIds = ref<Set<string>>(new Set());
    const MAX_SEEN = 500;
    const notifications = ref<Notification[]>([]);

    const unsubscribe = (): void => {
        if (channel === null) {
            return;
        }

        channel.stopListening('.NotificationCreated');
        channel = null;
    };

    const subscribe = (tenant: string | null, user: number | null): void => {
        unsubscribe();

        if (tenant === null || user === null) {
            return;
        }

        const instance = initEcho();

        if (instance === null) {
            return;
        }

        const next = instance.private(`tenant.${tenant}.users.${user}.notifications`);
        channel = next;

        next.listen('.NotificationCreated', (payload: { notification: Notification }) => {
            const notif = payload.notification;

            if (notif === undefined || notif === null || typeof notif !== 'object') {
                return;
            }

            if (typeof notif.id !== 'string' || notif.id === '') {
                return;
            }

            if (seenIds.value.has(notif.id)) {
                return;
            }

            seenIds.value.add(notif.id);

            if (seenIds.value.size > MAX_SEEN) {
                const ids = Array.from(seenIds.value);
                seenIds.value = new Set(ids.slice(-MAX_SEEN));
            }

            notifications.value = prependNotification(notifications.value, notif);

            handlers.onNotificationCreated(notif);
        });
    };

    watch(
        [tenantId, userId],
        ([tenant, user]) => {
            seenIds.value = new Set();
            notifications.value = [];
            subscribe(tenant, user);
        },
        { immediate: true },
    );

    onBeforeUnmount(unsubscribe);

    return { notifications };
}
