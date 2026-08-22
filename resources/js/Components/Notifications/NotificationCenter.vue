<script setup lang="ts">
import { computed } from 'vue';
import type { Notification } from '@/features/notifications/notificationTypes';
import { notificationTypeLabel, isUnread } from '@/features/notifications/notificationUtils';

const props = defineProps<{
    notifications: Notification[];
    isLoading: boolean;
    error: string | null;
    hasMore: boolean;
    isMarkingAll: boolean;
}>();

const emit = defineEmits<{
    markRead: [notification: Notification];
    markAllRead: [];
    loadMore: [];
    close: [];
}>();

const hasUnread = computed(() => props.notifications.some((n) => isUnread(n)));
const isEmpty = computed(() => !props.isLoading && props.notifications.length === 0);

function formatRelativeTime(dateStr: string): string {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);

    if (diffMin < 1) return 'Ahora';
    if (diffMin < 60) return `${diffMin}m`;

    const diffHrs = Math.floor(diffMin / 60);
    if (diffHrs < 24) return `${diffHrs}h`;

    const diffDays = Math.floor(diffHrs / 24);
    if (diffDays < 7) return `${diffDays}d`;

    return date.toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
}

function handleClick(notification: Notification): void {
    if (isUnread(notification)) {
        emit('markRead', notification);
    }
}
</script>

<template>
    <div
        class="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg sm:w-96"
        role="dialog"
        aria-label="Centro de notificaciones"
    >
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3">
            <h3 class="text-sm font-semibold text-zinc-900">Notificaciones</h3>
            <div class="flex items-center gap-2">
                <button
                    v-if="hasUnread"
                    type="button"
                    :disabled="isMarkingAll"
                    class="text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                    @click="emit('markAllRead')"
                >
                    {{ isMarkingAll ? 'Marcando...' : 'Marcar todas como leídas' }}
                </button>
                <button
                    type="button"
                    class="text-zinc-400 hover:text-zinc-600"
                    aria-label="Cerrar"
                    @click="emit('close')"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="max-h-80 overflow-y-auto">
            <div v-if="isLoading && notifications.length === 0" class="px-4 py-6 text-center">
                <div class="mx-auto h-5 w-5 animate-spin rounded-full border-2 border-zinc-300 border-t-indigo-600" />
                <p class="mt-2 text-xs text-zinc-500">Cargando notificaciones...</p>
            </div>

            <div v-else-if="error" class="px-4 py-6 text-center">
                <p class="text-sm text-red-600">{{ error }}</p>
                <button
                    type="button"
                    class="mt-2 text-xs text-indigo-600 hover:text-indigo-800"
                    @click="emit('loadMore')"
                >
                    Reintentar
                </button>
            </div>

            <div v-else-if="isEmpty" class="px-4 py-8 text-center">
                <p class="text-sm text-zinc-500">No tienes notificaciones.</p>
            </div>

            <template v-else>
                <div
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="flex cursor-pointer gap-3 border-b border-zinc-100 px-4 py-3 transition-colors hover:bg-zinc-50"
                    :class="{ 'bg-indigo-50': isUnread(notification) }"
                    role="button"
                    :tabindex="0"
                    @click="handleClick(notification)"
                    @keydown.enter="handleClick(notification)"
                >
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span
                                v-if="isUnread(notification)"
                                class="inline-block h-2 w-2 flex-shrink-0 rounded-full bg-indigo-600"
                            />
                            <p class="text-xs font-medium text-zinc-500">
                                {{ notificationTypeLabel(notification.type) }}
                            </p>
                            <span class="text-xs text-zinc-400">{{ formatRelativeTime(notification.created_at) }}</span>
                        </div>
                        <p class="mt-0.5 text-sm font-medium text-zinc-900">{{ notification.title }}</p>
                        <p class="mt-0.5 text-xs text-zinc-600 line-clamp-2">{{ notification.body }}</p>
                    </div>
                </div>
            </template>
        </div>

        <div v-if="hasMore && !isLoading" class="border-t border-zinc-200 px-4 py-2 text-center">
            <button
                type="button"
                class="text-xs text-indigo-600 hover:text-indigo-800"
                @click="emit('loadMore')"
            >
                Cargar más
            </button>
        </div>

        <div v-if="isLoading && notifications.length > 0" class="border-t border-zinc-200 px-4 py-2 text-center">
            <div class="mx-auto h-4 w-4 animate-spin rounded-full border-2 border-zinc-300 border-t-indigo-600" />
        </div>
    </div>
</template>
