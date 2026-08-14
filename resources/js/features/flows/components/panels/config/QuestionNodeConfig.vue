<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const fieldHint = 'Se guardará como {custom.field}';

const text = ref(typeof props.modelValue?.text === 'string' ? props.modelValue.text : '');
const prompt = ref(typeof props.modelValue?.prompt === 'string' ? props.modelValue.prompt : '');
const field = ref(typeof props.modelValue?.field === 'string' ? props.modelValue.field : '');

watch(
    () => props.modelValue,
    (value) => {
        text.value = typeof value?.text === 'string' ? value.text : '';
        prompt.value = typeof value?.prompt === 'string' ? value.prompt : '';
        field.value = typeof value?.field === 'string' ? value.field : '';
    },
);

function update(): void {
    emit('update:modelValue', { text: text.value, prompt: prompt.value, field: field.value });
}
</script>

<template>
    <div class="space-y-3">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Mensaje previo (opcional)</span>
            <textarea
                v-model="text"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Voy a necesitar algunos datos..."
                @input="update"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Pregunta a capturar</span>
            <textarea
                v-model="prompt"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="¿Cómo te llamás?"
                @input="update"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Nombre de la variable</span>
            <input
                v-model="field"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="nombre"
                @input="update"
            />
            <span class="mt-1 block text-[11px] text-zinc-400">{{ fieldHint }}</span>
        </label>
    </div>
</template>
