<script setup lang="ts">
import type { FlowEditorController } from '../../useFlowEditor';

const props = defineProps<{ editor: FlowEditorController }>();

function reload(): void {
    void props.editor.reloadFromServer();
}

function keepEditing(): void {
    props.editor.clearConflict();
}

function overwrite(): void {
    void props.editor.saveOverriding();
}
</script>

<template>
    <div
        v-if="editor.conflict.value"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <h3 class="text-base font-semibold text-zinc-900">Cambios en conflicto</h3>
            <p class="mt-2 text-sm text-zinc-600">
                {{ editor.conflict.value?.message ?? 'Otra persona modificó el flujo mientras trabajabas.' }}
            </p>

            <div class="mt-5 space-y-2">
                <button
                    type="button"
                    class="w-full rounded-md border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                    @click="reload"
                >
                    Recargar desde el servidor
                    <span class="block text-xs font-normal text-zinc-500">Descarta tus cambios locales</span>
                </button>
                <button
                    type="button"
                    class="w-full rounded-md border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                    @click="keepEditing"
                >
                    Seguir editando
                    <span class="block text-xs font-normal text-zinc-500">Mantiene tus cambios; podés volver a guardar</span>
                </button>
                <button
                    v-if="editor.canManage"
                    type="button"
                    class="w-full rounded-md bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-500"
                    @click="overwrite"
                >
                    Sobrescribir con mis cambios
                    <span class="block text-xs font-normal text-red-100">Reemplaza la versión del servidor (acción explícita)</span>
                </button>
            </div>
        </div>
    </div>
</template>
