<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Faq } from '@/features/faq/faqTypes';
import { buildFaqQuery, extractErrorMessage, statusLabel, buildFaqPayload } from '@/features/faq/faqUtils';
import AppSelect from '@/Components/AppSelect.vue';

interface FaqMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (perm: string): boolean => permissions.value.includes(perm);
const canManage = computed(() => can('faqs.manage'));

const loading = ref(true);
const saving = ref(false);
const deleting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const faqs = ref<Faq[]>([]);
const meta = ref<FaqMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({ search: '', status: '' });
const pageNumber = ref(1);

const showModal = ref(false);
const editingFaq = ref<Faq | null>(null);
const deletingFaq = ref<Faq | null>(null);

const form = ref({
    question: '',
    answer: '',
    priority: 50,
    status: 'active' as 'active' | 'inactive',
});

const lastPage = computed(() => Math.max(1, meta.value.last_page));

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const params = buildFaqQuery({
            ...filters.value,
            page: pageNumber.value,
        });
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/faqs`, { params });
        faqs.value = res.data.faqs;
        meta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar las FAQs.');
    } finally {
        loading.value = false;
    }
};

const applyFilters = (): void => {
    pageNumber.value = 1;
    load();
};

const goToPage = (target: number): void => {
    if (target < 1 || target > lastPage.value) {
        return;
    }
    pageNumber.value = target;
    load();
};

const openCreate = (): void => {
    editingFaq.value = null;
    form.value = { question: '', answer: '', priority: 50, status: 'active' };
    error.value = null;
    showModal.value = true;
};

const openEdit = (faq: Faq): void => {
    editingFaq.value = faq;
    form.value = {
        question: faq.question,
        answer: faq.answer,
        priority: faq.priority,
        status: faq.status,
    };
    error.value = null;
    showModal.value = true;
};

const saveFaq = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    if (form.value.question.trim() === '') {
        error.value = 'La pregunta es obligatoria.';
        return;
    }

    if (form.value.answer.trim() === '') {
        error.value = 'La respuesta es obligatoria.';
        return;
    }

    if (form.value.priority < 0 || form.value.priority > 100) {
        error.value = 'La prioridad debe estar entre 0 y 100.';
        return;
    }

    const payload = buildFaqPayload(form.value);

    saving.value = true;
    error.value = null;
    success.value = null;

    try {
        if (editingFaq.value === null) {
            await window.axios.post(`/api/v1/tenants/${tenantId}/faqs`, payload);
            success.value = 'FAQ creada.';
        } else {
            await window.axios.patch(`/api/v1/tenants/${tenantId}/faqs/${editingFaq.value.id}`, payload);
            success.value = 'FAQ actualizada.';
        }

        showModal.value = false;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo guardar la FAQ.');
    } finally {
        saving.value = false;
    }
};

const askDelete = (faq: Faq): void => {
    deletingFaq.value = faq;
    error.value = null;
};

const confirmDelete = async (): Promise<void> => {
    if (!tenantId || deletingFaq.value === null) {
        return;
    }

    deleting.value = true;
    error.value = null;

    try {
        await window.axios.delete(`/api/v1/tenants/${tenantId}/faqs/${deletingFaq.value.id}`);
        success.value = 'FAQ eliminada.';
        deletingFaq.value = null;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo eliminar la FAQ.');
    } finally {
        deleting.value = false;
    }
};

onMounted(load);
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="app-card relative overflow-hidden p-6 sm:p-8">
                <h2 class="text-2xl font-semibold tracking-tight text-[#10261f]">Preguntas frecuentes</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[#71877b]">
                    Base de conocimiento determinista: las preguntas frecuentes se comparan
                    de forma exacta (case-insensitive) contra mensajes entrantes. Las FAQs
                    activas se responden automáticamente. Solo owner/admin pueden gestionarlas.
                </p>
            </div>

            <div v-if="success" class="app-alert app-alert--success px-4">
                {{ success }}
            </div>
            <div v-if="error" class="app-alert app-alert--error px-4">
                {{ error }}
            </div>

            <div v-if="canManage" class="flex justify-end">
                <button
                    type="button"
                    class="app-button app-button--primary"
                    @click="openCreate"
                >
                    Nueva FAQ
                </button>
            </div>

            <div v-if="!can('faqs.view')" class="app-card p-8 text-sm text-[#71877b]">
                No tienes permiso para ver FAQs.
            </div>

            <div v-else class="app-card p-5 sm:p-6">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-3" @submit.prevent="applyFilters">
                    <div>
                        <label for="faq-search" class="mb-1 block text-sm font-medium text-[#33483e]">Buscar</label>
                        <input
                            id="faq-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Pregunta o respuesta"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="faq-status" class="mb-1 block text-sm font-medium text-[#33483e]">Estado</label>
                        <AppSelect
                            id="faq-status"
                            v-model="filters.status"
                            :options="[
                                { value: '', label: 'Todos' },
                                { value: 'active', label: 'Activa' },
                                { value: 'inactive', label: 'Inactiva' },
                            ]"
                        />
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="app-button app-button--primary"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            class="app-button app-button--secondary"
                            @click="filters = { search: '', status: '' }; applyFilters()"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-[#71877b]">Cargando...</p>

                <div v-else-if="faqs.length === 0" class="mt-6 rounded-xl border border-dashed border-[#dce8df] bg-[#f7f8f3] px-4 py-8 text-center text-sm text-[#71877b]">
                    No hay FAQs que coincidan con la búsqueda.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-[#dce8df] text-xs uppercase tracking-wide text-[#71877b]">
                                <th class="py-2 pr-4">Pregunta</th>
                                <th class="py-2 pr-4">Respuesta</th>
                                <th class="py-2 pr-4">Estado</th>
                                <th class="py-2 pr-4">Prioridad</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="faq in faqs" :key="faq.id" class="border-b border-[#edf2ec]">
                                <td class="max-w-[16rem] py-3 pr-4 font-medium text-[#10261f]">{{ faq.question }}</td>
                                <td class="max-w-[20rem] truncate py-3 pr-4 text-[#33483e]">{{ faq.answer }}</td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="faq.status === 'active'
                                            ? 'bg-[#effaf2] text-[#176b42]'
                                            : 'bg-[#eef3ed] text-[#71877b]'"
                                    >
                                        {{ statusLabel(faq.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-[#33483e]">{{ faq.priority }}</td>
                                <td class="py-3 text-right">
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="font-semibold text-[#0b8f5a] hover:underline"
                                            @click="openEdit(faq)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 font-semibold text-[#b42318] hover:underline"
                                            @click="askDelete(faq)"
                                        >
                                            Eliminar
                                        </button>
                                    </template>
                                    <span v-else class="text-[#8a9b91]">Solo lectura</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!loading && meta.total > 0" class="mt-4 flex items-center justify-between text-sm">
                    <p class="text-[#71877b]">
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} FAQs
                    </p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="meta.current_page <= 1"
                            class="app-button app-button--secondary px-3 py-1.5 disabled:opacity-50"
                            @click="goToPage(meta.current_page - 1)"
                        >
                            Anterior
                        </button>
                        <button
                            type="button"
                            :disabled="meta.current_page >= lastPage"
                            class="app-button app-button--secondary px-3 py-1.5 disabled:opacity-50"
                            @click="goToPage(meta.current_page + 1)"
                        >
                            Siguiente
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4 backdrop-blur-sm"
            @click.self="showModal = false"
        >
            <div class="app-card w-full max-w-lg p-6 sm:p-7">
                <h3 class="text-lg font-semibold text-[#10261f]">
                    {{ editingFaq === null ? 'Nueva FAQ' : 'Editar FAQ' }}
                </h3>

                <form class="mt-4 space-y-4" @submit.prevent="saveFaq">
                    <div>
                        <label for="faq-question" class="mb-1 block text-sm font-medium text-[#33483e]">Pregunta *</label>
                        <input
                            id="faq-question"
                            v-model="form.question"
                            type="text"
                            required
                            maxlength="500"
                            placeholder="¿Cómo agendo una cita?"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="faq-answer" class="mb-1 block text-sm font-medium text-[#33483e]">Respuesta *</label>
                        <textarea
                            id="faq-answer"
                            v-model="form.answer"
                            rows="4"
                            required
                            maxlength="4096"
                            placeholder="Puedes agendar una cita en nuestro sitio web..."
                            class="app-field"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="faq-priority" class="mb-1 block text-sm font-medium text-[#33483e]">Prioridad (0–100)</label>
                            <input
                                id="faq-priority"
                                v-model.number="form.priority"
                                type="number"
                                min="0"
                                max="100"
                                class="app-field"
                            />
                        </div>
                        <div>
                            <label for="faq-status-input" class="mb-1 block text-sm font-medium text-[#33483e]">Estado</label>
                            <AppSelect
                                id="faq-status-input"
                                v-model="form.status"
                                :options="[
                                    { value: 'active', label: 'Activa' },
                                    { value: 'inactive', label: 'Inactiva' },
                                ]"
                            />
                        </div>
                    </div>

                    <div v-if="error" class="app-alert app-alert--error">
                        {{ error }}
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button
                            type="button"
                            class="app-button app-button--secondary"
                            @click="showModal = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="saving"
                            class="app-button app-button--primary disabled:opacity-50"
                        >
                            {{ saving ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="deletingFaq !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4 backdrop-blur-sm"
            @click.self="deletingFaq = null"
        >
            <div class="app-card w-full max-w-sm p-6">
                <h3 class="text-lg font-semibold text-[#10261f]">Eliminar FAQ</h3>
                <p class="mt-2 text-sm leading-6 text-[#71877b]">
                    ¿Eliminar la FAQ
                    <span class="font-medium text-[#10261f]">"{{ deletingFaq.question }}"</span>?
                    Se conserva el historial de mensajes que la utilizaron.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="app-button app-button--secondary"
                        @click="deletingFaq = null"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        :disabled="deleting"
                        class="app-button app-button--danger disabled:opacity-50"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Eliminando...' : 'Eliminar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
