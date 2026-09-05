<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    buildContactQuery,
    extractErrorMessage,
    hasValidPhoneDigits,
    normalizePhone,
    parseMetadata,
    type Contact,
} from '@/features/contacts/contactUtils';

interface ContactMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canManage = computed(() => can('contacts.manage'));

const loading = ref(true);
const saving = ref(false);
const deleting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const contacts = ref<Contact[]>([]);
const meta = ref<ContactMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({ search: '', phone: '', email: '' });
const pageNumber = ref(1);

const showModal = ref(false);
const editingContact = ref<Contact | null>(null);
const deletingContact = ref<Contact | null>(null);

const form = ref({
    name: '',
    phone: '',
    email: '',
    metadataText: '',
});

const lastPage = computed(() => Math.max(1, meta.value.last_page));

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/contacts`, {
            params: buildContactQuery({
                ...filters.value,
                page: pageNumber.value,
            }),
        });
        contacts.value = res.data.contacts;
        meta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los contactos.');
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
    editingContact.value = null;
    form.value = { name: '', phone: '', email: '', metadataText: '' };
    error.value = null;
    showModal.value = true;
};

const openEdit = (contact: Contact): void => {
    editingContact.value = contact;
    form.value = {
        name: contact.name,
        phone: contact.phone,
        email: contact.email ?? '',
        metadataText: parseMetadata(contact.metadata),
    };
    error.value = null;
    showModal.value = true;
};

const saveContact = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    const name = form.value.name.trim();
    const phone = normalizePhone(form.value.phone.trim());

    if (name === '') {
        error.value = 'El nombre es obligatorio.';
        return;
    }

    if (phone === '' || !hasValidPhoneDigits(form.value.phone)) {
        error.value = 'El teléfono debe tener entre 7 y 15 dígitos.';
        return;
    }

    let metadata: Record<string, unknown> | undefined;

    if (form.value.metadataText.trim() !== '') {
        try {
            metadata = JSON.parse(form.value.metadataText) as Record<string, unknown>;
        } catch {
            error.value = 'El campo "Datos adicionales" debe ser JSON válido.';
            return;
        }
    }

    const payload: Record<string, unknown> = {
        name,
        phone,
        email: form.value.email.trim() === '' ? null : form.value.email.trim(),
    };

    if (metadata !== undefined) {
        payload.metadata = metadata;
    }

    saving.value = true;
    error.value = null;
    success.value = null;

    try {
        if (editingContact.value === null) {
            await window.axios.post(`/api/v1/tenants/${tenantId}/contacts`, payload);
            success.value = 'Contacto creado.';
        } else {
            await window.axios.patch(`/api/v1/tenants/${tenantId}/contacts/${editingContact.value.id}`, payload);
            success.value = 'Contacto actualizado.';
        }

        showModal.value = false;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo guardar el contacto.');
    } finally {
        saving.value = false;
    }
};

const askDelete = (contact: Contact): void => {
    deletingContact.value = contact;
    error.value = null;
};

const confirmDelete = async (): Promise<void> => {
    if (!tenantId || deletingContact.value === null) {
        return;
    }

    deleting.value = true;
    error.value = null;

    try {
        await window.axios.delete(`/api/v1/tenants/${tenantId}/contacts/${deletingContact.value.id}`);
        success.value = 'Contacto eliminado.';
        deletingContact.value = null;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo eliminar el contacto.');
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
                <h2 class="text-2xl font-semibold tracking-tight text-[#10261f]">Contactos</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[#71877b]">
                    CRM básico: contactos de clientes con su teléfono WhatsApp normalizado
                    (E.164). Los agentes pueden consultarlos; solo owner/admin pueden crear,
                    editar o eliminar.
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
                    Nuevo contacto
                </button>
            </div>

            <div v-if="!can('contacts.view')" class="app-card p-8 text-sm text-[#71877b]">
                No tienes permiso para ver contactos.
            </div>

            <div v-else class="app-card p-5 sm:p-6">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="applyFilters">
                    <div>
                        <label for="c-search" class="mb-1 block text-sm font-medium text-[#33483e]">Buscar</label>
                        <input
                            id="c-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Nombre, teléfono o email"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="c-phone" class="mb-1 block text-sm font-medium text-[#33483e]">Teléfono</label>
                        <input
                            id="c-phone"
                            v-model="filters.phone"
                            type="text"
                            placeholder="+54 11..."
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="c-email" class="mb-1 block text-sm font-medium text-[#33483e]">Email</label>
                        <input
                            id="c-email"
                            v-model="filters.email"
                            type="text"
                            placeholder="cliente@..."
                            class="app-field"
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
                            @click="filters = { search: '', phone: '', email: '' }; applyFilters()"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-[#71877b]">Cargando...</p>

                <div v-else-if="contacts.length === 0" class="mt-6 rounded-xl border border-dashed border-[#dce8df] bg-[#f7f8f3] px-4 py-8 text-center text-sm text-[#71877b]">
                    No hay contactos que coincidan con la búsqueda.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-[#dce8df] text-xs uppercase tracking-wide text-[#71877b]">
                                <th class="py-2 pr-4">Nombre</th>
                                <th class="py-2 pr-4">Teléfono</th>
                                <th class="py-2 pr-4">Email</th>
                                <th class="py-2 pr-4">Datos adicionales</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="contact in contacts" :key="contact.id" class="border-b border-[#edf2ec]">
                                <td class="py-3 pr-4 font-medium text-[#10261f]">{{ contact.name }}</td>
                                <td class="py-3 pr-4 text-[#33483e]">{{ contact.phone }}</td>
                                <td class="py-3 pr-4 text-[#33483e]">{{ contact.email ?? '—' }}</td>
                                <td class="max-w-[12rem] truncate py-3 pr-4 text-[#71877b]">{{ parseMetadata(contact.metadata) || '—' }}</td>
                                <td class="py-3 text-right">
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="font-semibold text-[#0b8f5a] hover:underline"
                                            @click="openEdit(contact)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 font-semibold text-[#b42318] hover:underline"
                                            @click="askDelete(contact)"
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
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} contactos
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
            <div class="app-card w-full max-w-md p-6 sm:p-7">
                <h3 class="text-lg font-semibold text-[#10261f]">
                    {{ editingContact === null ? 'Nuevo contacto' : 'Editar contacto' }}
                </h3>

                <form class="mt-4 space-y-4" @submit.prevent="saveContact">
                    <div>
                        <label for="f-name" class="mb-1 block text-sm font-medium text-[#33483e]">Nombre *</label>
                        <input
                            id="f-name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="f-phone" class="mb-1 block text-sm font-medium text-[#33483e]">Teléfono WhatsApp *</label>
                        <input
                            id="f-phone"
                            v-model="form.phone"
                            type="text"
                            required
                            maxlength="40"
                            placeholder="+54 11 5555 4444"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="f-email" class="mb-1 block text-sm font-medium text-[#33483e]">Email</label>
                        <input
                            id="f-email"
                            v-model="form.email"
                            type="email"
                            maxlength="255"
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="f-metadata" class="mb-1 block text-sm font-medium text-[#33483e]">Datos adicionales (JSON)</label>
                        <textarea
                            id="f-metadata"
                            v-model="form.metadataText"
                            rows="3"
                            placeholder='{"origen":"whatsapp"}'
                            class="app-field font-mono"
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

        <div
            v-if="deletingContact !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4 backdrop-blur-sm"
            @click.self="deletingContact = null"
        >
            <div class="app-card w-full max-w-sm p-6">
                <h3 class="text-lg font-semibold text-[#10261f]">Eliminar contacto</h3>
                <p class="mt-2 text-sm leading-6 text-[#71877b]">
                    ¿Eliminar a <span class="font-medium text-[#10261f]">{{ deletingContact.name }}</span>? Se conserva el
                    historial y el teléfono quedará disponible para un futuro contacto.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="app-button app-button--secondary"
                        @click="deletingContact = null"
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
