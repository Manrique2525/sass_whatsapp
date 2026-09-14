<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppSelect from '@/Components/AppSelect.vue';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

interface Subscription {
    id: string;
    tenant: { id: string; name: string; slug: string };
    owner: { name: string | null; email: string | null };
    plan: { id: string; name: string; slug: string };
    status: string;
    provider: string;
    provider_subscription_id: string | null;
    current_period_start: string | null;
    current_period_end: string | null;
    created_at: string;
    usage: { categories: Record<string, { used: number; limit: number | null; percentage: number | null }> } | null;
}

const page = usePage();
const props = defineProps<{ subscriptions: Subscription[]; pagination: { current_page: number; last_page: number; total: number }; filters: Record<string, string>; plans: { id: string; name: string }[] }>();
const filters = reactive({ search: props.filters.search ?? '', plan_id: props.filters.plan_id ?? '', status: props.filters.status ?? '', provider: props.filters.provider ?? '' });
const options = (items: { value: string; label: string }[], all: string) => [{ value: '', label: all }, ...items];
const apply = (): void => router.get('/platform/subscriptions', filters, { preserveState: true, replace: true });
const date = (value: string | null): string => value ? new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value)) : 'Not available';
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="app-eyebrow">Platform operations</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">Subscriptions</h1><p class="mt-2 text-sm text-[#71877b]">Read-only subscription and usage administration.</p></div><span class="text-sm text-[#71877b]">{{ props.pagination.total }} subscriptions</span></div>
        <section class="app-card mt-6 grid gap-4 p-5 md:grid-cols-4">
            <label class="md:col-span-2"><span class="app-label">Search tenant</span><input v-model="filters.search" class="app-input" placeholder="Name or slug" @keyup.enter="apply" /></label>
            <div><span class="app-label">Plan</span><AppSelect v-model="filters.plan_id" :options="options(props.plans.map((plan) => ({ value: plan.id, label: plan.name })), 'All plans')" clearable aria-label="Filter by plan" /></div>
            <div><span class="app-label">Provider</span><AppSelect v-model="filters.provider" :options="options([{ value: 'local', label: 'Local' }, { value: 'stripe', label: 'Stripe' }], 'All providers')" clearable aria-label="Filter by provider" /></div>
            <div><span class="app-label">Status</span><AppSelect v-model="filters.status" :options="options([{ value: 'active', label: 'Active' }, { value: 'past_due', label: 'Past due' }, { value: 'pending', label: 'Pending' }, { value: 'cancelled', label: 'Cancelled' }], 'All statuses')" clearable aria-label="Filter by status" /></div>
            <div class="flex items-end"><button class="app-button app-button--primary" type="button" @click="apply">Apply filters</button></div>
        </section>
        <section class="app-card mt-6 overflow-x-auto"><table class="w-full min-w-[980px] text-left text-sm"><thead class="border-b border-[#e7eee7] text-xs uppercase tracking-[0.12em] text-[#71877b]"><tr><th class="px-5 py-4">Tenant</th><th class="px-5 py-4">Plan</th><th class="px-5 py-4">Status</th><th class="px-5 py-4">Provider</th><th class="px-5 py-4">Current period</th><th class="px-5 py-4">Usage</th><th class="px-5 py-4"></th></tr></thead><tbody class="divide-y divide-[#edf2ec]"><tr v-for="subscription in props.subscriptions" :key="subscription.id"><td class="px-5 py-4"><Link :href="`/platform/customers/${subscription.tenant.id}`" class="font-semibold text-[#0b8f5a]">{{ subscription.tenant.name }}</Link><p class="mt-1 text-xs text-[#71877b]">{{ subscription.owner.email ?? 'No owner' }}</p></td><td class="px-5 py-4 font-medium">{{ subscription.plan.name }}</td><td class="px-5 py-4 capitalize">{{ subscription.status.replaceAll('_', ' ') }}</td><td class="px-5 py-4 capitalize">{{ subscription.provider }}<p v-if="subscription.provider_subscription_id" class="mt-1 text-xs text-[#71877b]">{{ subscription.provider_subscription_id }}</p></td><td class="px-5 py-4 text-[#52695c]">{{ date(subscription.current_period_start) }}<br />{{ date(subscription.current_period_end) }}</td><td class="px-5 py-4">{{ subscription.usage ? `${Object.values(subscription.usage.categories).reduce((total, item) => total + item.used, 0).toLocaleString()} used` : 'Not available' }}</td><td class="px-5 py-4"><Link :href="`/platform/subscriptions/${subscription.id}`" class="font-semibold text-[#0b8f5a]">View</Link></td></tr><tr v-if="props.subscriptions.length === 0"><td colspan="7" class="px-5 py-10 text-center text-[#71877b]">No subscriptions match these filters.</td></tr></tbody></table></section>
        <div v-if="props.pagination.last_page > 1" class="mt-5 flex gap-2"><Link v-for="number in props.pagination.last_page" :key="number" :href="`/platform/subscriptions?page=${number}`" class="rounded-lg px-3 py-2 text-sm" :class="number === props.pagination.current_page ? 'bg-[#10261f] text-white' : 'bg-white text-[#52695c]'">{{ number }}</Link></div>
    </PlatformLayout>
</template>
