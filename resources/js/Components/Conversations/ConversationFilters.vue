<script setup lang="ts">
import type {
    ConversationFilters,
    ConversationStatus,
    TenantMember,
} from '@/features/conversations/conversationUtils';

const filters = defineModel<ConversationFilters>({ default: () => ({ search: '', status: '', agent_id: '' }) });

const props = defineProps<{
    members: TenantMember[];
}>();

const emit = defineEmits<{
    apply: [];
    clear: [];
}>();

const statusOptions: Array<{ value: ConversationStatus | ''; label: string }> = [
    { value: '', label: 'Todos los estados' },
    { value: 'open', label: 'Abierta' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'resolved', label: 'Resuelta' },
    { value: 'archived', label: 'Archivada' },
];

function submit(): void {
    emit('apply');
}

function clear(): void {
    filters.value = { search: '', status: '', agent_id: '' };
    emit('clear');
}
</script>

<template>
    <form
        class="flex flex-col gap-2 border-b border-zinc-200 bg-white p-3"
        @submit.prevent="submit"
    >
        <div class="flex gap-2">
            <input
                v-model="filters.search"
                type="search"
                placeholder="Buscar por nombre o telefono"
                class="w-full rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-800"
            />
            <button
                type="button"
                class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                @click="clear"
            >
                Limpiar
            </button>
        </div>

        <div class="flex gap-2">
            <select
                v-model="filters.status"
                class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700"
            >
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>

            <select
                v-model="filters.agent_id"
                class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700"
            >
                <option value="">Todos los agentes</option>
                <option v-for="member in props.members" :key="member.id" :value="member.id">
                    {{ member.user.name }}
                </option>
            </select>
        </div>
    </form>
</template>
