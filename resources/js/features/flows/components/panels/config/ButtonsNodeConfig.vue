<script setup lang="ts">
import { ref, watch } from 'vue';

interface ButtonDraft {
    id: string;
    title: string;
}

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const text = ref(typeof props.modelValue?.text === 'string' ? props.modelValue.text : '');
const buttons = ref<ButtonDraft[]>(
    Array.isArray(props.modelValue?.buttons)
        ? (props.modelValue.buttons as unknown[]).map((button) => {
              const b = typeof button === 'object' && button !== null ? (button as Record<string, unknown>) : {};

              return { id: String(b.id ?? ''), title: String(b.title ?? '') };
          })
        : [{ id: 'opcion_1', title: '' }],
);

watch(
    () => props.modelValue,
    (value) => {
        text.value = typeof value?.text === 'string' ? value.text : '';
        buttons.value = Array.isArray(value?.buttons)
            ? (value.buttons as unknown[]).map((button) => {
                  const b = typeof button === 'object' && button !== null ? (button as Record<string, unknown>) : {};

                  return { id: String(b.id ?? ''), title: String(b.title ?? '') };
              })
            : [{ id: 'opcion_1', title: '' }];
    },
);

function update(): void {
    emit('update:modelValue', { text: text.value, buttons: buttons.value.map((button) => ({ ...button })) });
}

function addButton(): void {
    if (buttons.value.length >= 3) {
        return;
    }
    buttons.value = [...buttons.value, { id: `opcion_${buttons.value.length + 1}`, title: '' }];
    update();
}

function removeButton(index: number): void {
    buttons.value = buttons.value.filter((_, i) => i !== index);
    update();
}
</script>

<template>
    <div class="space-y-3">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Texto del mensaje</span>
            <textarea
                v-model="text"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Elegí una opción..."
                @input="update"
            />
        </label>

        <div>
            <span class="mb-1 block text-xs font-medium text-zinc-600">Botones (1 a 3)</span>
            <div class="space-y-2">
                <div v-for="(button, index) in buttons" :key="index" class="flex gap-2">
                    <input
                        v-model="button.id"
                        type="text"
                        class="w-1/3 rounded-md border border-zinc-300 px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none"
                        placeholder="id"
                        @input="update"
                    />
                    <input
                        v-model="button.title"
                        type="text"
                        class="w-2/3 rounded-md border border-zinc-300 px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none"
                        placeholder="Texto visible"
                        @input="update"
                    />
                    <button
                        type="button"
                        class="shrink-0 rounded-md px-2 text-xs text-red-500 hover:bg-red-50 disabled:opacity-30"
                        :disabled="buttons.length <= 1"
                        @click="removeButton(index)"
                    >
                        ✕
                    </button>
                </div>
            </div>
            <button
                type="button"
                class="mt-2 rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 disabled:opacity-40"
                :disabled="buttons.length >= 3"
                @click="addButton"
            >
                + Agregar botón
            </button>
        </div>
    </div>
</template>
