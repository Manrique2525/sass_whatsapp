<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppSelect from '@/Components/AppSelect.vue';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

interface Customer {
    id: string;
    name: string;
    slug: string;
    status: string;
    created_at: string;
    owner: { name: string; email: string } | null;
    plan: { id: string; name: string; slug: string } | null;
    subscription_status: string | null;
    users_count: number;
    whatsapp_status: string | null;
    usage: null;
    metrics: { contacts: number; conversations: number; messages: number; last_activity_at: string | null };
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface PlanOption {
    id: string;
    name: string;
}

const page = usePage();
const props = defineProps<{
    customers: Customer[];
    pagination: Pagination;
    filters: Record<string, string | number | undefined>;
    plans: PlanOption[];
}>();

const filters = reactive({
    search: String(props.filters.search ?? ''),
    plan_id: String(props.filters.plan_id ?? ''),
    subscription_status: String(props.filters.subscription_status ?? ''),
    status: String(props.filters.status ?? ''),
    sort: String(props.filters.sort ?? 'created_at'),
    direction: String(props.filters.direction ?? 'desc'),
});

const selectOptions = (items: { value: string; label: string }[], emptyLabel: string) => [
    { value: '', label: emptyLabel },
    ...items,
];

const applyFilters = (): void => {
    router.get('/platform/customers', {
        ...filters,
        page: 1,
    }, { preserveState: true, replace: true });
};

const goToPage = (pageNumber: number): void => {
    if (pageNumber < 1 || pageNumber > props.pagination.last_page) return;
    router.get('/platform/customers', { ...filters, page: pageNumber }, { preserveState: true, replace: true });
};

const formatDate = (value: string | null): string => value
    ? new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value))
    : 'No activity';

const label = (value: string | null): string => ({
    active: 'Active',
    past_due: 'Past due',
    pending: 'Pending',
    cancelled: 'Cancelled',
    connected: 'Connected',
    disconnected: 'Not connected',
    suspended: 'Suspended',
}[value ?? ''] ?? value ?? 'No subscription');

const badgeClass = (value: string | null): string => ({
    active: 'bg-[#eff9e8] text-[#176b42]',
    connected: 'bg-[#eff9e8] text-[#176b42]',
    past_due: 'bg-amber-50 text-amber-800',
    suspended: 'bg-red-50 text-red-700',
    disconnected: 'bg-zinc-100 text-zinc-600',
}[value ?? ''] ?? 'bg-zinc-100 text-zinc-600');
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="app-eyebrow">Platform Admin</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">Customers</h1>
                <p class="mt-2 text-sm text-[#60766a]">A read-only view of every tenant in the platform.</p>
            </div>
            <div class="flex items-center gap-4"><p class="text-sm text-[#71877b]" data-testid="customers-total">{{ props.pagination.total }} customers</p><Link href="/platform/customers/create" class="app-button app-button--primary">Create customer</Link></div>
        </div>

        <section class="app-card mt-6 p-4 sm:p-5">
            <form class="grid gap-3 md:grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,180px))_auto]" @submit.prevent="applyFilters">
                <label class="block">
                    <span class="sr-only">Search customers</span>
                    <input v-model="filters.search" type="search" class="app-input" placeholder="Search by customer or owner" aria-label="Search customers" />
                </label>
                <AppSelect v-model="filters.plan_id" :options="selectOptions(props.plans.map((plan) => ({ value: plan.id, label: plan.name })), 'All plans')" clearable aria-label="Filter by plan" />
                <AppSelect v-model="filters.subscription_status" :options="selectOptions([
                    { value: 'active', label: 'Active' },
                    { value: 'past_due', label: 'Past due' },
                    { value: 'pending', label: 'Pending' },
                ], 'All subscriptions')" clearable aria-label="Filter by subscription status" />
                <AppSelect v-model="filters.status" :options="selectOptions([
                    { value: 'active', label: 'Active' },
                    { value: 'suspended', label: 'Suspended' },
                ], 'All tenant statuses')" clearable aria-label="Filter by tenant status" />
                <button type="submit" class="app-button app-button--primary">Search</button>
            </form>
        </section>

        <section v-if="props.customers.length > 0" class="mt-6">
            <div class="hidden overflow-hidden rounded-2xl border border-[#dce8df] bg-white md:block">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Platform customers</caption>
                    <thead class="border-b border-[#edf2ec] bg-[#fbfdfb] text-xs uppercase tracking-[0.12em] text-[#71877b]">
                        <tr>
                            <th scope="col" class="px-5 py-4">Customer</th>
                            <th scope="col" class="px-5 py-4">Owner</th>
                            <th scope="col" class="px-5 py-4">Plan</th>
                            <th scope="col" class="px-5 py-4">Subscription</th>
                            <th scope="col" class="px-5 py-4">Users</th>
                            <th scope="col" class="px-5 py-4">WhatsApp</th>
                            <th scope="col" class="px-5 py-4">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#edf2ec]">
                        <tr v-for="customer in props.customers" :key="customer.id" class="hover:bg-[#fbfdfb]">
                            <td class="px-5 py-4"><Link :href="`/platform/customers/${customer.id}`" class="font-semibold text-[#10261f] hover:text-[#0b8f5a]">{{ customer.name }}</Link><p class="mt-1 text-xs text-[#71877b]">{{ customer.slug }}</p></td>
                            <td class="px-5 py-4"><p>{{ customer.owner?.name ?? 'No owner' }}</p><p class="mt-1 text-xs text-[#71877b]">{{ customer.owner?.email ?? '—' }}</p></td>
                            <td class="px-5 py-4">{{ customer.plan?.name ?? 'No subscription' }}</td>
                            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgeClass(customer.subscription_status)">{{ label(customer.subscription_status) }}</span></td>
                            <td class="px-5 py-4">{{ customer.users_count }}</td>
                            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgeClass(customer.whatsapp_status)">{{ label(customer.whatsapp_status) }}</span></td>
                            <td class="whitespace-nowrap px-5 py-4 text-[#60766a]">{{ formatDate(customer.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:hidden">
                <article v-for="customer in props.customers" :key="customer.id" class="app-card p-5">
                    <div class="flex items-start justify-between gap-3"><div><Link :href="`/platform/customers/${customer.id}`" class="font-semibold text-[#10261f]">{{ customer.name }}</Link><p class="mt-1 text-xs text-[#71877b]">{{ customer.owner?.email ?? 'No owner' }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgeClass(customer.status)">{{ label(customer.status) }}</span></div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-[#71877b]">Plan</dt><dd class="mt-1 font-medium">{{ customer.plan?.name ?? 'No subscription' }}</dd></div><div><dt class="text-xs text-[#71877b]">Users</dt><dd class="mt-1 font-medium">{{ customer.users_count }}</dd></div><div><dt class="text-xs text-[#71877b]">Subscription</dt><dd class="mt-1">{{ label(customer.subscription_status) }}</dd></div><div><dt class="text-xs text-[#71877b]">WhatsApp</dt><dd class="mt-1">{{ label(customer.whatsapp_status) }}</dd></div></dl>
                </article>
            </div>

            <nav v-if="props.pagination.last_page > 1" class="mt-5 flex items-center justify-between gap-3" aria-label="Customer pagination">
                <button type="button" class="app-button app-button--secondary" :disabled="props.pagination.current_page === 1" @click="goToPage(props.pagination.current_page - 1)">Previous</button>
                <span class="text-sm text-[#71877b]">Page {{ props.pagination.current_page }} of {{ props.pagination.last_page }}</span>
                <button type="button" class="app-button app-button--secondary" :disabled="props.pagination.current_page === props.pagination.last_page" @click="goToPage(props.pagination.current_page + 1)">Next</button>
            </nav>
        </section>

        <section v-else class="app-card mt-6 p-10 text-center">
            <h2 class="text-lg font-semibold">No customers found</h2>
            <p class="mt-2 text-sm text-[#60766a]">Try adjusting the search or filters.</p>
        </section>
    </PlatformLayout>
</template>
