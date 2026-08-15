<script setup lang="ts">
import { ref, watch } from 'vue';
import type { NodeConfigContext } from '../../../flowEditorTypes';
import type { VariableDefinition } from '../../../flowTypes';
import VariablePicker from '../../VariablePicker.vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null; context: NodeConfigContext }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const placeholder = 'Hola {{contact.name}}, ¿cómo estás?';
const variableHint = 'Las variables se insertan como {{contact.*}}, {{business.*}}, {{custom.*}} y se resuelven al ejecutar el flujo.';

const text = ref(typeof props.modelValue?.text === 'string' ? props.modelValue.text : '');
const textarea = ref<HTMLTextAreaElement | null>(null);

watch(
    () => props.modelValue,
    (value) => {
        text.value = typeof value?.text === 'string' ? value.text : '';
    },
);

function update(): void {
    emit('update:modelValue', { text: text.value });
}

function insertVariable(variable: VariableDefinition): void {
    const token = `{{${variable.key}}}`;
    const target = textarea.value;

    if (target) {
        const start = target.selectionStart ?? text.value.length;
        const end = target.selectionEnd ?? start;
        text.value = text.value.slice(0, start) + token + text.value.slice(end);
        update();

        requestAnimationFrame(() => {
            target.focus();
            const cursor = start + token.length;
            target.setSelectionRange(cursor, cursor);
        });
    } else {
        text.value = text.value + token;
        update();
    }
}
</script>

<template>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Texto del mensaje</span>
        <textarea
            ref="textarea"
            v-model="text"
            rows="3"
            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            :placeholder="placeholder"
            @input="update"
        />
        <div class="mt-1 flex items-start justify-between gap-2">
            <span class="block text-[11px] text-zinc-400">
                {{ variableHint }}
            </span>
            <VariablePicker
                class="shrink-0"
                :tenant-id="context.tenantId"
                :flow-id="context.flowId"
                :disabled="context.readOnly"
                @select="insertVariable"
            />
        </div>
    </label>
</template>
