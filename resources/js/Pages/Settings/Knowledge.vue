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
        <div class="app-card relative overflow-hidden p-6 sm:p-8">
            <div><p class="app-eyebrow">Respuestas con IA</p><h1 class="mt-2 text-2xl font-semibold tracking-tight text-[#10261f]">Knowledge</h1><p class="mt-2 text-sm leading-6 text-[#71877b]">Organiza documentos que tus flujos pueden consultar.</p></div>
        </div>
        <p v-if="error" class="app-alert app-alert--error mt-6">{{ error }}</p>
        <p v-if="success" class="app-alert app-alert--success mt-6">{{ success }}</p>
        <div class="mt-6 grid gap-6 lg:grid-cols-[280px_1fr]">
            <section class="app-card p-5">
                <h2 class="font-semibold text-[#10261f]">Bases de conocimiento</h2>
                <p v-if="!loading && bases.length === 0" class="mt-3 text-sm leading-6 text-[#71877b]">Aún no hay bases. Crea la primera para conectar contexto a tus flujos.</p>
                <div class="mt-4 space-y-1"><button v-for="base in bases" :key="base.id" type="button" class="w-full rounded-xl px-3 py-2.5 text-left text-sm transition hover:bg-[#f0f5ef]" :class="selectedBaseId === base.id ? 'bg-[#eef8ed] font-semibold text-[#10261f]' : 'text-[#33483e]'" @click="selectBase(base.id)">{{ base.name }}<span class="block text-xs font-normal text-[#71877b]">{{ base.documents_count ?? 0 }} documentos</span></button></div>
                <form v-if="canManage" class="mt-5 border-t border-[#dce8df] pt-5" @submit.prevent="createBase"><label class="text-sm font-medium text-[#33483e]">Nueva base</label><input v-model="name" required maxlength="255" class="app-field mt-2" placeholder="Ej. Políticas de soporte"><textarea v-model="description" maxlength="2000" class="app-field mt-2" rows="2" placeholder="Descripción opcional"/><button type="submit" class="app-button app-button--primary mt-2 w-full" :disabled="saving || !name.trim()">{{ saving ? 'Creando...' : 'Crear base' }}</button></form>
            </section>
            <section class="app-card p-5"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-[#10261f]">Documentos</h2><p class="mt-1 text-sm text-[#71877b]">{{ selectedBaseId ? 'Los documentos se procesan para consultas de IA.' : 'Selecciona una base para ver sus documentos.' }}</p></div><label v-if="canManage && selectedBaseId" class="app-button app-button--primary cursor-pointer">{{ uploading ? 'Subiendo...' : 'Subir documento' }}<input type="file" class="sr-only" accept=".pdf,.txt,.md,.docx" :disabled="uploading" @change="upload"></label></div><p v-if="selectedBaseId && !loading && documents.length === 0" class="mt-8 rounded-xl border border-dashed border-[#dce8df] bg-[#f7f8f3] p-5 text-sm text-[#71877b]">No hay documentos todavía. Sube un PDF, DOCX, TXT o Markdown para empezar.</p><div v-else class="mt-5 divide-y divide-[#dce8df]"> <div v-for="document in documents" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><div><p class="font-medium text-[#10261f]">{{ document.original_filename }}</p><p class="text-xs text-[#71877b]">{{ document.mime_type }} · {{ document.status }}</p></div><span class="text-xs text-[#71877b]">{{ document.chunk_count ?? 0 }} fragmentos</span></div></div></section>
        </div>
    </AppLayout>
</template>
