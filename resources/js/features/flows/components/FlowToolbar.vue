<script setup lang="ts">
import { computed } from 'vue';
import type { FlowEditorController } from '../useFlowEditor';

const props = defineProps<{ editor: FlowEditorController }>();

const emit = defineEmits<{ (e: 'deactivate-request'): void }>();

const statusText = computed(() => {
    const state = props.editor.saveState.value;
    const dirty = props.editor.dirty.value;

    if (state === 'saving') {
        return 'Guardando...';
    }
    if (state === 'saved') {
        return 'Guardado';
    }
    if (state === 'error') {
        return 'Error al guardar';
    }
    if (dirty) {
        return 'Cambios sin guardar';
    }
    return 'Sin cambios';
});

const statusClass = computed(() => {
    const state = props.editor.saveState.value;
    const dirty = props.editor.dirty.value;

    if (state === 'saving') {
        return 'bg-sky-100 text-sky-700';
    }
    if (state === 'saved') {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (state === 'error' || dirty) {
        return 'bg-amber-100 text-amber-700';
    }
    return 'bg-zinc-100 text-zinc-600';
});

const isPublished = computed(() => props.editor.flowStatus.value === 'published');
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 shadow-sm">
        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass">
            {{ statusText }}
        </span>

        <div class="mx-1 h-5 w-px bg-zinc-200" />

        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="editor.readOnly.value || editor.saveState.value === 'saving'"
            @click="editor.save()"
        >
            Guardar (Ctrl+S)
        </button>

        <div class="mx-1 h-5 w-px bg-zinc-200" />

        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="!editor.canUndo.value || editor.readOnly.value"
            @click="editor.undo()"
        >
            Deshacer
        </button>
        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="!editor.canRedo.value || editor.readOnly.value"
            @click="editor.redo()"
        >
            Rehacer
        </button>

        <div class="mx-1 h-5 w-px bg-zinc-200" />

        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
            @click="editor.validate()"
        >
            Validar
        </button>

        <div class="flex-1" />

        <button
            v-if="isPublished && editor.canManage"
            type="button"
            class="rounded-md bg-zinc-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="editor.publishState.value === 'deactivating'"
            @click="emit('deactivate-request')"
        >
            Desactivar
        </button>
        <button
            v-else-if="!editor.readOnly.value"
            type="button"
            class="rounded-md bg-zinc-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="editor.saveState.value === 'saving' || editor.publishState.value === 'publishing'"
            @click="editor.publish()"
        >
            Publicar
        </button>
    </div>
</template>
