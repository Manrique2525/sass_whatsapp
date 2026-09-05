<script setup lang="ts">
import { computed } from 'vue';
import {
    CONVERSATION_STATUS_META,
    isHumanActive,
    isUnassignedHandoff,
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

const unassignedHandoff = computed(() => isUnassignedHandoff(props.conversation));
const humanActive = computed(() => isHumanActive(props.conversation));
</script>

<template>
    <button
        type="button"
        class="flex w-full items-start gap-3 border-b border-[#edf2ec] px-4 py-3.5 text-left transition-colors"
        :class="[
            active ? 'bg-[#eef8ed]' : 'hover:bg-[#f0f5ef]',
            unassignedHandoff ? 'border-l-2 border-l-amber-400' : '',
        ]"
        @click="$emit('select')"
    >
        <span
            class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-[#dff4d7] text-sm font-semibold text-[#176b42]"
        >
            {{ initials }}
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-baseline justify-between gap-2">
                <span class="truncate text-sm font-semibold text-[#10261f]">
                    {{ conversation.contact?.name ?? conversation.contact?.phone ?? 'Sin nombre' }}
                </span>
                <span class="shrink-0 text-xs text-[#8a9b91]">{{ time }}</span>
            </span>

            <span class="mt-0.5 flex items-center justify-between gap-2">
                <span class="truncate text-xs text-[#71877b]">
                    {{ preview || 'Sin mensajes' }}
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <span
                        v-if="unassignedHandoff"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700"
                    >
                        Requiere agente
                    </span>
                    <span
                        v-else-if="humanActive"
                        class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700"
                    >
                        {{ conversation.agent?.name ?? 'Humano' }}
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
