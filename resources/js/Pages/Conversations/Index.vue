<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    buildConversationQuery,
    canClose,
    canReopen,
    CONVERSATION_STATUS_META,
    extractErrorMessage,
    formatLastInteraction,
    type Conversation,
    type ConversationStatus,
} from '@/features/conversations/conversationUtils';

interface ConversationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Member {
    id: number;
    user: { id: number; name: string; email: string };
    role: string;
}

interface ContactOption {
    id: string;
    name: string;
    phone: string;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canView = computed(() => can('conversations.view'));
const canManage = computed(() => can('conversations.manage'));
const canAssign = computed(() => can('conversations.assign'));
const canSeeUsers = computed(() => can('users.view'));

const loading = ref(true);
const acting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const conversations = ref<Conversation[]>([]);
const meta = ref<ConversationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const members = ref<Member[]>([]);

const filters = ref<{ search: string; status: ConversationStatus | ''; agent_id: number | '' }>({
    search: '',
    status: '',
    agent_id: '',
});
const pageNumber = ref(1);

const showCreateModal = ref(false);
const showDetailModal = ref(false);
const creating = ref(false);
const detail = ref<Conversation | null>(null);
const contacts = ref<ContactOption[]>([]);
const newContactId = ref('');

const lastPage = computed(() => Math.max(1, meta.value.last_page));
const statusMeta = CONVERSATION_STATUS_META;

const statusOptions: ConversationStatus[] = ['open', 'pending', 'resolved', 'archived'];

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/conversations`, {
            params: buildConversationQuery({
                ...filters.value,
                page: pageNumber.value,
            }),
        });
        conversations.value = res.data.conversations;
        meta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar las conversaciones.');
    } finally {
        loading.value = false;
    }
};

const loadMembers = async (): Promise<void> => {
    if (!tenantId || members.value.length > 0) {
        return;
    }

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/users`);
        members.value = res.data.members as Member[];
    } catch {
        members.value = [];
    }
};

const applyFilters = (): void => {
    pageNumber.value = 1;
    load();
};

const clearFilters = (): void => {
    filters.value = { search: '', status: '', agent_id: '' };
    applyFilters();
};

const goToPage = (target: number): void => {
    if (target < 1 || target > lastPage.value) {
        return;
    }
    pageNumber.value = target;
    load();
};

const openDetail = async (conversation: Conversation): Promise<void> => {
    if (!tenantId) {
        return;
    }

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/conversations/${conversation.id}`);
        detail.value = res.data.conversation as Conversation;
        showDetailModal.value = true;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo cargar la conversación.');
    }
};

const openCreate = async (): Promise<void> => {
    newContactId.value = '';
    error.value = null;
    showCreateModal.value = true;

    if (!tenantId || contacts.value.length > 0) {
        return;
    }

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/contacts`, { params: { per_page: 100 } });
        contacts.value = (res.data.contacts as ContactOption[]);
    } catch {
        contacts.value = [];
    }
};

const createConversation = async (): Promise<void> => {
    if (!tenantId || newContactId.value === '') {
        error.value = 'Debes elegir un contacto.';
        return;
    }

    creating.value = true;
    error.value = null;
    success.value = null;

    try {
        await window.axios.post(`/api/v1/tenants/${tenantId}/conversations`, {
            contact_id: newContactId.value,
        });
        success.value = 'Conversación creada.';
        showCreateModal.value = false;
        await load();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo crear la conversación.');
    } finally {
        creating.value = false;
    }
};

const onAssign = async (conversation: Conversation, target: string): Promise<void> => {
    if (!tenantId || target === '') {
        return;
    }

    const agentId = Number(target);

    if (conversation.agent?.id === agentId) {
        return;
    }

    acting.value = true;
    error.value = null;
    success.value = null;

    const endpoint = conversation.agent === null ? 'assign' : 'transfer';

    try {
        const res = await window.axios.post(
            `/api/v1/tenants/${tenantId}/conversations/${conversation.id}/${endpoint}`,
            { agent_id: agentId },
        );
        success.value = conversation.agent === null ? 'Conversación asignada.' : 'Conversación transferida.';
        await refreshRow(conversation, res.data.conversation as Conversation);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo asignar la conversación.');
    } finally {
        acting.value = false;
    }
};

const runAction = async (conversation: Conversation, action: 'close' | 'reopen' | 'pause-bot' | 'resume-bot'): Promise<void> => {
    if (!tenantId) {
        return;
    }

    acting.value = true;
    error.value = null;
    success.value = null;

    try {
        const res = await window.axios.post(
            `/api/v1/tenants/${tenantId}/conversations/${conversation.id}/${action}`,
        );
        const messages: Record<string, string> = {
            close: 'Conversación cerrada.',
            reopen: 'Conversación reabierta.',
            'pause-bot': 'Bot pausado.',
            'resume-bot': 'Bot reanudado.',
        };
        success.value = messages[action];
        await refreshRow(conversation, res.data.conversation as Conversation);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo ejecutar la acción.');
    } finally {
        acting.value = false;
    }
};

const refreshRow = (oldRow: Conversation, fresh: Conversation): void => {
    const index = conversations.value.findIndex((c) => c.id === oldRow.id);

    if (index !== -1) {
        conversations.value[index] = fresh;
    }

    if (showDetailModal.value && detail.value?.id === oldRow.id) {
        detail.value = fresh;
    }
};

const assignableAgents = (conversation: Conversation): Member[] => {
    return members.value.filter((m) => m.user.id !== conversation.agent?.id);
};

onMounted(() => {
    load();
    if (canSeeUsers.value && canAssign.value) {
        loadMembers();
    }
});
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Conversaciones</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Inbox de conversaciones por contacto. Los agentes pueden consultarlas; solo
                    owner/admin pueden crear, cambiar estados, pausar el bot y asignar/transferir a
                    otros agentes. La conversación se asocia al contacto desde el primer mensaje.
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
                    Nueva conversación
                </button>
            </div>

            <div v-if="!canView" class="rounded-xl border border-zinc-200 bg-white p-8 text-sm text-zinc-500 shadow-sm">
                No tienes permiso para ver conversaciones.
            </div>

            <div v-else class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <form class="grid grid-cols-1 gap-4 sm:grid-cols-4" @submit.prevent="applyFilters">
                    <div>
                        <label for="cv-search" class="mb-1 block text-sm font-medium text-zinc-700">Buscar</label>
                        <input
                            id="cv-search"
                            v-model="filters.search"
                            type="text"
                            placeholder="Contacto, teléfono o email"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label for="cv-status" class="mb-1 block text-sm font-medium text-zinc-700">Estado</label>
                        <select
                            id="cv-status"
                            v-model="filters.status"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Todos</option>
                            <option v-for="status in statusOptions" :key="status" :value="status">
                                {{ statusMeta[status].label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="cv-agent" class="mb-1 block text-sm font-medium text-zinc-700">Agente</label>
                        <select
                            id="cv-agent"
                            v-model="filters.agent_id"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Todos</option>
                            <option v-for="m in members" :key="m.user.id" :value="m.user.id">
                                {{ m.user.name }}
                            </option>
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
                            @click="clearFilters"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                <p v-if="loading" class="mt-6 text-sm text-zinc-500">Cargando...</p>

                <div v-else-if="conversations.length === 0" class="mt-6 rounded-md bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500">
                    No hay conversaciones que coincidan con la búsqueda.
                </div>

                <div v-else class="mt-6 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500">
                                <th class="py-2 pr-4">Contacto</th>
                                <th class="py-2 pr-4">Estado</th>
                                <th class="py-2 pr-4">Última interacción</th>
                                <th class="py-2 pr-4">Agente</th>
                                <th class="py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="conversation in conversations" :key="conversation.id" class="border-b border-zinc-100">
                                <td class="py-3 pr-4">
                                    <button type="button" class="text-left font-medium text-zinc-900 hover:underline" @click="openDetail(conversation)">
                                        {{ conversation.contact?.name ?? 'Contacto' }}
                                    </button>
                                    <p class="text-xs text-zinc-500">{{ conversation.contact?.phone ?? '—' }}</p>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusMeta[conversation.status].badge">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="statusMeta[conversation.status].dot"></span>
                                        {{ conversation.status_label }}
                                    </span>
                                    <span v-if="conversation.bot_paused" class="ml-2 text-xs font-medium text-red-600" title="Bot pausado">
                                        Bot pausado
                                    </span>
                                </td>
                                <td class="py-3 pr-4 text-zinc-700">{{ formatLastInteraction(conversation) }}</td>
                                <td class="py-3 pr-4 text-zinc-700">{{ conversation.agent?.name ?? 'Sin asignar' }}</td>
                                <td class="py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <select
                                            v-if="canAssign && members.length > 0"
                                            class="rounded-md border border-zinc-300 px-2 py-1 text-xs text-zinc-700"
                                            :value="conversation.agent?.id ?? ''"
                                            :disabled="acting"
                                            @change="onAssign(conversation, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option value="">Asignar...</option>
                                            <option v-for="m in assignableAgents(conversation)" :key="m.user.id" :value="m.user.id">
                                                {{ m.user.name }}
                                            </option>
                                        </select>
                                        <button
                                            v-if="canManage && canClose(conversation.status)"
                                            type="button"
                                            :disabled="acting"
                                            class="text-emerald-700 hover:underline disabled:opacity-50"
                                            @click="runAction(conversation, 'close')"
                                        >
                                            Cerrar
                                        </button>
                                        <button
                                            v-if="canManage && canReopen(conversation.status)"
                                            type="button"
                                            :disabled="acting"
                                            class="text-sky-700 hover:underline disabled:opacity-50"
                                            @click="runAction(conversation, 'reopen')"
                                        >
                                            Reabrir
                                        </button>
                                        <button
                                            v-if="canManage && !conversation.bot_paused && conversation.status !== 'archived'"
                                            type="button"
                                            :disabled="acting"
                                            class="text-amber-700 hover:underline disabled:opacity-50"
                                            @click="runAction(conversation, 'pause-bot')"
                                        >
                                            Pausar bot
                                        </button>
                                        <button
                                            v-if="canManage && conversation.bot_paused"
                                            type="button"
                                            :disabled="acting"
                                            class="text-amber-700 hover:underline disabled:opacity-50"
                                            @click="runAction(conversation, 'resume-bot')"
                                        >
                                            Reanudar bot
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!loading && meta.total > 0" class="mt-4 flex items-center justify-between text-sm">
                    <p class="text-zinc-500">
                        Página {{ meta.current_page }} de {{ lastPage }} · {{ meta.total }} conversaciones
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
            v-if="showCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="showCreateModal = false"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg">
                <h3 class="text-lg font-semibold text-zinc-900">Nueva conversación</h3>
                <form class="mt-4 space-y-4" @submit.prevent="createConversation">
                    <div>
                        <label for="f-contact" class="mb-1 block text-sm font-medium text-zinc-700">Contacto *</label>
                        <select
                            id="f-contact"
                            v-model="newContactId"
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">Elegir contacto...</option>
                            <option v-for="contact in contacts" :key="contact.id" :value="contact.id">
                                {{ contact.name }} · {{ contact.phone }}
                            </option>
                        </select>
                    </div>
                    <div v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ error }}
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                            @click="showCreateModal = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="creating"
                            class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {{ creating ? 'Creando...' : 'Crear' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="showDetailModal && detail !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
            @click.self="showDetailModal = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg">
                <div class="flex items-start justify-between gap-4">
                    <h3 class="text-lg font-semibold text-zinc-900">
                        {{ detail.contact?.name ?? 'Conversación' }}
                    </h3>
                    <button type="button" class="text-zinc-400 hover:text-zinc-600" @click="showDetailModal = false">✕</button>
                </div>
                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Teléfono</dt>
                        <dd class="mt-0.5 text-zinc-800">{{ detail.contact?.phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Email</dt>
                        <dd class="mt-0.5 text-zinc-800">{{ detail.contact?.email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Estado</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusMeta[detail.status].badge">
                                {{ detail.status_label }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Agente asignado</dt>
                        <dd class="mt-0.5 text-zinc-800">{{ detail.agent?.name ?? 'Sin asignar' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Último mensaje</dt>
                        <dd class="mt-0.5 text-zinc-800">{{ formatLastInteraction({ ...detail, last_interaction_at: detail.last_message_at }) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Última interacción</dt>
                        <dd class="mt-0.5 text-zinc-800">{{ formatLastInteraction(detail) }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase text-zinc-500">Contexto (motor de flujos)</dt>
                        <dd class="mt-0.5 font-mono text-xs text-zinc-700">
                            {{ detail.context === null ? '—' : JSON.stringify(detail.context) }}
                        </dd>
                    </div>
                </dl>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
                        @click="showDetailModal = false"
                    >
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
