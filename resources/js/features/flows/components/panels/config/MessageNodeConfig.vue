<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const placeholder = 'Hola {contact.name}, ¿cómo estás?';
const variableHint = 'Podés usar variables: {contact.*}, {business.*}, {custom.*}';

const text = ref(typeof props.modelValue?.text === 'string' ? props.modelValue.text : '');

watch(
    () => props.modelValue,
    (value) => {
        text.value = typeof value?.text === 'string' ? value.text : '';
    },
);

function update(): void {
    emit('update:modelValue', { text: text.value });
}
</script>

<template>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Texto del mensaje</span>
        <textarea
            v-model="text"
            rows="3"
            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            :placeholder="placeholder"
            @input="update"
        />
        <span class="mt-1 block text-[11px] text-zinc-400">
            {{ variableHint }}
        </span>
    </label>
</template>
