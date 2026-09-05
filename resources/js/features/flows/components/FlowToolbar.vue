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
    <div class="app-card flex flex-wrap items-center gap-2 px-4 py-3">
        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass">
            {{ statusText }}
        </span>

        <div class="mx-1 h-5 w-px bg-[#dce8df]" />

        <button
            type="button"
            class="app-button app-button--secondary px-3 py-1.5 text-xs"
            :disabled="editor.readOnly.value || editor.saveState.value === 'saving'"
            @click="editor.save()"
        >
            Guardar (Ctrl+S)
        </button>

        <div class="mx-1 h-5 w-px bg-[#dce8df]" />

        <button
            type="button"
            class="app-button app-button--secondary px-3 py-1.5 text-xs"
            :disabled="!editor.canUndo.value || editor.readOnly.value"
            @click="editor.undo()"
        >
            Deshacer
        </button>
        <button
            type="button"
            class="app-button app-button--secondary px-3 py-1.5 text-xs"
            :disabled="!editor.canRedo.value || editor.readOnly.value"
            @click="editor.redo()"
        >
            Rehacer
        </button>

        <div class="mx-1 h-5 w-px bg-[#dce8df]" />

        <button
            type="button"
            class="app-button app-button--secondary px-3 py-1.5 text-xs"
            @click="editor.validate()"
        >
            Validar
        </button>

        <div class="flex-1" />

        <button
            v-if="isPublished && editor.canManage"
            type="button"
            class="app-button app-button--primary px-4 py-1.5 text-xs"
            :disabled="editor.publishState.value === 'deactivating'"
            @click="emit('deactivate-request')"
        >
            Desactivar
        </button>
        <button
            v-else-if="!editor.readOnly.value"
            type="button"
            class="app-button app-button--primary px-4 py-1.5 text-xs"
            :disabled="editor.saveState.value === 'saving' || editor.publishState.value === 'publishing'"
            @click="editor.publish()"
        >
            Publicar
        </button>
    </div>
</template>
