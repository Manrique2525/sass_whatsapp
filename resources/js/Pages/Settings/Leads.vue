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
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Leads</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Administra prospectos y su avance comercial.
                </p>
            </div>

            <div v-if="success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ success }}
            </div>
            <div v-if="error && !showModal && deletingLead === null" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <div v-if="canManage" class="flex justify-end">
                <button
                    type="button"
                    class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                    @click="openCreate"
                >
                    Nuevo lead
                </button>
            </div>

            <div v-if="!can('leads.view')" class="rounded-xl border border-zinc-200 bg-white p-8 text-sm text-zinc-500 shadow-sm">
                No tienes permiso para ver leads.
            </div>

            <div v-else class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="applyFilters">
                    <div>
                        <label for="lead-search" class="mb-1 block text-sm font-medium text-zinc-700">Buscar</label>
                        <input
                            id="lead-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Nombre, teléfono o email"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label for="lead-status" class="mb-1 block text-sm font-medium text-zinc-700">Estado</label>
                        <select
                            id="lead-status"
                            v-model="filters.status"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Todos</option>
                            <option value="new">Nuevo</option>
                            <option value="contacted">Contactado</option>
                            <option value="qualified">Calificado</option>
                            <option value="won">Ganado</option>
                            <option value="lost">Perdido</option>
                        </select>
                    </div>
                    <div>
                        <label for="lead-source" class="mb-1 block text-sm font-medium text-zinc-700">Origen</label>
                        <select
                            id="lead-source"
                            v-model="filters.source"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Todos</option>
                            <option value="manual">Manual</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="web">Web</option>
                            <option value="referral">Referido</option>
                            <option value="other">Otro</option>
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
                            @click="filters = { search: '', status: '', source: '' }; applyFilters()"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-zinc-500">Cargando...</p>

                <div v-else-if="leads.length === 0" class="mt-6 rounded-md bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500">
                    No hay leads registrados.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500">
                                <th class="py-2 pr-4">Nombre</th>
                                <th class="py-2 pr-4">Contacto</th>
                                <th class="py-2 pr-4">Estado</th>
                                <th class="py-2 pr-4">Origen</th>
                                <th class="py-2 pr-4">Actualizado</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="lead in leads" :key="lead.id" class="border-b border-zinc-100">
                                <td class="max-w-[16rem] py-3 pr-4 font-medium text-zinc-900">{{ lead.name }}</td>
                                <td class="max-w-[16rem] py-3 pr-4 text-zinc-700">
                                    <span v-if="lead.phone">{{ lead.phone }}</span>
                                    <span v-if="lead.phone && lead.email" class="mx-1 text-zinc-300">·</span>
                                    <span v-if="lead.email">{{ lead.email }}</span>
                                    <span v-if="!lead.phone && !lead.email" class="text-zinc-400">—</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusColor(lead.status)"
                                    >
                                        {{ statusLabel(lead.status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-zinc-700">{{ sourceLabel(lead.source) }}</td>
                                <td class="py-3 pr-4 text-xs text-zinc-500">
                                    {{ new Date(lead.updated_at).toLocaleDateString('es-MX') }}
                                </td>
                                <td class="py-3 text-right">
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="text-emerald-700 hover:underline"
                                            @click="openEdit(lead)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 text-red-600 hover:underline"
                                            @click="askDelete(lead)"
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
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} leads
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

        <!-- Create/Edit Modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="showModal = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-zinc-900">
                    {{ editingLead === null ? 'Nuevo lead' : 'Editar lead' }}
                </h3>

                <form class="mt-4 space-y-4" @submit.prevent="saveLead">
                    <div>
                        <label for="lead-name" class="mb-1 block text-sm font-medium text-zinc-700">Nombre *</label>
                        <input
                            id="lead-name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            placeholder="Nombre del lead"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="lead-phone" class="mb-1 block text-sm font-medium text-zinc-700">Teléfono</label>
                            <input
                                id="lead-phone"
                                v-model="form.phone"
                                type="text"
                                maxlength="50"
                                placeholder="+52 993 123 4567"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                        <div>
                            <label for="lead-email" class="mb-1 block text-sm font-medium text-zinc-700">Email</label>
                            <input
                                id="lead-email"
                                v-model="form.email"
                                type="email"
                                maxlength="255"
                                placeholder="correo@ejemplo.com"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="lead-source" class="mb-1 block text-sm font-medium text-zinc-700">Origen</label>
                            <select
                                id="lead-source"
                                v-model="form.source"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                                <option value="">Sin origen</option>
                                <option value="manual">Manual</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="web">Web</option>
                                <option value="referral">Referido</option>
                                <option value="other">Otro</option>
                            </select>
                        </div>
                        <div v-if="editingLead !== null && canChangeStatus">
                            <label for="lead-status-edit" class="mb-1 block text-sm font-medium text-zinc-700">Estado</label>
                            <select
                                id="lead-status-edit"
                                v-model="form.status"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            >
                                <option :value="editingLead.status">{{ statusLabel(editingLead.status) }} (actual)</option>
                                <option v-for="t in editTransitions" :key="t" :value="t">
                                    {{ statusLabel(t) }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="lead-notes" class="mb-1 block text-sm font-medium text-zinc-700">Notas</label>
                        <textarea
                            id="lead-notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="10000"
                            placeholder="Notas sobre el lead..."
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
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

        <!-- Delete Confirmation -->
        <div
            v-if="deletingLead !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="deletingLead = null"
        >
            <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-zinc-900">Eliminar lead</h3>
                <p class="mt-2 text-sm text-zinc-600">
                    El lead <span class="font-medium text-zinc-900">"{{ deletingLead.name }}"</span> dejará de aparecer en la lista.
                </p>
                <div v-if="error && deletingLead !== null" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ error }}
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                        @click="deletingLead = null"
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
