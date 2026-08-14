<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { Message } from '@/features/messages/messageTypes';
import { groupMessagesByDay, isNearBottom } from '@/features/messages/messageUtils';
import MessageBubble from './MessageBubble.vue';

const props = defineProps<{
    messages: Message[];
    loadingOlder: boolean;
    hasOlder: boolean;
    hasNewMessages: boolean;
}>();

const emit = defineEmits<{
    loadOlder: [];
    reachTop: [];
    nearBottomChange: [nearBottom: boolean];
}>();

const container = ref<HTMLElement | null>(null);
const stickToBottom = ref(true);

const groups = computed(() => groupMessagesByDay(props.messages));

function scrollToBottom(smooth = false): void {
    const el = container.value;

    if (el === null) {
        return;
    }

    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    stickToBottom.value = true;
    emit('nearBottomChange', true);
}

function onScroll(): void {
    const el = container.value;

    if (el === null) {
        return;
    }

    stickToBottom.value = isNearBottom(el.scrollTop, el.scrollHeight, el.clientHeight);
    emit('nearBottomChange', stickToBottom.value);

    if (el.scrollTop <= 40 && props.hasOlder && !props.loadingOlder) {
        emit('reachTop');
    }
}

watch(
    () => props.messages.length,
    () => {
        nextTick(() => {
            if (stickToBottom.value) {
                scrollToBottom(false);
            }
        });
    },
);

onMounted(() => {
    nextTick(() => scrollToBottom(false));
});

defineExpose({ scrollToBottom });
</script>

<template>
    <div class="relative flex min-h-0 flex-1 flex-col">
        <div ref="container" class="min-h-0 flex-1 overflow-y-auto bg-zinc-50 px-4 py-4" @scroll="onScroll">
            <div v-if="props.hasOlder" class="mb-3 flex justify-center">
                <button
                    type="button"
                    class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs text-zinc-600 hover:bg-zinc-50 disabled:opacity-60"
                    :disabled="props.loadingOlder"
                    @click="$emit('loadOlder')"
                >
                    {{ props.loadingOlder ? 'Cargando...' : 'Mensajes anteriores' }}
                </button>
            </div>

            <div v-if="props.messages.length === 0 && !props.loadingOlder" class="py-10 text-center text-sm text-zinc-400">
                Sin mensajes todavia. Escribi para iniciar la conversacion.
            </div>

            <template v-for="group in groups" :key="group.key">
                <div class="mb-2 mt-3 flex justify-center">
                    <span class="rounded-full bg-white px-3 py-0.5 text-xs font-medium text-zinc-500 shadow-sm">
                        {{ group.label }}
                    </span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <MessageBubble v-for="message in group.items" :key="message.id" :message="message" />
                </div>
            </template>
        </div>

        <transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200"
            leave-to-class="opacity-0"
        >
            <button
                v-if="props.hasNewMessages"
                type="button"
                class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-3 py-1 text-xs font-medium text-white shadow-lg"
                @click="scrollToBottom(true)"
            >
                Nuevos mensajes
            </button>
        </transition>
    </div>
</template>
