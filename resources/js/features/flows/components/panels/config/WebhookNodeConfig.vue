<script setup lang="ts">
import { ref, watch } from 'vue';

const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const url = ref(typeof props.modelValue?.url === 'string' ? props.modelValue.url : '');
const method = ref(typeof props.modelValue?.method === 'string' ? props.modelValue.method.toUpperCase() : 'POST');

watch(
    () => props.modelValue,
    (value) => {
        url.value = typeof value?.url === 'string' ? value.url : '';
        method.value = typeof value?.method === 'string' ? value.method.toUpperCase() : 'POST';
    },
);

function update(): void {
    emit('update:modelValue', { url: url.value, method });
}
</script>

<template>
    <div class="space-y-3">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">URL del webhook</span>
            <input
                v-model="url"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="https://api.ejemplo.com/hook"
                @input="update"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Método HTTP</span>
            <select
                v-model="method"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                @change="update"
            >
                <option v-for="httpMethod in HTTP_METHODS" :key="httpMethod" :value="httpMethod">{{ httpMethod }}</option>
            </select>
        </label>

        <p class="text-[11px] text-zinc-400">
            Los headers y el body se gestionan del lado del servidor (nunca se exponen en el editor).
        </p>
    </div>
</template>
