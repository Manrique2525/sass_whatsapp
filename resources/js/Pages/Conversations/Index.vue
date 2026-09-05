<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { AuthUser } from '@/types/inertia';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    buildConversationQuery,
    extractErrorMessage,
    isUnassignedHandoff,
    type Conversation,
    type ConversationFilters as ConversationFilterOptions,
    type ConversationInboxCounts,
    type InboxScope,
    type TenantMember,
} from '@/features/conversations/conversationUtils';
import type { Message, MessagePagination } from '@/features/messages/messageTypes';
import {
    applyMessageUpdate,
    buildMessageQuery,
    mergeIncomingMessage,
} from '@/features/messages/messageUtils';
import { useConversationChannel } from '@/features/realtime/useConversationChannel';
import { useInboxChannel } from '@/features/realtime/useInboxChannel';
import type { InboxConversationChangeKind } from '@/features/realtime/inboxChannelTypes';
import ChatHeader from '@/Components/Conversations/ChatHeader.vue';
import ContactPanel from '@/Components/Conversations/ContactPanel.vue';
import ConversationFilters from '@/Components/Conversations/ConversationFilters.vue';
import ConversationListItem from '@/Components/Conversations/ConversationListItem.vue';
import MessageComposer from '@/Components/Conversations/MessageComposer.vue';
import MessageList from '@/Components/Conversations/MessageList.vue';
import AppSelect from '@/Components/AppSelect.vue';

interface ContactOption {
    id: string;
    name: string;
    phone: string;
}

const page = usePage();
const user = page.props.auth.user as AuthUser | null;
const tenantId = page.props.auth.current_tenant_id;
const currentUserId = computed(() => user?.id ?? 0);
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canView = computed(() => can('conversations.view'));
const canManage = computed(() => can('conversations.manage'));
const canAssign = computed(() => can('conversations.assign'));
const canClaim = computed(() => can('conversations.claim'));
const canSeeUsers = computed(() => can('users.view'));
const canSend = computed(() => can('messages.send'));

const loading = ref(true);
const acting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const conversations = ref<Conversation[]>([]);
const meta = ref<{ current_page: number; last_page: number; per_page: number; total: number }>({
    current_page: 1,
    last_page: 1,
    per_page: 30,
    total: 0,
});
const counts = ref<ConversationInboxCounts>({ all: 0, mine: 0, unassigned: 0 });
const members = ref<TenantMember[]>([]);
const filters = ref<ConversationFilterOptions>({ search: '', status: '', agent_id: '' });
const scope = ref<InboxScope>('all');
const pageNumber = ref(1);
const listLoadingMore = ref(false);

const openId = ref<string | null>(null);
const detail = ref<Conversation | null>(null);
const view = ref<'list' | 'chat'>('list');

const messages = ref<Message[]>([]);
const messagesMeta = ref<MessagePagination>({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
const messagesLoading = ref(false);
const loadingOlder = ref(false);
const sending = ref(false);
const nearBottom = ref(true);
const hasNewMessages = ref(false);

const messageListRef = ref<InstanceType<typeof MessageList> | null>(null);

const showCreateModal = ref(false);
const creating = ref(false);
const contacts = ref<ContactOption[]>([]);
const newContactId = ref('');

const hasOlderMessages = computed(() => messagesMeta.value.current_page < messagesMeta.value.last_page);
const listHasMore = computed(() => meta.value.current_page < meta.value.last_page);
const canWrite = computed(
    () => canSend.value && detail.value !== null && detail.value.status !== 'archived',
);

let pollTimer: number | null = null;

const scopeTabs: Array<{ key: InboxScope; label: string }> = [
    { key: 'all', label: 'Todas' },
    { key: 'mine', label: 'Mias' },
    { key: 'unassigned', label: 'Sin asignar' },
];

const sortConversations = (): void => {
    conversations.value.sort((a, b) => {
        const aTime = a.last_interaction_at ?? '';
        const bTime = b.last_interaction_at ?? '';

        return bTime.localeCompare(aTime);
    });
};

const updateConversationRow = (fresh: Conversation): void => {
    const index = conversations.value.findIndex((c) => c.id === fresh.id);

    if (index !== -1) {
        const existing = conversations.value[index];
        const merged = {
            ...fresh,
            last_message: fresh.last_message ?? existing.last_message,
        };

        conversations.value[index] = merged;
    }

    if (detail.value?.id === fresh.id) {
        detail.value = {
            ...fresh,
            last_message: fresh.last_message ?? detail.value.last_message,
        };
    }

    sortConversations();
};

const removeConversationFromList = (conversationId: string): void => {
    conversations.value = conversations.value.filter((c) => c.id !== conversationId);

    if (detail.value?.id === conversationId) {
        view.value = 'list';
        detail.value = null;
        openId.value = null;
    }
};

const shouldShowInScope = (conversation: Conversation): boolean => {
    if (scope.value === 'all') {
        return true;
    }

    if (scope.value === 'mine') {
        return conversation.agent?.id === currentUserId.value;
    }

    if (scope.value === 'unassigned') {
        return isUnassignedHandoff(conversation);
    }

    return true;
};

const loadConversations = async (silent = false): Promise<void> => {
    if (!tenantId) {
        return;
    }

    if (!silent) {
        loading.value = true;
    }

    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/conversations`, {
            params: buildConversationQuery({
                ...filters.value,
                scope: scope.value,
                page: pageNumber.value,
                perPage: 30,
            }),
        });

        if (pageNumber.value === 1) {
            conversations.value = res.data.conversations as Conversation[];
        } else {
            conversations.value = [...conversations.value, ...(res.data.conversations as Conversation[])];
        }

        meta.value = res.data.meta;

        if (res.data.counts) {
            counts.value = res.data.counts as ConversationInboxCounts;
        }
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar las conversaciones.');
    } finally {
        if (!silent) {
            loading.value = false;
        }

        listLoadingMore.value = false;
    }
};

const loadMembers = async (): Promise<void> => {
    if (!tenantId || members.value.length > 0) {
        return;
    }

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/users`);

        members.value = res.data.members as TenantMember[];
    } catch {
        members.value = [];
    }
};

const changeScope = (newScope: InboxScope): void => {
    scope.value = newScope;
    pageNumber.value = 1;
    loadConversations();
};

const applyFilters = (): void => {
    pageNumber.value = 1;
    loadConversations();
};

const clearFilters = (): void => {
    filters.value = { search: '', status: '', agent_id: '' };
    applyFilters();
};

const loadMoreList = (): void => {
    if (!tenantId || listLoadingMore.value || !listHasMore.value) {
        return;
    }

    listLoadingMore.value = true;
    pageNumber.value += 1;
    loadConversations(true);
};

const loadMessages = async (): Promise<void> => {
    if (!tenantId || openId.value === null) {
        return;
    }

    messagesLoading.value = true;
    messages.value = [];
    messagesMeta.value = { current_page: 1, last_page: 1, per_page: 30, total: 0 };

    try {
        const res = await window.axios.get(
            `/api/v1/tenants/${tenantId}/conversations/${openId.value}/messages`,
            { params: buildMessageQuery(1) },
        );

        messages.value = (res.data.messages as Message[]).slice().reverse();
        messagesMeta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los mensajes.');
    } finally {
        messagesLoading.value = false;
    }
};

const openConversation = async (conversation: Conversation): Promise<void> => {
    openId.value = conversation.id;
    detail.value = conversation;
    view.value = 'chat';
    nearBottom.value = true;
    hasNewMessages.value = false;
    await loadMessages();
};

const closeChat = (): void => {
    view.value = 'list';
};

const loadOlder = async (): Promise<void> => {
    if (!tenantId || openId.value === null || loadingOlder.value || !hasOlderMessages.value) {
        return;
    }

    loadingOlder.value = true;
    const nextPage = messagesMeta.value.current_page + 1;

    try {
        const res = await window.axios.get(
            `/api/v1/tenants/${tenantId}/conversations/${openId.value}/messages`,
            { params: buildMessageQuery(nextPage) },
        );

        messages.value = [...(res.data.messages as Message[]).slice().reverse(), ...messages.value];
        messagesMeta.value = res.data.meta;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los mensajes anteriores.');
    } finally {
        loadingOlder.value = false;
    }
};

const handleIncomingMessage = (message: Message): void => {
    const alreadyPresent = messages.value.some((m) => m.id === message.id);

    if (!alreadyPresent) {
        messagesMeta.value.total += 1;
    }

    messages.value = mergeIncomingMessage(messages.value, message);

    const conversationId = message.conversation_id;

    if (detail.value?.id === conversationId) {
        detail.value = {
            ...detail.value,
            last_message: message,
            last_message_at: message.created_at,
            last_interaction_at: message.created_at,
        };
    }

    const index = conversations.value.findIndex((c) => c.id === conversationId);

    if (index !== -1) {
        conversations.value[index] = {
            ...conversations.value[index],
            last_message: message,
            last_message_at: message.created_at,
            last_interaction_at: message.created_at,
        };
    }

    sortConversations();

    if (!nearBottom.value) {
        hasNewMessages.value = true;
    }
};

const sendMessage = async (body: string): Promise<void> => {
    if (!tenantId || openId.value === null || sending.value || !canWrite.value) {
        return;
    }

    sending.value = true;
    error.value = null;
    nearBottom.value = true;
    hasNewMessages.value = false;

    try {
        const res = await window.axios.post(
            `/api/v1/tenants/${tenantId}/conversations/${openId.value}/messages`,
            { body },
        );

        handleIncomingMessage(res.data.created_message as Message);
        await nextTick();
        messageListRef.value?.scrollToBottom(true);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo enviar el mensaje.');
    } finally {
        sending.value = false;
    }
};

const onClaim = async (): Promise<void> => {
    const conversation = detail.value;

    if (!tenantId || conversation === null || acting.value) {
        return;
    }

    acting.value = true;
    error.value = null;
    success.value = null;

    try {
        const res = await window.axios.post(
            `/api/v1/tenants/${tenantId}/conversations/${conversation.id}/claim`,
        );

        success.value = 'Conversación reclamada.';
        updateConversationRow(res.data.conversation as Conversation);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo reclamar la conversación.');
    } finally {
        acting.value = false;
    }
};

const onAssign = async (agentId: number): Promise<void> => {
    const conversation = detail.value;

    if (!tenantId || conversation === null || acting.value) {
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
        updateConversationRow(res.data.conversation as Conversation);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo asignar la conversación.');
    } finally {
        acting.value = false;
    }
};

const onAction = async (action: 'close' | 'reopen' | 'pause_bot' | 'resume_bot'): Promise<void> => {
    const conversation = detail.value;

    if (!tenantId || conversation === null || acting.value) {
        return;
    }

    acting.value = true;
    error.value = null;
    success.value = null;

    try {
        const endpoint = action === 'resume_bot' ? 'resume-bot' : action;
        const res = await window.axios.post(
            `/api/v1/tenants/${tenantId}/conversations/${conversation.id}/${endpoint}`,
        );

        const messagesMap: Record<string, string> = {
            close: 'Conversación cerrada.',
            reopen: 'Conversación reabierta.',
            pause_bot: 'Bot pausado.',
            resume_bot: 'Bot reanudado.',
        };

        success.value = messagesMap[action] ?? 'Acción ejecutada.';
        updateConversationRow(res.data.conversation as Conversation);
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo ejecutar la acción.');
    } finally {
        acting.value = false;
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

        contacts.value = res.data.contacts as ContactOption[];
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
        await loadConversations();
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo crear la conversación.');
    } finally {
        creating.value = false;
    }
};

/**
 * Upsert de eventos tenant-wide del Inbox (U4 + U5).
 *
 * - handoff_requested: upsert si corresponde al scope activo.
 * - assigned/claimed/transferred: upsert siempre (puede cambiar de bucket).
 * - bot_resumed/conversation_updated: upsert si la conversación ya está en la lista.
 * - Si la conversación ya no pertenece al scope activo, se elimina de la lista.
 */
const upsertInboxConversation = (conversation: Conversation, kind: InboxConversationChangeKind, _eventId: string): void => {
    const existingIndex = conversations.value.findIndex((c) => c.id === conversation.id);
    const existsInList = existingIndex !== -1;

    if (kind === 'handoff_requested' || kind === 'claimed' || kind === 'assigned' || kind === 'transferred') {
        if (shouldShowInScope(conversation)) {
            if (existsInList) {
                const existing = conversations.value[existingIndex];
                conversations.value[existingIndex] = {
                    ...conversation,
                    last_message: conversation.last_message ?? existing.last_message,
                };
            } else {
                conversations.value.unshift(conversation);
            }
        } else if (existsInList) {
            removeConversationFromList(conversation.id);
        }
    } else if (existsInList) {
        if (shouldShowInScope(conversation)) {
            const existing = conversations.value[existingIndex];
            conversations.value[existingIndex] = {
                ...conversation,
                last_message: conversation.last_message ?? existing.last_message,
            };
        } else {
            removeConversationFromList(conversation.id);
        }
    }

    if (detail.value?.id === conversation.id) {
        detail.value = {
            ...conversation,
            last_message: conversation.last_message ?? detail.value.last_message,
        };
    }

    sortConversations();
};

useConversationChannel(
    () => tenantId,
    () => openId.value,
    {
        onMessageCreated: (message) => handleIncomingMessage(message),
        onMessageStatusUpdated: (message) => {
            messages.value = applyMessageUpdate(messages.value, message);
        },
        onConversationUpdated: (conversation) => updateConversationRow(conversation),
    },
);

useInboxChannel(
    () => tenantId,
    { onInboxChanged: upsertInboxConversation },
);

onMounted(() => {
    if (!canView.value) {
        return;
    }

    loadConversations();

    if (canSeeUsers.value) {
        loadMembers();
    }

    pollTimer = window.setInterval(() => {
        if (pageNumber.value === 1) {
            loadConversations(true);
        }
    }, 30000);
});

onBeforeUnmount(() => {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
    }
});
</script>

<template>
    <AppLayout :user="user" full-width>
        <div class="space-y-4">
            <div v-if="success" class="app-alert app-alert--success px-4">
                {{ success }}
            </div>
            <div v-if="error" class="app-alert app-alert--error px-4">
                {{ error }}
            </div>

            <div
                v-if="!canView"
                class="app-card p-8 text-sm text-[#71877b]"
            >
                No tienes permiso para ver conversaciones.
            </div>

            <div
                v-else
                class="app-card flex h-[calc(100vh-12rem)] min-h-[480px] overflow-hidden"
            >
                <section
                    class="w-full flex-col border-r border-[#dce8df] lg:flex lg:w-72 lg:shrink-0"
                    :class="view === 'list' ? 'flex' : 'hidden'"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-[#edf2ec] bg-white px-4 py-4">
                        <h2 class="text-sm font-semibold text-[#10261f]">Conversaciones</h2>
                        <button
                            v-if="canManage"
                            type="button"
                            class="app-button app-button--primary px-3 py-1 text-xs"
                            @click="openCreate"
                        >
                            Nueva
                        </button>
                    </div>

                    <div class="flex border-b border-[#edf2ec] bg-white" role="tablist">
                        <button
                            v-for="tab in scopeTabs"
                            :key="tab.key"
                            type="button"
                            role="tab"
                            :aria-selected="scope === tab.key"
                            class="flex-1 px-3 py-2 text-center text-xs font-medium transition-colors"
                            :class="scope === tab.key
                                ? 'border-b-2 border-[#0b8f5a] text-[#0b8f5a]'
                                : 'text-[#71877b] hover:text-[#33483e]'"
                            @click="changeScope(tab.key)"
                        >
                            {{ tab.label }}
                            <span
                                v-if="counts[tab.key] > 0"
                                class="ml-1 inline-flex items-center justify-center rounded-full bg-[#eef3ed] px-1.5 py-0.5 text-[10px] font-semibold text-[#60766a]"
                            >
                                {{ counts[tab.key] }}
                            </span>
                        </button>
                    </div>

                    <ConversationFilters
                        v-model="filters"
                        :members="members"
                        @apply="applyFilters"
                        @clear="clearFilters"
                    />

                    <div class="min-h-0 flex-1 overflow-y-auto bg-[#fbfcf9]">
                        <p v-if="loading" class="px-4 py-6 text-center text-sm text-zinc-500">Cargando...</p>

                        <div
                            v-else-if="conversations.length === 0"
                            class="px-4 py-6 text-center text-sm text-zinc-500"
                        >
                            No hay conversaciones que coincidan con la busqueda.
                        </div>

                        <ConversationListItem
                            v-for="conversation in conversations"
                            :key="conversation.id"
                            :conversation="conversation"
                            :active="conversation.id === openId"
                            @select="openConversation(conversation)"
                        />
                    </div>

                    <div v-if="listHasMore" class="border-t border-[#edf2ec] p-2">
                        <button
                            type="button"
                            class="app-button app-button--secondary w-full px-3 py-1.5 text-xs"
                            :disabled="listLoadingMore"
                            @click="loadMoreList"
                        >
                            {{ listLoadingMore ? 'Cargando...' : 'Cargar mas' }}
                        </button>
                    </div>
                </section>

                <section
                    class="min-w-0 flex-1 flex-col bg-[#f0f5ef] lg:flex"
                    :class="view === 'chat' ? 'flex' : 'hidden'"
                >
                    <template v-if="detail !== null">
                        <ChatHeader
                            :conversation="detail"
                            :members="members"
                            :can-manage="canManage"
                            :can-assign="canAssign"
                            :can-claim="canClaim"
                            :current-user-id="currentUserId"
                            :acting="acting"
                            @assign="onAssign"
                            @claim="onClaim"
                            @action="onAction"
                            @back="closeChat"
                        />

                        <div v-if="messagesLoading" class="flex flex-1 items-center justify-center text-sm text-zinc-500">
                            Cargando mensajes...
                        </div>

                        <MessageList
                            v-else
                            ref="messageListRef"
                            :messages="messages"
                            :loading-older="loadingOlder"
                            :has-older="hasOlderMessages"
                            :has-new-messages="hasNewMessages"
                            @load-older="loadOlder"
                            @reach-top="loadOlder"
                            @near-bottom-change="(near: boolean) => { nearBottom = near; if (near) hasNewMessages = false; }"
                        />

                        <MessageComposer
                            v-if="canWrite"
                            :sending="sending"
                            :disabled="acting"
                            @send="sendMessage"
                        />
                        <div
                            v-else
                            class="border-t border-[#dce8df] bg-white px-4 py-3 text-xs text-[#71877b]"
                        >
                            {{ canSend ? 'La conversacion esta archivada.' : 'No tienes permiso para enviar mensajes.' }}
                        </div>
                    </template>

                    <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 text-center text-zinc-400">
                        <p class="text-sm font-medium">Inbox de conversaciones</p>
                        <p class="text-xs">Elegi una conversacion para ver el historial de mensajes.</p>
                    </div>
                </section>

                <aside class="hidden w-64 shrink-0 xl:block">
                    <ContactPanel v-if="detail !== null" :conversation="detail" />
                    <div v-else class="h-full bg-white" />
                </aside>
            </div>
        </div>

        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/45 p-4"
            @click.self="showCreateModal = false"
        >
            <div class="app-card w-full max-w-md p-6">
                <h3 class="text-lg font-semibold text-[#10261f]">Nueva conversacion</h3>
                <form class="mt-4 space-y-4" @submit.prevent="createConversation">
                    <div>
                        <label for="f-contact" class="mb-1 block text-sm font-medium text-zinc-700">Contacto *</label>
                        <AppSelect
                            id="f-contact"
                            v-model="newContactId"
                            class="w-full"
                            :options="[
                                { value: '', label: 'Elegir contacto...' },
                                ...contacts.map((contact) => ({ value: contact.id, label: `${contact.name} - ${contact.phone}` })),
                            ]"
                            searchable
                        />
                    </div>
                    <div v-if="error" class="app-alert app-alert--error">
                        {{ error }}
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <button
                            type="button"
                            class="app-button app-button--secondary"
                            @click="showCreateModal = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="creating"
                            class="app-button app-button--primary px-5"
                        >
                            {{ creating ? 'Creando...' : 'Crear' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
