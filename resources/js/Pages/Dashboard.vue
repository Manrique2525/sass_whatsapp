<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NotificationPreferenceToggle from '@/Components/Notifications/NotificationPreferenceToggle.vue';
import { fetchAnalyticsOverview } from '@/features/analytics/analyticsApi';
import { fetchKnowledgeBases } from '@/features/knowledge/knowledgeApi';
import { trackMarketingEvent } from '@/features/marketing/marketingAnalytics';

const page = usePage();
const user = page.props.auth.user;
const currentTenant = page.props.auth.tenants.find(
    (tenant) => tenant.id === page.props.auth.current_tenant_id,
);
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions ?? []);
const can = (permission: string): boolean => permissions.value.includes(permission);
const loading = ref(true);
const error = ref<string | null>(null);
const whatsappConnected = ref<boolean | null>(null);
const counts = ref({ conversations: 0, leads: 0, chatbots: 0, faqs: 0, knowledge: 0 });
const analytics = ref<{ messages: number; open: number; newLeads: number } | null>(null);

const load = async (): Promise<void> => {
    if (!tenantId) {
        loading.value = false;
        return;
    }

    const requests: Promise<unknown>[] = [];
    const results: Record<string, unknown> = {};
    const loadOne = (key: string, request: Promise<unknown>): void => {
        requests.push(request.then((value) => { results[key] = value; }).catch(() => undefined));
    };

    if (can('whatsapp.view')) loadOne('whatsapp', window.axios.get(`/api/v1/tenants/${tenantId}/whatsapp`));
    if (can('conversations.view')) loadOne('conversations', window.axios.get(`/api/v1/tenants/${tenantId}/conversations`, { params: { per_page: 1 } }));
    if (can('leads.view')) loadOne('leads', window.axios.get(`/api/v1/tenants/${tenantId}/leads`, { params: { page: 1, per_page: 1 } }));
    if (can('flows.view')) loadOne('chatbots', window.axios.get(`/api/v1/tenants/${tenantId}/chatbots`, { params: { page: 1, per_page: 1 } }));
    if (can('faqs.view')) loadOne('faqs', window.axios.get(`/api/v1/tenants/${tenantId}/faqs`, { params: { page: 1, per_page: 1 } }));
    if (can('knowledge.view')) loadOne('knowledge', fetchKnowledgeBases(tenantId, { per_page: 1 }));
    if (can('analytics.view')) loadOne('analytics', fetchAnalyticsOverview(tenantId));

    await Promise.all(requests);
    const response = (key: string): Record<string, any> => (results[key] as any)?.data ?? results[key] ?? {};
    const whatsapp = response('whatsapp');
    if ('whatsapp_account' in whatsapp) whatsappConnected.value = whatsapp.whatsapp_account !== null;
    for (const key of ['conversations', 'leads', 'chatbots', 'faqs']) counts.value[key as keyof typeof counts.value] = Number(response(key).meta?.total ?? 0);
    counts.value.knowledge = Number(response('knowledge').meta?.total ?? 0);
    const overview = response('analytics');
    if (overview.messages) analytics.value = { messages: overview.messages.total, open: overview.conversations.open, newLeads: overview.leads.new };
    if (requests.length > 0 && Object.keys(results).length === 0) error.value = 'No se pudieron cargar los datos del workspace.';
    loading.value = false;
};

onMounted(() => {
    trackMarketingEvent('dashboard_viewed', {}, { once: true });
    load();
});
</script>

<template>
    <AppLayout :user="user">
        <div class="app-card relative overflow-hidden p-6 sm:p-8">
            <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full bg-[#b7f36b]/25 blur-3xl" />
            <p class="app-eyebrow relative">Workspace activo</p>
            <h2 class="relative mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#10261f]">Hola, {{ user?.name }}</h2>
            <p class="relative mt-2 max-w-2xl text-sm leading-6 text-[#60766a]">
                <template v-if="currentTenant">
                    <span class="font-semibold text-[#33483e]">{{ currentTenant.name }} {{ currentTenant.status }}</span>.
                    Gestiona este workspace desde un solo lugar.
                </template>
                <template v-else>Sin tenant activo. Selecciona uno con el selector superior.</template>
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <Link v-if="can('conversations.view')" href="/settings/conversations" class="app-button app-button--primary">Abrir conversaciones</Link>
                <Link v-if="can('flows.view')" href="/settings/flows" class="app-button app-button--secondary">Configurar automatizaciones</Link>
            </div>
        </div>

        <p v-if="error" class="app-alert app-alert--error mt-6">{{ error }}</p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div v-for="card in [
                { label: 'Conversaciones', value: counts.conversations, href: '/settings/conversations', visible: can('conversations.view') },
                { label: 'Leads', value: counts.leads, href: '/settings/leads', visible: can('leads.view') },
                { label: 'Chatbots', value: counts.chatbots, href: '/settings/flows', visible: can('flows.view') },
                { label: 'FAQs', value: counts.faqs, href: '/settings/faq', visible: can('faqs.view') },
                { label: 'Knowledge', value: counts.knowledge, href: '/settings/knowledge', visible: can('knowledge.view') },
            ].filter((item) => item.visible)" :key="card.label" class="app-card p-5 transition hover:-translate-y-0.5 hover:shadow-[0_16px_38px_rgba(16,38,31,0.1)]">
                <p class="text-sm font-medium text-[#71877b]">{{ card.label }}</p>
                <p class="mt-2 text-3xl font-semibold tracking-[-0.04em] text-[#10261f]">{{ loading ? '...' : card.value }}</p>
                <Link :href="card.href" class="mt-3 inline-block text-sm font-semibold text-[#0b8f5a] hover:text-[#10261f]">Ver módulo →</Link>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="app-card p-6">
                <h3 class="font-semibold text-[#10261f]">Primeros pasos</h3>
                <div class="mt-4 space-y-3 text-sm">
                    <Link v-if="can('whatsapp.view')" href="/settings/whatsapp" class="flex items-center justify-between rounded-xl bg-[#f0f5ef] p-3.5 transition hover:bg-[#e5f0e5]" @click="trackMarketingEvent('whatsapp_connect_clicked')"><span>Conectar WhatsApp</span><span :class="whatsappConnected ? 'text-[#176b42]' : 'text-amber-700'">{{ whatsappConnected ? 'Conectado' : 'Revisar' }}</span></Link>
                    <Link v-if="can('flows.view')" href="/settings/flows" class="flex items-center justify-between rounded-xl bg-[#f0f5ef] p-3.5 transition hover:bg-[#e5f0e5]"><span>Publicar tu primer flujo</span><span class="text-[#71877b]">Ir a flujos →</span></Link>
                    <Link v-if="can('knowledge.view')" href="/settings/knowledge" class="flex items-center justify-between rounded-xl bg-[#f0f5ef] p-3.5 transition hover:bg-[#e5f0e5]"><span>Preparar respuestas con IA</span><span class="text-[#71877b]">{{ counts.knowledge }} bases</span></Link>
                </div>
            </section>
            <section v-if="can('analytics.view')" class="app-card p-6">
                <div class="flex items-center justify-between"><h3 class="font-semibold text-[#10261f]">Últimos 30 días</h3><Link href="/settings/analytics" class="text-sm font-semibold text-[#0b8f5a]">Ver analytics →</Link></div>
                <p v-if="loading" class="mt-5 text-sm text-zinc-500">Cargando métricas...</p>
                <p v-else-if="!analytics" class="mt-5 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-600">Aún no hay actividad suficiente para mostrar métricas.</p>
                <div v-else class="mt-5 grid grid-cols-3 gap-3 text-center"><div><p class="text-2xl font-semibold text-zinc-900">{{ analytics.messages }}</p><p class="text-xs text-zinc-500">Mensajes</p></div><div><p class="text-2xl font-semibold text-zinc-900">{{ analytics.open }}</p><p class="text-xs text-zinc-500">Abiertas</p></div><div><p class="text-2xl font-semibold text-zinc-900">{{ analytics.newLeads }}</p><p class="text-xs text-zinc-500">Leads nuevos</p></div></div>
            </section>
        </div>

        <div class="mt-6">
            <NotificationPreferenceToggle />
        </div>
    </AppLayout>
</template>
