<script setup lang="ts">
import { ref } from 'vue';
import type { FlowEditorController } from '../useFlowEditor';
import type { FlowNodeType } from '../flowTypes';
import { nodeTypeLabel } from '../flowUtils';

const props = defineProps<{ editor: FlowEditorController }>();

const expanded = ref(false);

const NODE_TYPES: FlowNodeType[] = [
    'message',
    'buttons',
    'question',
    'condition',
    'delay',
    'tag',
    'webhook',
    'human',
    'end',
    'ai',
];

const accent: Record<FlowNodeType, string> = {
    message: 'bg-sky-600',
    buttons: 'bg-violet-600',
    question: 'bg-amber-500',
    condition: 'bg-rose-600',
    delay: 'bg-teal-600',
    tag: 'bg-emerald-600',
    webhook: 'bg-indigo-600',
    ai: 'bg-zinc-500',
    human: 'bg-purple-600',
    end: 'bg-slate-700',
};

const descriptions: Partial<Record<FlowNodeType, string>> = {
    message: 'Envía un mensaje de texto',
    buttons: 'Mensaje con botones de respuesta',
    question: 'Captura una variable del contacto',
    condition: 'Ramifica según reglas',
    delay: 'Espera un tiempo',
    tag: 'Aplica etiquetas al contacto',
    webhook: 'Llama a un endpoint HTTP',
    human: 'Transfiere a un agente humano',
    end: 'Finaliza el flujo',
    ai: 'Genera contenido con IA y lo guarda en una variable',
};

function add(type: FlowNodeType): void {
    props.editor.addNode(type, { x: 120 + Math.random() * 160, y: 80 + Math.random() * 120 });
    expanded.value = false;
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-800 shadow-sm hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="editor.readOnly.value"
            @click="expanded = !expanded"
        >
            + Agregar nodo
        </button>

        <div
            v-if="expanded && !editor.readOnly.value"
            class="absolute left-0 top-11 z-30 w-72 space-y-1 rounded-lg border border-zinc-200 bg-white p-2 shadow-xl"
        >
            <div v-for="type in NODE_TYPES" :key="type">
                <button
                    type="button"
                    class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left hover:bg-zinc-50"
                    @click="add(type)"
                >
                    <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" :class="accent[type]" />
                    <span class="flex-1">
                        <span class="block text-xs font-semibold text-zinc-800">
                            {{ nodeTypeLabel(type) }}
                        </span>
                        <span class="block text-[11px] text-zinc-500">{{ descriptions[type] }}</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
