<script setup lang="ts">
import type { FlowEditorController } from '../../useFlowEditor';
import type { EditorValidationIssue } from '../../flowEditorTypes';

defineProps<{ editor: FlowEditorController }>();

function severityClass(severity: EditorValidationIssue['severity']): string {
    return severity === 'error'
        ? 'border-red-200 bg-red-50 text-red-700'
        : 'border-amber-200 bg-amber-50 text-amber-700';
}

function severityBadge(severity: EditorValidationIssue['severity']): string {
    return severity === 'error' ? 'ERROR' : 'AVISO';
}
</script>

<template>
    <div v-if="editor.validationIssues.value.length > 0" class="space-y-1.5">
        <div
            v-for="(issue, index) in editor.validationIssues.value"
            :key="`${issue.code}-${issue.nodeId ?? 'flow'}-${index}`"
            class="flex items-start gap-2 rounded-md border px-3 py-2 text-xs"
            :class="severityClass(issue.severity)"
        >
            <span class="mt-0.5 shrink-0 rounded bg-black/5 px-1.5 py-0.5 text-[9px] font-bold tracking-wide">
                {{ severityBadge(issue.severity) }}
            </span>
            <span class="flex-1">
                <span v-if="issue.nodeId" class="mr-1 font-semibold">Nodo:</span>
                {{ issue.message }}
            </span>
            <button
                v-if="issue.nodeId"
                type="button"
                class="shrink-0 rounded px-2 py-0.5 font-semibold text-current underline-offset-2 hover:underline"
                @click="editor.focusNode(issue.nodeId)"
            >
                Ver nodo
            </button>
        </div>
    </div>

    <div v-else class="py-2 text-center text-xs text-zinc-400">
        Sin errores de validación locales.
    </div>
</template>
