<script setup lang="ts">
import type { FlowEditorController } from '../../useFlowEditor';

defineProps<{ editor: FlowEditorController }>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-zinc-900">Propiedades del flujo</h3>
            <p class="mt-0.5 text-xs text-zinc-500">Nombre y descripción del flujo.</p>
        </div>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Nombre</span>
            <input
                :value="editor.flowName.value"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                :disabled="editor.readOnly.value"
                @input="(event) => editor.updateFlowMeta((event.target as HTMLInputElement).value, editor.flowDescription.value)"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Descripción</span>
            <textarea
                :value="editor.flowDescription.value"
                rows="3"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                :disabled="editor.readOnly.value"
                @input="(event) => editor.updateFlowMeta(editor.flowName.value, (event.target as HTMLTextAreaElement).value)"
            />
        </label>

        <div v-if="!editor.readOnly.value" class="rounded-md bg-zinc-50 px-3 py-2 text-[11px] text-zinc-500">
            Los cambios se guardan con el botón Guardar o Ctrl/Cmd+S. Se publican con Publicar.
        </div>
    </div>
</template>
