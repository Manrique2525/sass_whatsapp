<script setup lang="ts">
import { computed } from 'vue';
import type { Message } from '@/features/messages/messageTypes';
import { formatMessageTimestamp, isOutbound, messageStatusLabel } from '@/features/messages/messageUtils';

const props = defineProps<{
    message: Message;
}>();

const isOwn = computed(() => isOutbound(props.message));

const time = computed(() => formatMessageTimestamp(props.message.created_at));

const failed = computed(() => props.message.status === 'failed');

const delivered = computed(() => props.message.status === 'delivered');

const read = computed(() => props.message.status === 'read');
</script>

<template>
    <div class="flex" :class="isOwn ? 'justify-end' : 'justify-start'">
        <div
            class="max-w-[78%] rounded-2xl px-3 py-2 text-sm shadow-sm"
            :class="
                isOwn
                    ? 'rounded-br-md bg-emerald-600 text-white'
                    : 'rounded-bl-md bg-white text-zinc-800 border border-zinc-200'
            "
        >
            <p v-if="message.body !== null" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
            <p v-else class="italic opacity-80">Mensaje multimedia</p>

            <div class="mt-1 flex items-center justify-end gap-1.5">
                <span class="text-[10px]" :class="isOwn ? 'text-emerald-100' : 'text-zinc-400'">{{ time }}</span>

                <span v-if="isOwn && !failed" class="flex items-center" title="Entregado">
                    <svg
                        v-if="delivered || read"
                        class="h-3.5 w-3.5"
                        :class="read ? 'text-sky-300' : 'text-emerald-200'"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path
                            d="M18.9 7.2 10 16.1 5.1 11.2 6.5 9.8 10 13.3 17.5 5.8z"
                        />
                        <path d="M20.5 9.4 19.1 8 18.9 8.2 20.3 9.6z" />
                    </svg>
                    <svg
                        v-else
                        class="h-3.5 w-3.5 text-emerald-200"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path d="M18.9 7.2 10 16.1 5.1 11.2 6.5 9.8 10 13.3 17.5 5.8z" />
                    </svg>
                </span>

                <span
                    v-if="isOwn && failed"
                    class="rounded bg-red-100 px-1 text-[10px] font-medium text-red-700"
                >
                    {{ messageStatusLabel('failed') }}
                </span>
            </div>
        </div>
    </div>
</template>
