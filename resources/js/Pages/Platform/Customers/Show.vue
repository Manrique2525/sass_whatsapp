<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

interface Customer {
    id: string;
    name: string;
    slug: string;
    status: string;
    created_at: string;
    updated_at: string;
    owner: { id: number; name: string; email: string; email_verified: boolean } | null;
    plan: { id: string; name: string; slug: string } | null;
    subscription: {
        id: string;
        status: string;
        created_at: string;
        current_period_start: string | null;
        current_period_end: string | null;
        cancel_at_period_end: boolean;
        provider: string | null;
        provider_subscription_id: string | null;
    } | null;
    metrics: { users: number; contacts: number; conversations: number; messages: number; last_activity_at: string | null };
    usage: { period_start: string; period_end: string; categories: Record<string, { used: number; limit: number | null; remaining: number | null; percentage: number | null }> } | null;
    users: { id: number; name: string; email: string; role: string; status: string; joined_at: string | null }[];
    whatsapp: { account: { id: string; display_name: string | null; status: string; updated_at: string } | null; phones: { id: string; display_phone_number: string | null; verified_name: string | null; quality_rating: string | null; status: string; is_default: boolean }[] };
    audit: { items: { id: string; action: string; subject_type: string | null; subject_id: string | null; created_at: string; actor_name: string | null }[]; pagination: { current_page: number; last_page: number; total: number } };
}

const page = usePage();
const props = defineProps<{ customer: Customer }>();

const formatDate = (value: string | null): string => value
    ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : 'Not available';

const label = (value: string | null): string => ({
    active: 'Active', past_due: 'Past due', pending: 'Pending', cancelled: 'Cancelled',
    connected: 'Connected', disconnected: 'Not connected', suspended: 'Suspended',
}[value ?? ''] ?? value ?? 'Not available');

const maskLimit = (limit: number | null): string => limit === null ? 'Unlimited' : limit.toLocaleString();
const action = (url: string, message: string, requiresReason = false): void => {
    if (!window.confirm(message)) return;
    const reason = requiresReason ? window.prompt('Reason for this administrative action:')?.trim() : undefined;
    if (requiresReason && !reason) return;
    useForm({ confirm: true, reason: reason ?? '' }).post(url);
};
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <Link href="/platform/customers" class="text-sm font-semibold text-[#0b8f5a] hover:text-[#10261f]">← Back to Customers</Link>
        <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
            <div><p class="app-eyebrow">Customer detail</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">{{ props.customer.name }}</h1><p class="mt-2 text-sm text-[#71877b]">{{ props.customer.slug }} · Created {{ formatDate(props.customer.created_at) }}</p></div>
            <div class="flex flex-wrap items-center gap-3"><span class="rounded-full px-3 py-1.5 text-xs font-semibold" :class="props.customer.status === 'suspended' ? 'bg-red-50 text-red-700' : 'bg-[#eff9e8] text-[#176b42]'">{{ label(props.customer.status) }}</span><button v-if="props.customer.status === 'active'" type="button" class="app-button app-button--secondary" @click="action(`/platform/customers/${props.customer.id}/suspend`, 'Suspend this tenant? Users lose operational access, data is preserved, and the subscription remains.', true)">Suspend tenant</button><button v-else type="button" class="app-button app-button--primary" @click="action(`/platform/customers/${props.customer.id}/reactivate`, 'Reactivate this tenant?', true)">Reactivate tenant</button></div>
        </div>

        <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="app-card p-5"><p class="app-eyebrow">Owner</p><p class="mt-2 font-semibold">{{ props.customer.owner?.name ?? 'No owner' }}</p><p class="mt-1 break-all text-sm text-[#71877b]">{{ props.customer.owner?.email ?? '—' }}</p><p v-if="props.customer.owner" class="mt-2 text-xs font-semibold" :class="props.customer.owner.email_verified ? 'text-[#176b42]' : 'text-amber-700'">Email {{ props.customer.owner.email_verified ? 'verified' : 'not verified' }}</p><div v-if="props.customer.owner" class="mt-4 flex flex-wrap gap-2"><button v-if="!props.customer.owner.email_verified" type="button" class="app-button app-button--secondary" @click="action(`/platform/customers/${props.customer.id}/owner/resend-verification`, 'Resend the owner verification email?')">Resend verification</button><button v-else type="button" class="app-button app-button--secondary" @click="action(`/platform/customers/${props.customer.id}/owner/send-password-reset`, 'Send a password reset email to the owner?')">Send password reset</button></div></div>
            <div class="app-card p-5"><p class="app-eyebrow">Plan</p><p class="mt-2 font-semibold">{{ props.customer.plan?.name ?? 'No subscription' }}</p><p class="mt-1 text-sm text-[#71877b]">{{ label(props.customer.subscription?.status ?? null) }}</p><Link v-if="props.customer.subscription" :href="`/platform/customers/${props.customer.id}/subscription/edit`" class="mt-3 inline-block text-sm font-semibold text-[#0b8f5a]">Change plan</Link><button v-else type="button" class="mt-3 block text-sm font-semibold text-[#0b8f5a]" @click="action(`/platform/customers/${props.customer.id}/subscription/free`, 'Create the canonical Free subscription for this tenant?')">Create Free subscription</button></div>
            <div class="app-card p-5"><p class="app-eyebrow">Users</p><p class="mt-2 text-2xl font-semibold">{{ props.customer.metrics.users }}</p><p class="mt-1 text-sm text-[#71877b]">Active members</p></div>
            <div class="app-card p-5"><p class="app-eyebrow">Last activity</p><p class="mt-2 font-semibold">{{ formatDate(props.customer.metrics.last_activity_at) }}</p><p class="mt-1 text-sm text-[#71877b]">Conversation activity</p></div>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="app-card p-6"><div class="flex flex-wrap items-start justify-between gap-3"><h2 class="text-lg font-semibold">Subscription</h2><Link v-if="props.customer.subscription" :href="`/platform/customers/${props.customer.id}/subscription/edit`" class="app-button app-button--secondary">Change plan</Link></div><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-[#71877b]">Status</dt><dd class="mt-1 font-medium">{{ label(props.customer.subscription?.status ?? null) }}</dd></div><div><dt class="text-[#71877b]">Provider</dt><dd class="mt-1 font-medium">{{ props.customer.subscription?.provider ?? 'Not connected' }}</dd></div><div><dt class="text-[#71877b]">Current period</dt><dd class="mt-1 font-medium">{{ formatDate(props.customer.subscription?.current_period_start ?? null) }} to {{ formatDate(props.customer.subscription?.current_period_end ?? null) }}</dd></div><div><dt class="text-[#71877b]">Cancellation</dt><dd class="mt-1 font-medium">{{ props.customer.subscription?.cancel_at_period_end ? 'At period end' : 'Not scheduled' }}</dd></div></dl><p v-if="props.customer.subscription?.provider_subscription_id" class="mt-4 text-xs text-[#71877b]">Provider reference: {{ props.customer.subscription.provider_subscription_id }}</p><p v-if="!props.customer.subscription" class="mt-4 text-sm text-[#60766a]">No subscription is available for administrative plan changes.</p></section>

            <section class="app-card p-6"><h2 class="text-lg font-semibold">Operational metrics</h2><dl class="mt-4 grid grid-cols-2 gap-4 text-sm"><div><dt class="text-[#71877b]">Contacts</dt><dd class="mt-1 text-xl font-semibold">{{ props.customer.metrics.contacts }}</dd></div><div><dt class="text-[#71877b]">Conversations</dt><dd class="mt-1 text-xl font-semibold">{{ props.customer.metrics.conversations }}</dd></div><div><dt class="text-[#71877b]">Messages</dt><dd class="mt-1 text-xl font-semibold">{{ props.customer.metrics.messages }}</dd></div><div><dt class="text-[#71877b]">WhatsApp</dt><dd class="mt-1 font-semibold">{{ label(props.customer.whatsapp.account?.status ?? null) }}</dd></div></dl></section>
        </div>

        <section class="app-card mt-6 p-6"><div class="flex flex-wrap items-baseline justify-between gap-3"><div><h2 class="text-lg font-semibold">Usage</h2><p v-if="props.customer.usage" class="mt-1 text-sm text-[#71877b]">{{ formatDate(props.customer.usage.period_start) }} to {{ formatDate(props.customer.usage.period_end) }}</p></div></div><div v-if="props.customer.usage" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><div v-for="(item, category) in props.customer.usage.categories" :key="category" class="rounded-xl bg-[#f5f8f4] p-4"><div class="flex justify-between gap-3"><p class="text-sm font-medium capitalize">{{ String(category).replaceAll('_', ' ') }}</p><p class="text-xs text-[#71877b]">{{ item.percentage === null ? '—' : `${item.percentage}%` }}</p></div><p class="mt-2 text-xl font-semibold">{{ item.used.toLocaleString() }} <span class="text-sm font-normal text-[#71877b]">/ {{ maskLimit(item.limit) }}</span></p></div></div><p v-else class="mt-4 text-sm text-[#60766a]">No active subscription usage is available.</p></section>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="app-card p-6"><h2 class="text-lg font-semibold">Users</h2><div class="mt-4 divide-y divide-[#edf2ec]"><div v-for="member in props.customer.users" :key="member.id" class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0"><div><p class="font-medium">{{ member.name }}</p><p class="text-sm text-[#71877b]">{{ member.email }}</p></div><span class="text-xs font-semibold uppercase tracking-[0.1em] text-[#71877b]">{{ member.role }}</span></div></div></section>
            <section class="app-card p-6"><h2 class="text-lg font-semibold">WhatsApp</h2><div v-if="props.customer.whatsapp.account" class="mt-4"><p class="font-medium">{{ props.customer.whatsapp.account.display_name ?? 'WhatsApp Business account' }}</p><p class="mt-1 text-sm text-[#71877b]">{{ label(props.customer.whatsapp.account.status) }} · Updated {{ formatDate(props.customer.whatsapp.account.updated_at) }}</p><div class="mt-4 divide-y divide-[#edf2ec]"><div v-for="phone in props.customer.whatsapp.phones" :key="phone.id" class="py-3 first:pt-0"><p class="font-medium">{{ phone.display_phone_number ?? 'Phone not available' }}</p><p class="text-sm text-[#71877b]">{{ phone.verified_name ?? 'Unverified' }} · {{ label(phone.status) }}</p></div></div></div><p v-else class="mt-4 text-sm text-[#60766a]">No WhatsApp account connected.</p></section>
        </div>

         <section class="app-card mt-6 p-6"><h2 class="text-lg font-semibold">Activity</h2><p class="mt-1 text-sm text-[#71877b]">Safe audit metadata only. Payload contents are not exposed.</p><div v-if="props.customer.audit.items.length > 0" class="mt-4 divide-y divide-[#edf2ec]"><div v-for="entry in props.customer.audit.items" :key="entry.id" class="flex flex-wrap justify-between gap-2 py-3 first:pt-0"><div><p class="font-medium">{{ entry.action }}</p><p class="text-xs text-[#71877b]">{{ entry.actor_name ?? 'System' }}<span v-if="entry.subject_type"> · {{ entry.subject_type }}</span></p></div><time class="text-xs text-[#71877b]">{{ formatDate(entry.created_at) }}</time></div></div><p v-else class="mt-4 text-sm text-[#60766a]">No audit activity recorded.</p></section>
    </PlatformLayout>
</template>
