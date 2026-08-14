<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const tags = ref<string[]>(
    Array.isArray(props.modelValue?.tags) ? (props.modelValue.tags as unknown[]).map(String) : [''],
);

watch(
    () => props.modelValue,
    (value) => {
        tags.value = Array.isArray(value?.tags) ? (value.tags as unknown[]).map(String) : [''];
    },
);

function update(): void {
    emit('update:modelValue', { tags: tags.value.map((tag) => tag.trim()).filter((tag) => tag !== '') });
}

function addTag(): void {
    if (tags.value.length >= 10) {
        return;
    }
    tags.value = [...tags.value, ''];
    update();
}

function removeTag(index: number): void {
    tags.value = tags.value.filter((_, i) => i !== index);
    update();
}
</script>

<template>
    <div class="space-y-2">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Etiquetas a aplicar (1 a 10)</span>

        <div v-for="(_, index) in tags" :key="index" class="flex gap-2">
            <input
                v-model="tags[index]"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none"
                placeholder="Nombre de la etiqueta"
                @input="update"
            />
            <button
                type="button"
                class="shrink-0 rounded-md px-2 text-xs text-red-500 hover:bg-red-50 disabled:opacity-30"
                :disabled="tags.length <= 1"
                @click="removeTag(index)"
            >
                ✕
            </button>
        </div>

        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 disabled:opacity-40"
            :disabled="tags.length >= 10"
            @click="addTag"
        >
            + Agregar etiqueta
        </button>
    </div>
</template>
