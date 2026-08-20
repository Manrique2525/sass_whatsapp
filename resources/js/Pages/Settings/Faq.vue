<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Faq } from '@/features/faq/faqTypes';
import { buildFaqQuery, extractErrorMessage, statusLabel, buildFaqPayload } from '@/features/faq/faqUtils';

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
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Preguntas frecuentes</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Base de conocimiento determinista: las preguntas frecuentes se comparan
                    de forma exacta (case-insensitive) contra mensajes entrantes. Las FAQs
                    activas se responden automáticamente. Solo owner/admin pueden gestionarlas.
                </p>
            </div>

            <div v-if="success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ success }}
            </div>
            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <div v-if="canManage" class="flex justify-end">
                <button
                    type="button"
                    class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                    @click="openCreate"
                >
                    Nueva FAQ
                </button>
            </div>

            <div v-if="!can('faqs.view')" class="rounded-xl border border-zinc-200 bg-white p-8 text-sm text-zinc-500 shadow-sm">
                No tienes permiso para ver FAQs.
            </div>

            <div v-else class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-3" @submit.prevent="applyFilters">
                    <div>
                        <label for="faq-search" class="mb-1 block text-sm font-medium text-zinc-700">Buscar</label>
                        <input
                            id="faq-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Pregunta o respuesta"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label for="faq-status" class="mb-1 block text-sm font-medium text-zinc-700">Estado</label>
                        <select
                            id="faq-status"
                            v-model="filters.status"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Todos</option>
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button
                            type="submit"
                            class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                            @click="filters = { search: '', status: '' }; applyFilters()"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-zinc-500">Cargando...</p>

                <div v-else-if="faqs.length === 0" class="mt-6 rounded-md bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500">
                    No hay FAQs que coincidan con la búsqueda.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500">
                                <th class="py-2 pr-4">Pregunta</th>
                                <th class="py-2 pr-4">Respuesta</th>
                                <th class="py-2 pr-4">Estado</th>
                                <th class="py-2 pr-4">Prioridad</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="faq in faqs" :key="faq.id" class="border-b border-zinc-100">
                                <td class="max-w-[16rem] py-3 pr-4 font-medium text-zinc-900">{{ faq.question }}</td>
                                <td class="max-w-[20rem] truncate py-3 pr-4 text-zinc-700">{{ faq.answer }}</td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="faq.status === 'active'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-zinc-100 text-zinc-500'"
                                    >
                                        {{ statusLabel(faq.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-zinc-700">{{ faq.priority }}</td>
                                <td class="py-3 text-right">
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="text-emerald-700 hover:underline"
                                            @click="openEdit(faq)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 text-red-600 hover:underline"
                                            @click="askDelete(faq)"
                                        >
                                            Eliminar
                                        </button>
                                    </template>
                                    <span v-else class="text-zinc-400">Solo lectura</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!loading && meta.total > 0" class="mt-4 flex items-center justify-between text-sm">
                    <p class="text-zinc-500">
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} FAQs
                    </p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="meta.current_page <= 1"
                            class="rounded-md border border-zinc-300 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
                            @click="goToPage(meta.current_page - 1)"
                        >
                            Anterior
                        </button>
                        <button
                            type="button"
                            :disabled="meta.current_page >= lastPage"
                            class="rounded-md border border-zinc-300 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
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
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="showModal = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-zinc-900">
                    {{ editingFaq === null ? 'Nueva FAQ' : 'Editar FAQ' }}
                </h3>

                <form class="mt-4 space-y-4" @submit.prevent="saveFaq">
                    <div>
                        <label for="faq-question" class="mb-1 block text-sm font-medium text-zinc-700">Pregunta *</label>
                        <input
                            id="faq-question"
                            v-model="form.question"
                            type="text"
                            required
                            maxlength="500"
                            placeholder="¿Cómo agendo una cita?"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label for="faq-answer" class="mb-1 block text-sm font-medium text-zinc-700">Respuesta *</label>
                        <textarea
                            id="faq-answer"
                            v-model="form.answer"
                            rows="4"
                            required
                            maxlength="4096"
                            placeholder="Puedes agendar una cita en nuestro sitio web..."
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="faq-priority" class="mb-1 block text-sm font-medium text-zinc-700">Prioridad (0–100)</label>
                            <input
                                id="faq-priority"
                                v-model.number="form.priority"
                                type="number"
                                min="0"
                                max="100"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                        <div>
                            <label for="faq-status-input" class="mb-1 block text-sm font-medium text-zinc-700">Estado</label>
                            <select
                                id="faq-status-input"
                                v-model="form.status"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ error }}
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                            @click="showModal = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="saving"
                            class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {{ saving ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="deletingFaq !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="deletingFaq = null"
        >
            <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-zinc-900">Eliminar FAQ</h3>
                <p class="mt-2 text-sm text-zinc-600">
                    ¿Eliminar la FAQ
                    <span class="font-medium text-zinc-900">"{{ deletingFaq.question }}"</span>?
                    Se conserva el historial de mensajes que la utilizaron.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                        @click="deletingFaq = null"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        :disabled="deleting"
                        class="rounded-md bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Eliminando...' : 'Eliminar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
