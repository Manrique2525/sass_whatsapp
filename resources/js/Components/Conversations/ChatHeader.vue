<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    canClose,
    canReopen,
    CONVERSATION_STATUS_META,
    type Conversation,
    type TenantMember,
} from '@/features/conversations/conversationUtils';

const props = defineProps<{
    conversation: Conversation;
    members: TenantMember[];
    canManage: boolean;
    canAssign: boolean;
    acting: boolean;
}>();

const emit = defineEmits<{
    assign: [agentId: number];
    action: [action: 'close' | 'reopen' | 'pause_bot' | 'resume_bot'];
    back: [];
}>();

const statusMeta = computed(() => CONVERSATION_STATUS_META[props.conversation.status]);

const selectedAgent = ref<string>(props.conversation.agent?.id !== undefined ? String(props.conversation.agent.id) : '');

watch(
    () => [props.conversation.id, props.conversation.agent?.id],
    () => {
        selectedAgent.value = props.conversation.agent?.id !== undefined ? String(props.conversation.agent.id) : '';
    },
);

function onAssignChange(): void {
    const value = Number(selectedAgent.value);

    if (Number.isNaN(value) || value === 0) {
        return;
    }

    emit('assign', value);
}

const closeAction = computed<'close' | 'reopen' | null>(() => {
    if (canClose(props.conversation.status)) {
        return 'close';
    }

    if (canReopen(props.conversation.status)) {
        return 'reopen';
    }

    return null;
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-white px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <button
                type="button"
                class="rounded-full bg-zinc-100 px-2 py-1 text-xs text-zinc-600 lg:hidden"
                @click="$emit('back')"
            >
                Volver
            </button>

            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-800">
                {{ (conversation.contact?.name ?? '?').charAt(0).toUpperCase() }}
            </span>

            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-zinc-900">
                    {{ conversation.contact?.name ?? conversation.contact?.phone ?? 'Sin nombre' }}
                </p>
                <p v-if="conversation.contact?.phone" class="truncate text-xs text-zinc-500">
                    {{ conversation.contact.phone }}
                </p>
            </div>

            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium" :class="statusMeta.badge">
                {{ conversation.status_label }}
            </span>
            <span
                v-if="conversation.bot_paused"
                class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700"
            >
                Bot pausado
            </span>
        </div>

        <div class="flex items-center gap-2">
            <select
                v-if="props.canAssign"
                class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-700"
                :disabled="props.acting"
                v-model="selectedAgent"
                @change="onAssignChange"
            >
                <option value="" disabled>Asignar a...</option>
                <option v-for="member in props.members" :key="member.id" :value="member.id">
                    {{ member.user.name }}
                </option>
            </select>

            <button
                v-if="props.canManage && closeAction !== null"
                type="button"
                class="rounded-md border border-zinc-300 px-3 py-1 text-xs text-zinc-600 hover:bg-zinc-50 disabled:opacity-50"
                :disabled="props.acting"
                @click="$emit('action', closeAction)"
            >
                {{ closeAction === 'close' ? 'Cerrar' : 'Reabrir' }}
            </button>

            <button
                v-if="props.canManage"
                type="button"
                class="rounded-md border px-3 py-1 text-xs hover:bg-zinc-50 disabled:opacity-50"
                :class="conversation.bot_paused ? 'border-emerald-300 text-emerald-700' : 'border-amber-300 text-amber-700'"
                :disabled="props.acting"
                @click="$emit('action', conversation.bot_paused ? 'resume_bot' : 'pause_bot')"
            >
                {{ conversation.bot_paused ? 'Reanudar bot' : 'Pausar bot' }}
            </button>
        </div>
    </div>
</template>
