<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    buildChatbotQuery,
    extractErrorMessage,
    findStartNode,
    flowStatusLabel,
    nodeConfigSummary,
    nodeTypeLabel,
    triggerTypeLabel,
} from '@/features/flows/flowUtils';
import type { Chatbot, Flow, PaginationMeta } from '@/features/flows/flowTypes';

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);

const loading = ref(true);
const loadingFlows = ref(false);
const loadingFlow = ref(false);
const error = ref<string | null>(null);

const chatbots = ref<Chatbot[]>([]);
const meta = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const pageNumber = ref(1);
const search = ref('');

const selectedChatbot = ref<Chatbot | null>(null);
const selectedFlow = ref<Flow | null>(null);
const flows = ref<Flow[]>([]);

const lastPage = computed(() => Math.max(1, meta.value.last_page));

const canView = computed(() => can('flows.view'));

const loadChatbots = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/chatbots`, {
            params: buildChatbotQuery({ search: search.value, page: pageNumber.value }),
        });
        chatbots.value = res.data.chatbots;
        meta.value = res.data.meta;
        selectedChatbot.value = null;
        flows.value = [];
        selectedFlow.value = null;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los chatbots.');
    } finally {
        loading.value = false;
    }
};

const applySearch = (): void => {
    pageNumber.value = 1;
    loadChatbots();
};

const goToPage = (target: number): void => {
    if (target < 1 || target > lastPage.value) {
        return;
    }
    pageNumber.value = target;
    loadChatbots();
};

const selectChatbot = async (chatbot: Chatbot): Promise<void> => {
    if (!tenantId) {
        return;
    }

    selectedChatbot.value = chatbot;
    selectedFlow.value = null;

    if (chatbot.flows && chatbot.flows.length > 0) {
        flows.value = chatbot.flows;
        return;
    }

    loadingFlows.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/chatbots/${chatbot.id}/flows`);
        flows.value = res.data.flows;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudieron cargar los flujos.');
    } finally {
        loadingFlows.value = false;
    }
};

const selectFlow = async (flow: Flow): Promise<void> => {
    if (!tenantId) {
        return;
    }

    selectedFlow.value = flow;

    if (flow.nodes && flow.connections && flow.triggers) {
        return;
    }

    loadingFlow.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/flows/${flow.id}`);
        selectedFlow.value = res.data.flow;
    } catch (err) {
        error.value = extractErrorMessage(err, 'No se pudo cargar el flujo.');
    } finally {
        loadingFlow.value = false;
    }
};

onMounted(loadChatbots);
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Flujos</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Automatizaciones de WhatsApp por chatbot. Consulta el estado de cada flujo,
                    sus nodos, conexiones y triggers, o abrí el editor visual para modificarlo.
                </p>
            </div>

            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <div v-if="!canView" class="rounded-xl border border-zinc-200 bg-white p-8 text-sm text-zinc-500 shadow-sm">
                No tienes permiso para ver flujos.
            </div>

            <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Chatbots</h3>

                    <form class="mb-4 flex gap-2" @submit.prevent="applySearch">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Buscar chatbot..."
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                        <button
                            type="submit"
                            class="shrink-0 rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
                        >
                            Buscar
                        </button>
                    </form>

                    <div v-if="loading" class="py-8 text-center text-sm text-zinc-400">Cargando chatbots...</div>

                    <div v-else-if="chatbots.length === 0" class="py-8 text-center text-sm text-zinc-400">
                        No hay chatbots en este tenant.
                    </div>

                    <ul v-else class="space-y-2">
                        <li v-for="chatbot in chatbots" :key="chatbot.id">
                            <button
                                type="button"
                                class="w-full rounded-md border p-3 text-left transition"
                                :class="
                                    selectedChatbot?.id === chatbot.id
                                        ? 'border-emerald-500 bg-emerald-50'
                                        : 'border-zinc-200 hover:bg-zinc-50'
                                "
                                @click="selectChatbot(chatbot)"
                            >
                                <span class="block text-sm font-medium text-zinc-900">{{ chatbot.name }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">
                                    {{ chatbot.flows_count ?? chatbot.flows?.length ?? 0 }} flujo(s)
                                </span>
                            </button>
                        </li>
                    </ul>

                    <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-3 py-1 text-zinc-700 hover:bg-zinc-50 disabled:opacity-40"
                            :disabled="meta.current_page <= 1"
                            @click="goToPage(meta.current_page - 1)"
                        >
                            Anterior
                        </button>
                        <span class="text-xs text-zinc-500">
                            {{ meta.current_page }} / {{ meta.last_page }}
                        </span>
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-3 py-1 text-zinc-700 hover:bg-zinc-50 disabled:opacity-40"
                            :disabled="meta.current_page >= meta.last_page"
                            @click="goToPage(meta.current_page + 1)"
                        >
                            Siguiente
                        </button>
                    </div>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                        {{ selectedChatbot ? `Flujos · ${selectedChatbot.name}` : 'Flujos' }}
                    </h3>

                    <div v-if="loadingFlows" class="py-8 text-center text-sm text-zinc-400">Cargando flujos...</div>

                    <div v-else-if="!selectedChatbot" class="py-8 text-center text-sm text-zinc-400">
                        Seleccioná un chatbot para ver sus flujos.
                    </div>

                    <div v-else-if="flows.length === 0" class="py-8 text-center text-sm text-zinc-400">
                        Este chatbot no tiene flujos todavía.
                    </div>

                    <ul v-else class="space-y-2">
                        <li v-for="flow in flows" :key="flow.id">
                            <div
                                class="w-full rounded-md border p-3 text-left transition"
                                :class="
                                    selectedFlow?.id === flow.id
                                        ? 'border-emerald-500 bg-emerald-50'
                                        : 'border-zinc-200'
                                "
                            >
                                <button
                                    type="button"
                                    class="w-full text-left"
                                    @click="selectFlow(flow)"
                                >
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-zinc-900">{{ flow.name }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="{
                                                'bg-amber-100 text-amber-700': flow.status === 'draft',
                                                'bg-emerald-100 text-emerald-700': flow.status === 'published',
                                                'bg-zinc-100 text-zinc-600': flow.status === 'inactive',
                                            }"
                                        >
                                            {{ flowStatusLabel(flow.status) }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">
                                        {{ flow.nodes?.length ?? 0 }} nodos · {{ flow.triggers_count ?? flow.triggers?.length ?? 0 }} triggers
                                    </span>
                                </button>
                                <div class="mt-2 border-t border-zinc-100 pt-2">
                                    <Link
                                        :href="`/settings/flows/${selectedChatbot?.id ?? ''}/${flow.id}`"
                                        class="inline-flex rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-700"
                                    >
                                        Abrir editor
                                    </Link>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                        {{ selectedFlow ? `Detalle · ${selectedFlow.name}` : 'Detalle' }}
                    </h3>

                    <div v-if="loadingFlow" class="py-8 text-center text-sm text-zinc-400">Cargando detalle...</div>

                    <div v-else-if="!selectedFlow" class="py-8 text-center text-sm text-zinc-400">
                        Seleccioná un flujo para ver sus nodos, conexiones y triggers.
                    </div>

                    <div v-else class="space-y-5">
                        <div>
                            <p v-if="selectedFlow.description" class="text-sm text-zinc-600">
                                {{ selectedFlow.description }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-400">
                                Creado: {{ new Date(selectedFlow.created_at).toLocaleDateString('es-AR') }}
                            </p>
                        </div>

                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Nodos ({{ selectedFlow.nodes?.length ?? 0 }})
                            </h4>
                            <div v-if="selectedFlow.nodes && selectedFlow.nodes.length > 0" class="space-y-2">
                                <div
                                    v-for="node in selectedFlow.nodes"
                                    :key="node.id"
                                    class="rounded-md border border-zinc-200 p-3"
                                    :class="{ 'border-emerald-300 bg-emerald-50/50': node.is_start }"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-medium text-zinc-900">
                                            {{ node.name }}
                                            <span v-if="node.is_start" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                INICIO
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ nodeTypeLabel(node.type) }}</span>
                                    </div>
                                    <p v-if="nodeConfigSummary(node.type, node.config) !== ''" class="mt-1 text-xs text-zinc-500">
                                        {{ nodeConfigSummary(node.type, node.config) }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-xs text-zinc-400">Sin nodos.</p>
                        </div>

                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Conexiones ({{ selectedFlow.connections?.length ?? 0 }})
                            </h4>
                            <div v-if="selectedFlow.connections && selectedFlow.connections.length > 0" class="space-y-1">
                                <div
                                    v-for="connection in selectedFlow.connections"
                                    :key="connection.id"
                                    class="flex items-center gap-2 text-xs text-zinc-600"
                                >
                                    <span class="font-mono">{{ connection.source_node_id.slice(0, 8) }}</span>
                                    <span class="text-zinc-400">→</span>
                                    <span class="font-mono">{{ connection.target_node_id.slice(0, 8) }}</span>
                                    <span v-if="connection.label" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600">
                                        {{ connection.label }}
                                    </span>
                                </div>
                            </div>
                            <p v-else class="text-xs text-zinc-400">Sin conexiones.</p>
                        </div>

                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Triggers ({{ selectedFlow.triggers?.length ?? 0 }})
                            </h4>
                            <div v-if="selectedFlow.triggers && selectedFlow.triggers.length > 0" class="space-y-1">
                                <div
                                    v-for="trigger in selectedFlow.triggers"
                                    :key="trigger.id"
                                    class="flex items-center justify-between rounded-md border border-zinc-200 px-3 py-2 text-xs"
                                >
                                    <span>
                                        <span class="font-medium text-zinc-900">{{ triggerTypeLabel(trigger.type) }}</span>
                                        <span v-if="trigger.keyword" class="ml-2 font-mono text-zinc-600">
                                            "{{ trigger.keyword }}"
                                        </span>
                                    </span>
                                    <span class="text-zinc-500">{{ trigger.active ? 'activo' : 'inactivo' }}</span>
                                </div>
                            </div>
                            <p v-else class="text-xs text-zinc-400">Sin triggers.</p>
                        </div>

                        <div v-if="findStartNode(selectedFlow.nodes)" class="rounded-md bg-zinc-50 px-3 py-2 text-xs text-zinc-500">
                            Nodo de inicio: <span class="font-medium text-zinc-700">{{ findStartNode(selectedFlow.nodes)?.name }}</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
