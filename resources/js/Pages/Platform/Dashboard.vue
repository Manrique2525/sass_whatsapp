<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

interface DashboardData {
    summary: Record<string, number>;
    trends: { new_customers: { period: string; count: number }[] };
    plans: { plan_id: string; name: string; slug: string; subscribers: number; is_active: boolean }[];
    subscriptions: { statuses: { status: string; count: number }[]; providers: { provider: string; count: number }[] };
    usage: { period_start: string; period_end: string; categories: { category: string; quantity: number }[] };
    whatsapp: Record<string, number>;
    operations: {
        alerts: { type: string; count: number; label: string }[];
        recent_customers: { tenant: { id: string; name: string }; owner: { name: string | null; email: string | null }; plan: { id: string; name: string } | null; created_at: string }[];
        recent_activity: { id: string; action: string; actor: string | null; target: { id: string; name: string } | null; created_at: string }[];
    };
}

const page = usePage();
const props = defineProps<{ dashboard: DashboardData }>();
const summaryCards = computed(() => [
    { label: 'Customers', value: props.dashboard.summary.total_tenants, detail: `${props.dashboard.summary.active_tenants} active`, href: '/platform/customers' },
    { label: 'Unique users', value: props.dashboard.summary.unique_users, detail: `${props.dashboard.summary.tenant_memberships} active memberships` },
    { label: 'Subscriptions', value: props.dashboard.summary.total_subscriptions, detail: `${props.dashboard.summary.active_subscriptions} active`, href: '/platform/subscriptions' },
    { label: 'WhatsApp', value: props.dashboard.summary.whatsapp_connected, detail: `${props.dashboard.summary.whatsapp_configured} configured` },
]);
const chartOptions = computed(() => ({ chart: { toolbar: { show: false }, fontFamily: 'inherit' }, colors: ['#0b8f5a'], stroke: { curve: 'smooth' as const, width: 3 }, grid: { borderColor: '#e1e9e1' }, xaxis: { categories: props.dashboard.trends.new_customers.map((item) => item.period), labels: { style: { colors: '#71877b' } } }, yaxis: { min: 0, labels: { style: { colors: '#71877b' } } }, dataLabels: { enabled: false } }));
const chartSeries = computed(() => [{ name: 'New customers', data: props.dashboard.trends.new_customers.map((item) => item.count) }]);
const formatDate = (value: string): string => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
const label = (value: string): string => value.replaceAll('_', ' ');
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="app-eyebrow">Platform Admin</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">Operations overview</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-[#60766a]">Global, read-only visibility into customers, billing, usage and connection health.</p></div>
            <span class="rounded-full border border-[#cce5bd] bg-[#eff9e8] px-3 py-1.5 text-xs font-semibold text-[#176b42]">Live local data</span>
        </div>

        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Platform summary">
            <component :is="card.href ? Link : 'div'" v-for="card in summaryCards" :key="card.label" :href="card.href" class="app-card block p-5 transition hover:-translate-y-0.5 hover:border-[#9bc79d]">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[#71877b]">{{ card.label }}</p><p class="mt-3 text-3xl font-semibold tracking-tight">{{ card.value }}</p><p class="mt-1 text-xs text-[#60766a]">{{ card.detail }}</p>
            </component>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
            <article class="app-card p-5 sm:p-6"><div class="flex items-start justify-between gap-4"><div><p class="app-eyebrow">Acquisition</p><h2 class="mt-2 text-lg font-semibold">New customers</h2><p class="mt-1 text-xs text-[#71877b]">Monthly tenant creation, last six months</p></div><strong class="text-2xl font-semibold">{{ props.dashboard.summary.new_tenants_period }}</strong></div><div v-if="props.dashboard.trends.new_customers.length" class="mt-5"><VueApexCharts type="area" height="245" :options="chartOptions" :series="chartSeries" /></div><p v-else class="py-20 text-center text-sm text-[#71877b]">No customer history available.</p></article>
            <article class="app-card p-5 sm:p-6"><p class="app-eyebrow">Plans</p><h2 class="mt-2 text-lg font-semibold">Current distribution</h2><div v-if="props.dashboard.plans.length" class="mt-5 space-y-4"><div v-for="plan in props.dashboard.plans" :key="plan.plan_id"><div class="flex justify-between gap-3 text-sm"><Link :href="`/platform/plans/${plan.plan_id}`" class="font-semibold hover:text-[#0b8f5a]">{{ plan.name }}</Link><span class="text-[#60766a]">{{ plan.subscribers }}</span></div><div class="mt-2 h-2 rounded-full bg-[#e8f0e7]"><div class="h-2 rounded-full bg-[#0b8f5a]" :style="{ width: `${props.dashboard.summary.active_subscriptions ? Math.min(100, (plan.subscribers / props.dashboard.summary.active_subscriptions) * 100) : 0}%` }"></div></div></div></div><p v-else class="py-12 text-sm text-[#71877b]">No plans configured.</p><Link href="/platform/plans" class="mt-6 inline-block text-sm font-semibold text-[#0b8f5a]">Manage plans →</Link></article>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-3">
            <article class="app-card p-5"><p class="app-eyebrow">Subscriptions</p><h2 class="mt-2 text-lg font-semibold">Status breakdown</h2><div v-if="props.dashboard.subscriptions.statuses.length" class="mt-4 space-y-3"><div v-for="item in props.dashboard.subscriptions.statuses" :key="item.status" class="flex justify-between text-sm"><span class="capitalize text-[#60766a]">{{ label(item.status) }}</span><strong>{{ item.count }}</strong></div></div><p v-else class="mt-4 text-sm text-[#71877b]">No subscriptions.</p><Link href="/platform/subscriptions" class="mt-5 inline-block text-sm font-semibold text-[#0b8f5a]">Review subscriptions →</Link></article>
            <article class="app-card p-5"><p class="app-eyebrow">Usage</p><h2 class="mt-2 text-lg font-semibold">Current billing periods</h2><p class="mt-1 text-xs text-[#71877b]">{{ formatDate(props.dashboard.usage.period_start) }} - {{ formatDate(props.dashboard.usage.period_end) }}</p><div v-if="props.dashboard.usage.categories.length" class="mt-4 space-y-3"><div v-for="item in props.dashboard.usage.categories" :key="item.category" class="flex justify-between text-sm"><span class="capitalize text-[#60766a]">{{ label(item.category) }}</span><strong>{{ item.quantity.toLocaleString() }}</strong></div></div><p v-else class="mt-4 text-sm text-[#71877b]">No metered usage recorded.</p></article>
            <article class="app-card p-5"><p class="app-eyebrow">WhatsApp</p><h2 class="mt-2 text-lg font-semibold">Connection status</h2><div class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><span class="text-[#60766a]">Configured accounts</span><strong>{{ props.dashboard.whatsapp.configured }}</strong></div><div class="flex justify-between"><span class="text-[#60766a]">Connected accounts</span><strong class="text-[#176b42]">{{ props.dashboard.whatsapp.connected }}</strong></div><div class="flex justify-between"><span class="text-[#60766a]">Disconnected accounts</span><strong>{{ props.dashboard.whatsapp.disconnected }}</strong></div><div class="flex justify-between"><span class="text-[#60766a]">Banned numbers</span><strong>{{ props.dashboard.whatsapp.banned_phone_numbers }}</strong></div></div></article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-2">
            <article class="app-card overflow-hidden"><div class="flex items-center justify-between border-b border-[#e2ebe2] px-5 py-4"><div><p class="app-eyebrow">Customer activity</p><h2 class="mt-1 text-lg font-semibold">Recent customers</h2></div><Link href="/platform/customers" class="text-sm font-semibold text-[#0b8f5a]">View all →</Link></div><div v-if="props.dashboard.operations.recent_customers.length" class="divide-y divide-[#edf2ed]"><Link v-for="item in props.dashboard.operations.recent_customers" :key="item.tenant.id" :href="`/platform/customers/${item.tenant.id}`" class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-[#f5faf3]"><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ item.tenant.name }}</p><p class="truncate text-xs text-[#71877b]">{{ item.owner.name ?? 'No active owner' }} · {{ item.plan?.name ?? 'No subscription' }}</p></div><time class="shrink-0 text-xs text-[#71877b]">{{ formatDate(item.created_at) }}</time></Link></div><p v-else class="p-8 text-sm text-[#71877b]">No customers yet.</p></article>
            <article class="app-card overflow-hidden"><div class="border-b border-[#e2ebe2] px-5 py-4"><p class="app-eyebrow">Audit</p><h2 class="mt-1 text-lg font-semibold">Recent platform activity</h2></div><div v-if="props.dashboard.operations.recent_activity.length" class="divide-y divide-[#edf2ed]"><div v-for="item in props.dashboard.operations.recent_activity" :key="item.id" class="px-5 py-4"><div class="flex justify-between gap-4"><p class="text-sm font-semibold">{{ item.action }}</p><time class="shrink-0 text-xs text-[#71877b]">{{ formatDate(item.created_at) }}</time></div><p class="mt-1 text-xs text-[#71877b]">{{ item.actor ?? 'System' }}<span v-if="item.target"> · <Link :href="`/platform/customers/${item.target.id}`" class="text-[#0b8f5a]">{{ item.target.name }}</Link></span></p></div></div><p v-else class="p-8 text-sm text-[#71877b]">No platform activity recorded.</p></article>
        </section>

        <section v-if="props.dashboard.operations.alerts.length" class="app-card mt-6 border-[#ead8a7] bg-[#fffdf4] p-5"><p class="app-eyebrow text-[#8b6b20]">Operational alerts</p><div class="mt-3 grid gap-2 sm:grid-cols-2"><div v-for="alert in props.dashboard.operations.alerts" :key="alert.type" class="flex justify-between gap-3 rounded-xl border border-[#f0e2b8] bg-white px-3 py-2.5 text-sm"><span>{{ alert.label }}</span><strong>{{ alert.count }}</strong></div></div></section>
    </PlatformLayout>
</template>
