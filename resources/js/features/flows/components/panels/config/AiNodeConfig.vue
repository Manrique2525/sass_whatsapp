<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { NodeConfigContext } from '../../../flowEditorTypes';
import type { VariableDefinition } from '../../../flowTypes';
import VariablePicker from '../../VariablePicker.vue';

const props = defineProps<{ modelValue: Record<string, unknown> | null; context: NodeConfigContext }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const MAX_TEXT_LENGTH = 4096;
const promptHint = 'Prompt enviado al modelo. Soporta variables: {{contact.*}}, {{business.*}}, {{conversation.id}}, {{custom.*}}.';
const systemHint = 'Instrucciones del sistema del negocio (ej: "Sos un asistente de ventas de ..."). Opcional.';
const outputHint = 'Nombre de la variable donde se guarda la respuesta generada.';
const fallbackHint = 'Texto estático usado si el servicio de IA falla. No resuelve variables.';

const outputPreview = computed(() => outputVariable.value !== '' ? `{{custom.${outputVariable.value}}}` : '');

const prompt = ref(typeof props.modelValue?.prompt === 'string' ? props.modelValue.prompt : '');
const systemPrompt = ref(typeof props.modelValue?.system_prompt === 'string' ? props.modelValue.system_prompt : '');
const outputVariable = ref(typeof props.modelValue?.output_variable === 'string' ? props.modelValue.output_variable : '');
const fallbackMessage = ref(typeof props.modelValue?.fallback_message === 'string' ? props.modelValue.fallback_message : '');
const textarea = ref<HTMLTextAreaElement | null>(null);
const systemTextarea = ref<HTMLTextAreaElement | null>(null);

watch(
    () => props.modelValue,
    (value) => {
        prompt.value = typeof value?.prompt === 'string' ? value.prompt : '';
        systemPrompt.value = typeof value?.system_prompt === 'string' ? value.system_prompt : '';
        outputVariable.value = typeof value?.output_variable === 'string' ? value.output_variable : '';
        fallbackMessage.value = typeof value?.fallback_message === 'string' ? value.fallback_message : '';
    },
);

function update(): void {
    emit('update:modelValue', {
        prompt: prompt.value,
        system_prompt: systemPrompt.value === '' ? '' : systemPrompt.value,
        output_variable: outputVariable.value,
        fallback_message: fallbackMessage.value === '' ? '' : fallbackMessage.value,
    });
}

function insertVariable(variable: VariableDefinition, targetField: 'prompt'): void {
    const token = `{{${variable.key}}}`;
    const target = targetField === 'prompt' ? textarea.value : systemTextarea.value;

    if (target) {
        const fieldRef = targetField === 'prompt' ? prompt : systemPrompt;
        const start = target.selectionStart ?? fieldRef.value.length;
        const end = target.selectionEnd ?? start;
        fieldRef.value = fieldRef.value.slice(0, start) + token + fieldRef.value.slice(end);
        update();

        requestAnimationFrame(() => {
            target.focus();
            const cursor = start + token.length;
            target.setSelectionRange(cursor, cursor);
        });
    }
}
</script>

<template>
    <div class="space-y-3">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Prompt</span>
            <textarea
                ref="textarea"
                v-model="prompt"
                rows="4"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Ej: Respondé al usuario de forma amigable sobre {{business.name}}..."
                :maxlength="MAX_TEXT_LENGTH"
                @input="update"
            />
            <div class="mt-1 flex items-start justify-between gap-2">
                <span class="block text-[11px] text-zinc-400">{{ promptHint }}</span>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="text-[11px] text-zinc-400">{{ prompt.length }}/{{ MAX_TEXT_LENGTH }}</span>
                    <VariablePicker class="shrink-0" :tenant-id="context.tenantId" :flow-id="context.flowId" :disabled="context.readOnly" @select="(v: VariableDefinition) => insertVariable(v, 'prompt')" />
                </div>
            </div>
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">System Prompt</span>
            <textarea
                ref="systemTextarea"
                v-model="systemPrompt"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Ej: Sos un asistente de atención al cliente de una tienda de ropa."
                :maxlength="MAX_TEXT_LENGTH"
                @input="update"
            />
            <div class="mt-1 flex items-start justify-between gap-2">
                <span class="block text-[11px] text-zinc-400">{{ systemHint }}</span>
                <span class="text-[11px] text-zinc-400">{{ systemPrompt.length }}/{{ MAX_TEXT_LENGTH }}</span>
            </div>
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Guardar respuesta en</span>
            <input
                v-model="outputVariable"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="respuesta_ia"
                @input="update"
            />
            <div class="mt-1 flex items-start justify-between gap-2">
                <span class="block text-[11px] text-zinc-400">{{ outputHint }}</span>
                <span v-if="outputVariable" class="text-[11px] text-zinc-400">Ej: {{ outputPreview }}</span>
            </div>
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Mensaje de respaldo</span>
            <textarea
                v-model="fallbackMessage"
                rows="2"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Disculpá, no pude procesar tu consulta. Un agente te contactará pronto."
                :maxlength="MAX_TEXT_LENGTH"
                @input="update"
            />
            <span class="mt-1 block text-[11px] text-zinc-400">{{ fallbackHint }}</span>
        </label>
    </div>
</template>
