<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const seconds = ref(typeof props.modelValue?.seconds === 'number' ? props.modelValue.seconds : 5);

watch(
    () => props.modelValue,
    (value) => {
        seconds.value = typeof value?.seconds === 'number' ? value.seconds : 5;
    },
);

function update(): void {
    emit('update:modelValue', { seconds: Number(seconds.value) });
}
</script>

<template>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Segundos de espera (1 a 3600)</span>
        <input
            v-model.number="seconds"
            type="number"
            min="1"
            max="3600"
            step="1"
            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            @change="update"
        />
    </label>
</template>
