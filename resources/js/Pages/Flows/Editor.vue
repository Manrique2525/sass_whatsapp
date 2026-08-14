<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFlowEditor } from '@/features/flows/useFlowEditor';
import { useKeyboardShortcuts } from '@/features/flows/useKeyboardShortcuts';
import { flowStatusLabel } from '@/features/flows/flowUtils';
import FlowEditor from '@/features/flows/components/FlowEditor.vue';
import ConfirmDialog from '@/features/flows/components/dialogs/ConfirmDialog.vue';
import ConflictDialog from '@/features/flows/components/dialogs/ConflictDialog.vue';

const props = defineProps<{ chatbotId: string; flowId: string }>();

const page = usePage();
const tenantId = computed(() => page.props.auth.current_tenant_id);
const permissions = computed(() => page.props.auth.permissions);
const canManage = computed(() => permissions.value.includes('flows.manage'));

const editor = useFlowEditor({
    tenantId: tenantId.value ?? '',
    chatbotId: props.chatbotId,
    flowId: props.flowId,
    canManage: canManage.value,
});

const showDeactivateConfirm = ref(false);
let unsubscribeInertia: (() => void) | null = null;

function handleBeforeUnload(event: BeforeUnloadEvent): void {
    if (editor.dirty.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}

function handleInertiaBefore(event: CustomEvent<{ visit: unknown }>): void {
    if (editor.dirty.value && !window.confirm('Hay cambios sin guardar en el flujo. ¿Salir igualmente?')) {
        event.preventDefault();
    }
}

function onDeleteSelection(): void {
    const selection = editor.selected.value;
    if (!selection) {
        return;
    }

    if (selection.kind === 'node') {
        editor.removeNode(selection.id);
    } else {
        editor.removeEdge(selection.id);
    }
}

function requestDeactivate(): void {
    if (editor.dirty.value) {
        void editor.save().then((saved) => {
            if (saved) {
                showDeactivateConfirm.value = true;
            }
        });
        return;
    }

    showDeactivateConfirm.value = true;
}

function confirmDeactivate(): void {
    showDeactivateConfirm.value = false;
    void editor.deactivate();
}

useKeyboardShortcuts(
    {
        onSave: () => void editor.save(),
        onUndo: editor.undo,
        onRedo: editor.redo,
        onDelete: onDeleteSelection,
        onEscape: editor.clearSelection,
    },
    () => !editor.readOnly.value,
);

onMounted(async () => {
    await editor.load();
    window.addEventListener('beforeunload', handleBeforeUnload);
    unsubscribeInertia = router.on('before', handleInertiaBefore);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    unsubscribeInertia?.();
});
</script>

<template>
    <AppLayout :user="page.props.auth.user" full-width>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-3">
                    <Link href="/settings/flows" class="text-sm text-zinc-500 hover:text-zinc-900">← Flujos</Link>
                    <div class="h-5 w-px bg-zinc-200" />
                    <div>
                        <h1 class="text-base font-semibold text-zinc-900">
                            {{ editor.flowName.value || 'Editor de flujo' }}
                        </h1>
                        <p v-if="editor.flow.value" class="text-xs text-zinc-500">
                            Última modificación: {{ new Date(editor.flow.value.updated_at).toLocaleString('es-AR') }}
                        </p>
                    </div>
                </div>
                <span
                    class="rounded-full px-3 py-1 text-xs font-semibold"
                    :class="{
                        'bg-amber-100 text-amber-700': editor.flowStatus.value === 'draft',
                        'bg-emerald-100 text-emerald-700': editor.flowStatus.value === 'published',
                        'bg-zinc-100 text-zinc-600': editor.flowStatus.value === 'inactive',
                    }"
                >
                    {{ flowStatusLabel(editor.flowStatus.value) }}
                </span>
            </div>

            <div v-if="editor.loadState.value === 'loading'" class="rounded-xl border border-zinc-200 bg-white p-10 text-center text-sm text-zinc-400">
                Cargando flujo...
            </div>

            <div v-else-if="editor.loadState.value === 'error'" class="rounded-xl border border-zinc-200 bg-white p-10 text-center">
                <p class="text-sm text-red-600">{{ editor.error.value ?? 'No se pudo cargar el flujo.' }}</p>
                <button
                    type="button"
                    class="mt-4 rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
                    @click="editor.load()"
                >
                    Reintentar
                </button>
            </div>

            <FlowEditor v-else :editor="editor" @deactivate-request="requestDeactivate" />

            <ConfirmDialog
                v-if="showDeactivateConfirm"
                title="Desactivar flujo"
                message="El flujo dejará de responder mensajes entrantes. ¿Desactivar de todas formas?"
                confirm-label="Desactivar"
                danger
                @confirm="confirmDeactivate"
                @cancel="showDeactivateConfirm = false"
            />

            <ConflictDialog :editor="editor" />
        </div>
    </AppLayout>
</template>
