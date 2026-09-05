<script setup lang="ts">
import type {
    ConversationFilters,
    ConversationStatus,
    TenantMember,
} from '@/features/conversations/conversationUtils';
import AppSelect from '@/Components/AppSelect.vue';

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
        class="flex flex-col gap-2 border-b border-[#edf2ec] bg-white p-3"
        @submit.prevent="submit"
    >
        <div class="flex gap-2">
            <input
                v-model="filters.search"
                type="search"
                placeholder="Buscar por nombre o telefono"
                class="app-field px-3 py-1.5"
            />
            <button
                type="button"
                class="app-button app-button--secondary px-3 py-1.5"
                @click="clear"
            >
                Limpiar
            </button>
        </div>

        <div class="flex gap-2">
            <AppSelect
                v-model="filters.status"
                class="flex-1"
                :options="statusOptions"
            />

            <AppSelect
                v-model="filters.agent_id"
                class="flex-1"
                :options="[
                    { value: '', label: 'Todos los agentes' },
                    ...props.members.map((member) => ({ value: member.user.id, label: member.user.name })),
                ]"
                searchable
            />
        </div>
    </form>
</template>
