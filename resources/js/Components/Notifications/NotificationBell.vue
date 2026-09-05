<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { fetchUnreadCount, fetchNotifications, markAllNotificationsRead, markNotificationRead } from '@/features/notifications/notificationApi';
import { useNotificationChannel } from '@/features/notifications/useNotificationChannel';
import { formatUnreadBadge, shouldShowBadge, markNotificationInList, markAllInListAsRead } from '@/features/notifications/notificationUtils';
import type { Notification } from '@/features/notifications/notificationTypes';
import NotificationCenter from '@/Components/Notifications/NotificationCenter.vue';

const page = usePage();
const tenantId = computed(() => page.props.auth.current_tenant_id as string | null);
const userId = computed(() => page.props.auth.user?.id ?? null);

const isOpen = ref(false);
const notifications = ref<Notification[]>([]);
const unreadCount = ref(0);
const isLoading = ref(false);
const hasLoaded = ref(false);
const loadError = ref<string | null>(null);
const currentPage = ref(1);
const hasMore = ref(true);
const isMarkingAll = ref(false);

const badgeText = computed(() => formatUnreadBadge(unreadCount.value));
const showBadge = computed(() => shouldShowBadge(unreadCount.value));

useNotificationChannel(
    tenantId,
    userId,
    {
        onNotificationCreated: (notification: Notification) => {
            notifications.value = [notification, ...notifications.value];
            if (notification.read_at === null) {
                unreadCount.value += 1;
            }
        },
    },
);

async function loadNotifications(): Promise<void> {
    if (tenantId.value === null) return;

    isLoading.value = true;
    loadError.value = null;

    try {
        const response = await fetchUnreadCount(tenantId.value);
        unreadCount.value = response.unread_count;
    } catch {
        loadError.value = 'No pudimos cargar las notificaciones.';
    } finally {
        isLoading.value = false;
    }
}

async function openCenter(): Promise<void> {
    if (isOpen.value) {
        isOpen.value = false;
        return;
    }

    isOpen.value = true;

    if (!hasLoaded.value) {
        await loadNotificationList();
    }
}

async function loadNotificationList(): Promise<void> {
    if (tenantId.value === null) return;

    isLoading.value = true;
    loadError.value = null;

    try {
        const response = await fetchNotifications(tenantId.value, { read_status: 'all', per_page: 20 });
        notifications.value = response.notifications;
        unreadCount.value = response.counts.unread;
        currentPage.value = response.meta.current_page;
        hasMore.value = response.meta.current_page < response.meta.last_page;
        hasLoaded.value = true;
    } catch {
        loadError.value = 'No pudimos cargar las notificaciones.';
    } finally {
        isLoading.value = false;
    }
}

async function loadMore(): Promise<void> {
    if (tenantId.value === null || !hasMore.value || isLoading.value) return;

    isLoading.value = true;

    try {
        const response = await fetchNotifications(tenantId.value, { read_status: 'all', per_page: 20 });
        const existing = new Set(notifications.value.map((n) => n.id));
        const newNotifications = response.notifications.filter((n) => !existing.has(n.id));
        notifications.value = [...notifications.value, ...newNotifications];
        currentPage.value = response.meta.current_page;
        hasMore.value = response.meta.current_page < response.meta.last_page;
    } catch {
        loadError.value = 'No pudimos cargar más notificaciones.';
    } finally {
        isLoading.value = false;
    }
}

async function handleMarkRead(notification: Notification): Promise<void> {
    if (tenantId.value === null || notification.read_at !== null) return;

    try {
        await markNotificationRead(tenantId.value, notification.id);
        notifications.value = markNotificationInList(notifications.value, notification.id);
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    } catch {
        // Fail silently — state unchanged
    }
}

async function handleMarkAllRead(): Promise<void> {
    if (tenantId.value === null || isMarkingAll.value) return;

    isMarkingAll.value = true;

    try {
        const response = await markAllNotificationsRead(tenantId.value);
        notifications.value = markAllInListAsRead(notifications.value);
        unreadCount.value = response.counts.unread;
    } catch {
        // Fail silently — state unchanged
    } finally {
        isMarkingAll.value = false;
    }
}

function closeCenter(): void {
    isOpen.value = false;
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isOpen.value) {
        closeCenter();
    }
}

onMounted(() => {
    loadNotifications();
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
});

watch(tenantId, () => {
    notifications.value = [];
    unreadCount.value = 0;
    hasLoaded.value = false;
    isOpen.value = false;
    currentPage.value = 1;
    hasMore.value = true;
    loadNotifications();
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="app-button app-button--secondary relative p-2 text-[#71877b] hover:text-[#10261f]"
            aria-label="Notificaciones"
            @click="openCenter"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" />
            </svg>
            <span
                v-if="showBadge"
                class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#b42318] px-1 text-[10px] font-bold text-white"
            >
                {{ badgeText }}
            </span>
        </button>

        <NotificationCenter
            v-if="isOpen"
            :notifications="notifications"
            :is-loading="isLoading"
            :error="loadError"
            :has-more="hasMore"
            :is-marking-all="isMarkingAll"
            @mark-read="handleMarkRead"
            @mark-all-read="handleMarkAllRead"
            @load-more="loadMore"
            @close="closeCenter"
        />
    </div>
</template>
