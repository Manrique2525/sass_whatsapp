<script setup lang="ts">
import { computed } from 'vue';
import {
    CONVERSATION_STATUS_META,
    type Conversation,
} from '@/features/conversations/conversationUtils';
import { formatMessageTimestamp, isOutbound, messagePreview } from '@/features/messages/messageUtils';

const props = defineProps<{
    conversation: Conversation;
    active: boolean;
}>();

defineEmits<{
    select: [];
}>();

const initials = computed<string>(() => {
    const name = props.conversation.contact?.name ?? '?';

    return name.charAt(0).toUpperCase();
});

const preview = computed<string>(() => {
    const last = props.conversation.last_message;
    const text = messagePreview(last);

    if (last !== null && isOutbound(last) && text !== '') {
        return `Tu: ${text}`;
    }

    return text;
});

const time = computed<string>(() => {
    const last = props.conversation.last_message;

    return last !== null ? formatMessageTimestamp(last.created_at) : '—';
});

const statusMeta = computed(() => CONVERSATION_STATUS_META[props.conversation.status]);

const botPaused = computed(() => props.conversation.bot_paused);
</script>

<template>
    <button
        type="button"
        class="flex w-full items-start gap-3 border-b border-zinc-100 px-4 py-3 text-left transition-colors"
        :class="active ? 'bg-emerald-50/60' : 'hover:bg-zinc-50'"
        @click="$emit('select')"
    >
        <span
            class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-800"
        >
            {{ initials }}
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-baseline justify-between gap-2">
                <span class="truncate text-sm font-semibold text-zinc-900">
                    {{ conversation.contact?.name ?? conversation.contact?.phone ?? 'Sin nombre' }}
                </span>
                <span class="shrink-0 text-xs text-zinc-400">{{ time }}</span>
            </span>

            <span class="mt-0.5 flex items-center justify-between gap-2">
                <span class="truncate text-xs text-zinc-500">
                    {{ preview || 'Sin mensajes' }}
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <span
                        v-if="botPaused"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                    >
                        Bot pausado
                    </span>
                    <span
                        class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                        :class="statusMeta.badge"
                    >
                        {{ conversation.status_label }}
                    </span>
                </span>
            </span>
        </span>
    </button>
</template>
