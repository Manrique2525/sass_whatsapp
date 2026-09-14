<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppSelect from '@/Components/AppSelect.vue';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

interface Plan { id: string; name: string; slug: string; price_monthly: string; price_yearly: string; limits: Record<string, number | null>; features: { ai_enabled?: boolean } }
interface Category { used: number; limit: number | null; remaining: number | null; percentage: number | null }
interface Subscription { id: string; plan: { id: string; name: string; slug: string }; provider: string; provider_subscription_id: string | null }
const page = usePage();
const props = defineProps<{ tenant: { id: string; name: string; slug: string }; subscription: Subscription | null; usage: { categories: Record<string, Category> } | null; plans: Plan[] }>();
const form = useForm({ plan_id: '', reason: '', current_password: '' });
const target = computed(() => props.plans.find((plan) => plan.id === form.plan_id) ?? null);
const current = computed(() => props.plans.find((plan) => plan.id === props.subscription?.plan.id) ?? null);
const risks = computed(() => Object.entries(props.usage?.categories ?? {}).filter(([category, value]) => { const targetLimit = target.value?.limits[category] ?? null; return targetLimit !== null && value.used > targetLimit; }));
const submit = (): void => { if (props.subscription?.provider === 'stripe' || !target.value || !window.confirm(`Change ${props.tenant.name} to ${target.value.name}? Existing data and usage history will be preserved.`)) return; form.patch(`/platform/customers/${props.tenant.id}/subscription/plan`); };
const error = (key: string): string | undefined => (form.errors as Record<string, string>)[key];
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <Link :href="`/platform/customers/${props.tenant.id}`" class="text-sm font-semibold text-[#0b8f5a]">← Back to customer</Link>
        <div class="mt-5"><p class="app-eyebrow">Subscription operation</p><h1 class="mt-2 text-3xl font-semibold">Change plan</h1><p class="mt-2 text-sm text-[#71877b]">{{ props.tenant.name }} · administrative change with audit trail.</p></div>
        <div v-if="!props.subscription" class="app-card mt-6 p-6"><h2 class="text-lg font-semibold">No subscription</h2><p class="mt-2 text-sm text-[#60766a]">This tenant has no active subscription. U5 does not create subscriptions from Platform Admin.</p></div>
        <form v-else class="mt-6 space-y-6" @submit.prevent="submit">
            <section class="app-card grid gap-4 p-6 sm:grid-cols-2"><div><p class="app-eyebrow">Current plan</p><p class="mt-2 text-lg font-semibold">{{ props.subscription.plan.name }}</p><p class="mt-1 text-sm text-[#71877b]">{{ props.subscription.provider === 'stripe' ? 'Provider-managed subscription' : 'Local/manual subscription' }}</p></div><div><p class="app-eyebrow">Target plan</p><AppSelect v-model="form.plan_id" :options="props.plans.map((plan) => ({ value: plan.id, label: `${plan.name} · ${plan.price_monthly}/month` }))" placeholder="Select a plan" required searchable aria-label="Target plan" /><p class="app-error">{{ error('plan_id') }}</p></div></section>
            <section v-if="props.subscription.provider === 'stripe'" class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-950"><h2 class="font-semibold">Provider-managed subscription</h2><p class="mt-2">This subscription is managed externally and cannot be changed locally. No Stripe request or local divergence will be created.</p></section>
            <section v-if="target && current" class="app-card p-6"><h2 class="text-lg font-semibold">Impact preview</h2><div class="mt-4 grid gap-4 sm:grid-cols-2"><div><p class="text-sm text-[#71877b]">Current</p><p class="mt-1 font-semibold">{{ current.name }} · {{ current.price_monthly }}/month</p></div><div><p class="text-sm text-[#71877b]">Target</p><p class="mt-1 font-semibold">{{ target.name }} · {{ target.price_monthly }}/month</p></div></div><p class="mt-4 text-sm text-[#60766a]">AI enabled: {{ current.features.ai_enabled ? 'Yes' : 'No' }} to {{ target.features.ai_enabled ? 'Yes' : 'No' }}.</p><div v-if="risks.length" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><p class="font-semibold">Usage exceeds one or more target limits.</p><p v-for="([category, value]) in risks" :key="category" class="mt-1">{{ category.replaceAll('_', ' ') }}: {{ value.used.toLocaleString() }} used / {{ target.limits[category]?.toLocaleString() }} allowed</p><p class="mt-2">No users, contacts, files, flows, or usage history will be deleted. Future operations remain subject to canonical entitlements.</p></div><p v-else class="mt-5 text-sm text-[#60766a]">Existing data and usage history are preserved. Future feature and quota checks use the target plan.</p></section>
             <label><span class="app-label">Reason for change</span><textarea v-model="form.reason" class="app-input min-h-24" placeholder="Support adjustment, commercial correction, or customer request" required /><p class="app-error">{{ error('reason') }}</p></label>
             <label><span class="app-label">Current password</span><input v-model="form.current_password" type="password" autocomplete="current-password" class="app-input" required /><p class="app-error">{{ error('current_password') }}</p></label>
            <div class="flex justify-end"><button type="submit" class="app-button app-button--primary" :disabled="form.processing || props.subscription.provider === 'stripe' || !target">{{ form.processing ? 'Changing...' : 'Confirm plan change' }}</button></div>
        </form>
    </PlatformLayout>
</template>
