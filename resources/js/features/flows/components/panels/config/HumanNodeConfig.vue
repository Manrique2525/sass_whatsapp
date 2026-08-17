<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const handoffMessage = ref(typeof props.modelValue?.handoff_message === 'string' ? props.modelValue.handoff_message : '');

watch(
    () => props.modelValue,
    (value) => {
        handoffMessage.value = typeof value?.handoff_message === 'string' ? value.handoff_message : '';
    },
);

function update(): void {
    emit('update:modelValue', { handoff_message: handoffMessage.value });
}
</script>

<template>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Mensaje de traspaso (opcional)</span>
        <textarea
            v-model="handoffMessage"
            rows="3"
            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            placeholder="Te paso con un agente humano..."
            @input="update"
        />
        <span class="mt-1 block text-[11px] text-zinc-400">
            Máximo 4096 caracteres. El workflow operativo se implementará en FASE 15 U3.
        </span>
    </label>
</template>
