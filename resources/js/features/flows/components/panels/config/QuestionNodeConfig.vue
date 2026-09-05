<script setup lang="ts">
import { ref, watch } from 'vue';
import type { NodeConfigContext } from '../../../flowEditorTypes';
import type { VariableDefinition, VariableType } from '../../../flowTypes';
import VariablePicker from '../../VariablePicker.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null; context: NodeConfigContext }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const fieldHint = 'Se guardará como {{custom.field}}';
const fieldRules = 'Solo minúsculas, números y guión bajo (ej: nombre, dni_2).';
const promptHint = 'La pregunta soporta variables ({{contact.name}}, {{custom.nombre}}).';

const variableTypes: { value: VariableType; label: string }[] = [
    { value: 'string', label: 'Texto' },
    { value: 'integer', label: 'Entero' },
    { value: 'decimal', label: 'Decimal' },
    { value: 'boolean', label: 'Booleano' },
    { value: 'date', label: 'Fecha' },
    { value: 'datetime', label: 'Fecha y hora' },
];

const text = ref(typeof props.modelValue?.text === 'string' ? props.modelValue.text : '');
const prompt = ref(typeof props.modelValue?.prompt === 'string' ? props.modelValue.prompt : '');
const field = ref(typeof props.modelValue?.field === 'string' ? props.modelValue.field : '');
const type = ref<VariableType>(typeof props.modelValue?.type === 'string' ? (props.modelValue.type as VariableType) : 'string');
const defaultValue = ref(typeof props.modelValue?.default === 'string' ? props.modelValue.default : '');
const textarea = ref<HTMLTextAreaElement | null>(null);

watch(
    () => props.modelValue,
    (value) => {
        text.value = typeof value?.text === 'string' ? value.text : '';
        prompt.value = typeof value?.prompt === 'string' ? value.prompt : '';
        field.value = typeof value?.field === 'string' ? value.field : '';
        type.value = typeof value?.type === 'string' ? (value.type as VariableType) : 'string';
        defaultValue.value = typeof value?.default === 'string' ? value.default : '';
    },
);

function update(): void {
    emit('update:modelValue', {
        ...props.modelValue,
        text: text.value,
        prompt: prompt.value,
        field: field.value,
        type: type.value,
        default: defaultValue.value === '' ? null : defaultValue.value,
    });
}

function insertVariable(variable: VariableDefinition): void {
    const token = `{{${variable.key}}}`;
    const target = textarea.value;

    if (target) {
        const start = target.selectionStart ?? prompt.value.length;
        const end = target.selectionEnd ?? start;
        prompt.value = prompt.value.slice(0, start) + token + prompt.value.slice(end);
        update();

        requestAnimationFrame(() => {
            target.focus();
            const cursor = start + token.length;
            target.setSelectionRange(cursor, cursor);
        });
    } else {
        prompt.value = prompt.value + token;
        update();
    }
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
                ref="textarea"
                v-model="prompt"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="¿Cómo te llamás?"
                @input="update"
            />
            <div class="mt-1 flex items-start justify-between gap-2">
                <span class="block text-[11px] text-zinc-400">
                    {{ promptHint }}
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

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Nombre de la variable</span>
            <input
                v-model="field"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="nombre"
                @input="update"
            />
            <span class="mt-1 block text-[11px] text-zinc-400">{{ fieldHint }} · {{ fieldRules }}</span>
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Tipo de variable</span>
            <AppSelect
                v-model="type"
                class="w-full"
                :options="variableTypes"
                @change="update"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Valor por defecto (opcional)</span>
            <input
                v-model="defaultValue"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Se usa si el cliente no responde"
                @input="update"
            />
        </label>
    </div>
</template>
