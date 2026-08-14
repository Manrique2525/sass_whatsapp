<script setup lang="ts">
import { computed } from 'vue';
import {
    CONVERSATION_STATUS_META,
    formatLastInteraction,
    type Conversation,
} from '@/features/conversations/conversationUtils';

const props = defineProps<{
    conversation: Conversation;
}>();

const statusMeta = computed(() => CONVERSATION_STATUS_META[props.conversation.status]);

const contextItems = computed<Array<{ key: string; value: string }>>(() => {
    const context = props.conversation.context;

    if (context === null) {
        return [];
    }

    return Object.entries(context).map(([key, value]) => ({ key, value: String(value) }));
});
</script>

<template>
    <div class="flex h-full flex-col gap-4 overflow-y-auto border-l border-zinc-200 bg-white p-4">
        <div class="flex flex-col items-center gap-2 border-b border-zinc-100 pb-4">
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-2xl font-semibold text-emerald-800">
                {{ (conversation.contact?.name ?? '?').charAt(0).toUpperCase() }}
            </span>
            <p class="text-sm font-semibold text-zinc-900">
                {{ conversation.contact?.name ?? 'Sin nombre' }}
            </p>
            <p v-if="conversation.contact?.phone" class="text-xs text-zinc-500">
                {{ conversation.contact.phone }}
            </p>
            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="statusMeta.badge">
                {{ conversation.status_label }}
            </span>
        </div>

        <dl class="flex flex-col gap-3 text-sm">
            <div v-if="conversation.contact?.email">
                <dt class="text-xs uppercase text-zinc-400">Email</dt>
                <dd class="text-zinc-700">{{ conversation.contact.email }}</dd>
            </div>

            <div>
                <dt class="text-xs uppercase text-zinc-400">Agente</dt>
                <dd class="text-zinc-700">
                    {{ conversation.agent?.name ?? 'Sin asignar' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs uppercase text-zinc-400">Ultima interaccion</dt>
                <dd class="text-zinc-700">{{ formatLastInteraction(conversation) }}</dd>
            </div>

            <div v-if="conversation.flow_execution_id !== null">
                <dt class="text-xs uppercase text-zinc-400">Ejecucion de flujo</dt>
                <dd class="break-all font-mono text-xs text-zinc-700">{{ conversation.flow_execution_id }}</dd>
            </div>
        </dl>

        <div v-if="contextItems.length > 0" class="flex flex-col gap-2 border-t border-zinc-100 pt-4">
            <h3 class="text-xs font-semibold uppercase text-zinc-400">Contexto</h3>
            <dl class="flex flex-col gap-1.5 text-sm">
                <div v-for="item in contextItems" :key="item.key">
                    <dt class="text-xs uppercase text-zinc-400">{{ item.key }}</dt>
                    <dd class="break-words text-zinc-700">{{ item.value }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
