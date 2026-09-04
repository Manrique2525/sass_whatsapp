<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    createKnowledgeBase,
    fetchKnowledgeBases,
    fetchKnowledgeDocuments,
    uploadKnowledgeDocument,
} from '@/features/knowledge/knowledgeApi';
import type { KnowledgeBase, KnowledgeDocument } from '@/features/knowledge/knowledgeTypes';

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions ?? []);
const can = (permission: string): boolean => permissions.value.includes(permission);
const canManage = computed(() => can('knowledge.manage'));
const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const bases = ref<KnowledgeBase[]>([]);
const documents = ref<KnowledgeDocument[]>([]);
const selectedBaseId = ref<string | null>(null);
const name = ref('');
const description = ref('');

const load = async (): Promise<void> => {
    if (!tenantId || !can('knowledge.view')) { loading.value = false; return; }
    loading.value = true;
    error.value = null;
    try {
        const response = await fetchKnowledgeBases(tenantId);
        bases.value = response.knowledge_bases;
        selectedBaseId.value ??= bases.value[0]?.id ?? null;
        if (selectedBaseId.value) documents.value = (await fetchKnowledgeDocuments(tenantId, selectedBaseId.value)).documents;
    } catch (err) {
        error.value = typeof err === 'object' && err !== null && 'message' in err ? String(err.message) : 'No se pudo cargar Knowledge.';
    } finally { loading.value = false; }
};

const createBase = async (): Promise<void> => {
    if (!tenantId || !name.value.trim()) return;
    saving.value = true; error.value = null; success.value = null;
    try {
        const base = await createKnowledgeBase(tenantId, { name: name.value.trim(), description: description.value.trim() || undefined });
        bases.value.push(base); selectedBaseId.value = base.id; name.value = ''; description.value = ''; success.value = 'Base creada.';
    } catch { error.value = 'No se pudo crear la base.'; } finally { saving.value = false; }
};

const upload = async (event: Event): Promise<void> => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!tenantId || !selectedBaseId.value || !file) return;
    uploading.value = true; error.value = null; success.value = null;
    try { await uploadKnowledgeDocument(tenantId, selectedBaseId.value, file); documents.value = (await fetchKnowledgeDocuments(tenantId, selectedBaseId.value)).documents; success.value = 'Documento subido y enviado a procesamiento.'; }
    catch { error.value = 'No se pudo subir el documento.'; }
    finally { uploading.value = false; (event.target as HTMLInputElement).value = ''; }
};

const selectBase = async (id: string): Promise<void> => {
    selectedBaseId.value = id; documents.value = [];
    if (tenantId) documents.value = (await fetchKnowledgeDocuments(tenantId, id)).documents;
};

onMounted(load);
</script>

<template>
    <AppLayout :user="user">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-medium text-emerald-700">Respuestas con IA</p><h1 class="mt-1 text-2xl font-semibold text-zinc-900">Knowledge</h1><p class="mt-2 text-sm text-zinc-600">Organiza documentos que tus flujos pueden consultar.</p></div>
        </div>
        <p v-if="error" class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>
        <p v-if="success" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ success }}</p>
        <div class="mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold text-zinc-900">Bases de conocimiento</h2>
                <p v-if="!loading && bases.length === 0" class="mt-3 text-sm text-zinc-500">Aún no hay bases. Crea la primera para conectar contexto a tus flujos.</p>
                <div class="mt-4 space-y-1"><button v-for="base in bases" :key="base.id" type="button" class="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-zinc-100" :class="selectedBaseId === base.id ? 'bg-zinc-100 font-semibold text-zinc-900' : 'text-zinc-600'" @click="selectBase(base.id)">{{ base.name }}<span class="block text-xs font-normal text-zinc-500">{{ base.documents_count ?? 0 }} documentos</span></button></div>
                <form v-if="canManage" class="mt-5 border-t border-zinc-200 pt-5" @submit.prevent="createBase"><label class="text-sm font-medium text-zinc-700">Nueva base</label><input v-model="name" required maxlength="255" class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Ej. Políticas de soporte"><textarea v-model="description" maxlength="2000" class="mt-2 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm" rows="2" placeholder="Descripción opcional"/><button type="submit" class="mt-2 w-full rounded-md bg-zinc-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50" :disabled="saving || !name.trim()">{{ saving ? 'Creando...' : 'Crear base' }}</button></form>
            </section>
            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-zinc-900">Documentos</h2><p class="mt-1 text-sm text-zinc-500">{{ selectedBaseId ? 'Los documentos se procesan para consultas de IA.' : 'Selecciona una base para ver sus documentos.' }}</p></div><label v-if="canManage && selectedBaseId" class="cursor-pointer rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-800">{{ uploading ? 'Subiendo...' : 'Subir documento' }}<input type="file" class="sr-only" accept=".pdf,.txt,.md,.docx" :disabled="uploading" @change="upload"></label></div><p v-if="selectedBaseId && !loading && documents.length === 0" class="mt-8 rounded-lg bg-zinc-50 p-5 text-sm text-zinc-600">No hay documentos todavía. Sube un PDF, DOCX, TXT o Markdown para empezar.</p><div v-else class="mt-5 divide-y divide-zinc-200"> <div v-for="document in documents" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><div><p class="font-medium text-zinc-800">{{ document.original_filename }}</p><p class="text-xs text-zinc-500">{{ document.mime_type }} · {{ document.status }}</p></div><span class="text-xs text-zinc-500">{{ document.chunk_count ?? 0 }} fragmentos</span></div></div></section>
        </div>
    </AppLayout>
</template>
