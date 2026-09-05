<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Lead, LeadStatus } from '@/features/leads/leadTypes';
import {
  buildLeadQuery,
  extractErrorMessage,
  statusLabel,
  sourceLabel,
  statusColor,
  allowedLeadTransitions,
  buildLeadPayload,
  buildLeadEditPayload,
} from '@/features/leads/leadUtils';
import AppSelect from '@/Components/AppSelect.vue';

interface LeadMeta {
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
const canManage = computed(() => can('leads.manage'));

const loading = ref(true);
const saving = ref(false);
const deleting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const leads = ref<Lead[]>([]);
const meta = ref<LeadMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({ search: '', status: '', source: '' });
const pageNumber = ref(1);

const showModal = ref(false);
const editingLead = ref<Lead | null>(null);
const deletingLead = ref<Lead | null>(null);

const form = ref({
    name: '',
    phone: '',
    email: '',
    source: '',
    notes: '',
    status: 'new' as string,
});

const lastPage = computed(() => Math.max(1, meta.value.last_page));

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const params = buildLeadQuery({
            ...filters.value,
            page: pageNumber.value,
        });
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/leads`, { params });
        leads.value = res.data.leads;
        meta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los leads.');
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
    editingLead.value = null;
    form.value = { name: '', phone: '', email: '', source: '', notes: '', status: 'new' };
    error.value = null;
    showModal.value = true;
};

const openEdit = (lead: Lead): void => {
    editingLead.value = lead;
    form.value = {
        name: lead.name,
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        source: lead.source ?? '',
        notes: lead.notes ?? '',
        status: lead.status,
    };
    error.value = null;
    showModal.value = true;
};

const editTransitions = computed((): LeadStatus[] => {
    if (editingLead.value === null) {
        return [];
    }

    return allowedLeadTransitions(editingLead.value.status);
});

const canChangeStatus = computed((): boolean => {
    if (editingLead.value === null) {
        return false;
    }

    return editTransitions.value.length > 0;
});

const saveLead = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    if (form.value.name.trim() === '') {
        error.value = 'El nombre es obligatorio.';
        return;
    }

    saving.value = true;
    error.value = null;
    success.value = null;

    try {
        if (editingLead.value === null) {
            const payload = buildLeadPayload(form.value);
            await window.axios.post(`/api/v1/tenants/${tenantId}/leads`, payload);
            success.value = 'Lead creado.';
        } else {
            const payload = buildLeadEditPayload(form.value);
            await window.axios.patch(`/api/v1/tenants/${tenantId}/leads/${editingLead.value.id}`, payload);
            success.value = 'Lead actualizado.';
        }

        showModal.value = false;
        await load();
    } catch (err) {
        const msg = extractErrorMessage(err, 'No se pudo guardar el lead.');
        error.value = msg;
    } finally {
        saving.value = false;
    }
};

const askDelete = (lead: Lead): void => {
    deletingLead.value = lead;
    error.value = null;
};

const confirmDelete = async (): Promise<void> => {
    if (!tenantId || deletingLead.value === null) {
        return;
    }

    deleting.value = true;
    error.value = null;

    try {
        await window.axios.delete(`/api/v1/tenants/${tenantId}/leads/${deletingLead.value.id}`);
        success.value = 'Lead eliminado.';
        deletingLead.value = null;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo eliminar el lead.');
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
                <h2 class="text-2xl font-semibold tracking-tight text-[#10261f]">Leads</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[#71877b]">
                    Administra prospectos y su avance comercial.
                </p>
            </div>

            <div v-if="success" class="app-alert app-alert--success px-4">
                {{ success }}
            </div>
            <div v-if="error && !showModal && deletingLead === null" class="app-alert app-alert--error px-4">
                {{ error }}
            </div>

            <div v-if="canManage" class="flex justify-end">
                <button
                    type="button"
                    class="app-button app-button--primary"
                    @click="openCreate"
                >
                    Nuevo lead
                </button>
            </div>

            <div v-if="!can('leads.view')" class="app-card p-8 text-sm text-[#71877b]">
                No tienes permiso para ver leads.
            </div>

            <div v-else class="app-card p-5 sm:p-6">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="applyFilters">
                    <div>
                        <label for="lead-search" class="mb-1 block text-sm font-medium text-[#33483e]">Buscar</label>
                        <input
                            id="lead-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Nombre, teléfono, email o notas"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="lead-status" class="mb-1 block text-sm font-medium text-[#33483e]">Estado</label>
                        <AppSelect
                            id="lead-status"
                            v-model="filters.status"
                            :options="[
                                { value: '', label: 'Todos' },
                                { value: 'new', label: 'Nuevo' },
                                { value: 'contacted', label: 'Contactado' },
                                { value: 'qualified', label: 'Calificado' },
                                { value: 'won', label: 'Ganado' },
                                { value: 'lost', label: 'Perdido' },
                            ]"
                        />
                    </div>
                    <div>
                        <label for="lead-source" class="mb-1 block text-sm font-medium text-[#33483e]">Origen</label>
                        <AppSelect
                            id="lead-source"
                            v-model="filters.source"
                            :options="[
                                { value: '', label: 'Todos' },
                                { value: 'manual', label: 'Manual' },
                                { value: 'whatsapp', label: 'WhatsApp' },
                                { value: 'web', label: 'Web' },
                                { value: 'referral', label: 'Referido' },
                                { value: 'other', label: 'Otro' },
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
                            @click="filters = { search: '', status: '', source: '' }; applyFilters()"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-[#71877b]">Cargando...</p>

                <div v-else-if="leads.length === 0" class="mt-6 rounded-xl border border-dashed border-[#dce8df] bg-[#f7f8f3] px-4 py-8 text-center text-sm text-[#71877b]">
                    No hay leads registrados.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-[#dce8df] text-xs uppercase tracking-wide text-[#71877b]">
                                <th class="py-2 pr-4">Nombre</th>
                                <th class="py-2 pr-4">Contacto</th>
                                <th class="py-2 pr-4">Estado</th>
                                <th class="py-2 pr-4">Origen</th>
                                <th class="py-2 pr-4">Actualizado</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="lead in leads" :key="lead.id" class="border-b border-[#edf2ec]">
                                <td class="max-w-[16rem] py-3 pr-4 font-medium text-[#10261f]">{{ lead.name }}</td>
                                <td class="max-w-[16rem] py-3 pr-4 text-[#33483e]">
                                    <span v-if="lead.phone">{{ lead.phone }}</span>
                                    <span v-if="lead.phone && lead.email" class="mx-1 text-[#b7c8bb]">·</span>
                                    <span v-if="lead.email">{{ lead.email }}</span>
                                    <span v-if="!lead.phone && !lead.email" class="text-[#8a9b91]">—</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusColor(lead.status)"
                                    >
                                        {{ statusLabel(lead.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-[#33483e]">{{ sourceLabel(lead.source) }}</td>
                                <td class="py-3 pr-4 text-xs text-[#71877b]">
                                    {{ new Date(lead.updated_at).toLocaleDateString('es-MX') }}
                                </td>
                                <td class="py-3 text-right">
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="font-semibold text-[#0b8f5a] hover:underline"
                                            @click="openEdit(lead)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 font-semibold text-[#b42318] hover:underline"
                                            @click="askDelete(lead)"
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
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} leads
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

        <!-- Create/Edit Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4 backdrop-blur-sm"
            @click.self="showModal = false"
            @keydown.escape="showModal = false"
        >
            <div class="app-card w-full max-w-lg p-6 sm:p-7">
                <h3 class="text-lg font-semibold text-[#10261f]">
                    {{ editingLead === null ? 'Nuevo lead' : 'Editar lead' }}
                </h3>

                <form class="mt-4 space-y-4" @submit.prevent="saveLead">
                    <div>
                        <label for="lead-name" class="mb-1 block text-sm font-medium text-[#33483e]">Nombre *</label>
                        <input
                            id="lead-name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            placeholder="Nombre del lead"
                            class="app-field"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="lead-phone" class="mb-1 block text-sm font-medium text-[#33483e]">Teléfono</label>
                            <input
                                id="lead-phone"
                                v-model="form.phone"
                                type="text"
                                maxlength="50"
                                placeholder="+52 993 123 4567"
                                class="app-field"
                            />
                        </div>
                        <div>
                            <label for="lead-email" class="mb-1 block text-sm font-medium text-[#33483e]">Email</label>
                            <input
                                id="lead-email"
                                v-model="form.email"
                                type="email"
                                maxlength="255"
                                placeholder="correo@ejemplo.com"
                                class="app-field"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="lead-source-form" class="mb-1 block text-sm font-medium text-[#33483e]">Origen</label>
                            <AppSelect
                                id="lead-source-form"
                                v-model="form.source"
                                :options="[
                                    { value: '', label: 'Sin origen' },
                                    { value: 'manual', label: 'Manual' },
                                    { value: 'whatsapp', label: 'WhatsApp' },
                                    { value: 'web', label: 'Web' },
                                    { value: 'referral', label: 'Referido' },
                                    { value: 'other', label: 'Otro' },
                                ]"
                            />
                        </div>
                        <div v-if="editingLead !== null && canChangeStatus">
                            <label for="lead-status-edit" class="mb-1 block text-sm font-medium text-[#33483e]">Estado</label>
                            <AppSelect
                                id="lead-status-edit"
                                v-model="form.status"
                                :options="[
                                    { value: editingLead.status, label: `${statusLabel(editingLead.status)} (actual)` },
                                    ...editTransitions.map((t) => ({ value: t, label: statusLabel(t) })),
                                ]"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="lead-notes" class="mb-1 block text-sm font-medium text-[#33483e]">Notas</label>
                        <textarea
                            id="lead-notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="10000"
                            placeholder="Notas sobre el lead..."
                            class="app-field"
                        />
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

        <!-- Delete Confirmation -->
        <div
            v-if="deletingLead !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4 backdrop-blur-sm"
            @click.self="deletingLead = null"
            @keydown.escape="deletingLead = null"
        >
            <div class="app-card w-full max-w-sm p-6">
                <h3 class="text-lg font-semibold text-[#10261f]">Eliminar lead</h3>
                <p class="mt-2 text-sm leading-6 text-[#71877b]">
                    El lead <span class="font-medium text-[#10261f]">"{{ deletingLead.name }}"</span> dejará de aparecer en la lista.
                </p>
                <div v-if="error && deletingLead !== null" class="app-alert app-alert--error mt-3">
                    {{ error }}
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="app-button app-button--secondary"
                        @click="deletingLead = null"
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
