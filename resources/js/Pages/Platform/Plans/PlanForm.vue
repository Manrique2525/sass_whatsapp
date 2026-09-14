<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import AppSelect from '@/Components/AppSelect.vue';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

const categories = ['messages', 'ai_tokens', 'contacts', 'flow_executions', 'users', 'knowledge_documents'];
const labels: Record<string, string> = { messages: 'Messages', ai_tokens: 'AI tokens', contacts: 'Contacts', flow_executions: 'Flow executions', users: 'Users', knowledge_documents: 'Knowledge documents' };
const props = withDefaults(defineProps<{ plan?: Record<string, any>; mode: 'create' | 'edit' }>(), { plan: undefined });
const page = usePage();
const form = useForm({
    slug: props.plan?.slug ?? '', name: props.plan?.name ?? '', description: props.plan?.description ?? '',
    is_active: props.plan?.is_active ?? true, price_monthly: props.plan?.price_monthly ?? '0.00', price_yearly: props.plan?.price_yearly ?? '0.00',
    stripe_price_id_monthly: props.plan?.stripe_price_id_monthly ?? '', stripe_price_id_yearly: props.plan?.stripe_price_id_yearly ?? '',
    limits: Object.fromEntries(categories.map((key) => [key, props.plan?.limits?.[key] ?? (props.mode === 'edit' ? null : 0)])),
    features: { ai_enabled: props.plan?.features?.ai_enabled ?? false }, sort_order: props.plan?.sort_order ?? 0, reason: '', current_password: '',
});
const submit = (): void => { props.mode === 'create' ? form.post('/platform/plans') : form.patch(`/platform/plans/${props.plan?.id}`); };
const error = (key: string): string | undefined => (form.errors as Record<string, string>)[key];
const setUnlimited = (category: string, event: Event): void => {
    form.limits[category] = (event.target as HTMLInputElement).checked ? null : 0;
};
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <a href="/platform/plans" class="text-sm font-semibold text-[#0b8f5a]">← Back to Plans</a>
         <div class="mt-5"><p class="app-eyebrow">Platform catalog</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">{{ props.mode === 'create' ? 'Create plan' : 'Edit plan' }}</h1><p class="mt-2 text-sm text-[#60766a]">Direct catalog pricing only. No currency field or Stripe network operation is performed.</p><p v-if="props.mode === 'edit' && ((props.plan?.impact?.active_subscriptions ?? 0) + (props.plan?.impact?.past_due_subscriptions ?? 0)) > 0" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This plan affects {{ props.plan?.impact?.active_subscriptions ?? 0 }} active and {{ props.plan?.impact?.past_due_subscriptions ?? 0 }} past-due subscribers. Existing data is never deleted.</p></div>
        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <section class="app-card grid gap-4 p-6 sm:grid-cols-2">
                <label><span class="app-label">Name</span><input v-model="form.name" class="app-input" required /><p class="app-error">{{ error('name') }}</p></label>
                 <label><span class="app-label">Slug</span><input v-model="form.slug" class="app-input" required :disabled="props.mode === 'edit'" /><p class="app-error">{{ error('slug') }}</p></label>
                <label class="sm:col-span-2"><span class="app-label">Description</span><textarea v-model="form.description" class="app-input min-h-24" /><p class="app-error">{{ error('description') }}</p></label>
                 <label><span class="app-label">Monthly price</span><input v-model="form.price_monthly" type="number" min="0" step="0.01" class="app-input" required :disabled="props.plan?.slug === 'free'" /><p class="app-error">{{ error('price_monthly') }}</p></label>
                 <label><span class="app-label">Yearly price</span><input v-model="form.price_yearly" type="number" min="0" step="0.01" class="app-input" required :disabled="props.plan?.slug === 'free'" /><p class="app-error">{{ error('price_yearly') }}</p></label>
                <label><span class="app-label">Sort order</span><input v-model="form.sort_order" type="number" min="0" class="app-input" required /></label>
                <div><span class="app-label">Status</span><AppSelect v-model="form.is_active" :options="[{ value: true, label: 'Active' }, { value: false, label: 'Inactive' }]" required aria-label="Plan status" /></div>
                <label><span class="app-label">Stripe monthly price ID</span><input v-model="form.stripe_price_id_monthly" class="app-input" /></label>
                <label><span class="app-label">Stripe yearly price ID</span><input v-model="form.stripe_price_id_yearly" class="app-input" /></label>
            </section>
             <section class="app-card p-6"><h2 class="text-lg font-semibold">Limits</h2><p class="mt-1 text-sm text-[#60766a]">Changes affect all active and past-due subscribers on this plan. Existing data is never deleted.</p><div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><label v-for="category in categories" :key="category"><span class="app-label">{{ labels[category] }}</span><input v-model="form.limits[category]" type="number" min="0" class="app-input" :required="form.limits[category] !== null" :disabled="props.plan?.slug === 'free' || form.limits[category] === null" /><span class="mt-2 flex items-center gap-2 text-xs text-[#60766a]"><input type="checkbox" :checked="form.limits[category] === null" :disabled="props.plan?.slug === 'free'" @change="setUnlimited(category, $event)" /> Unlimited</span></label></div></section>
            <section class="app-card flex items-center justify-between gap-4 p-6"><div><h2 class="text-lg font-semibold">AI feature</h2><p class="mt-1 text-sm text-[#60766a]">The only configurable feature entitlement is `ai_enabled`.</p></div><label class="flex items-center gap-3"><input v-model="form.features.ai_enabled" type="checkbox" class="h-5 w-5 rounded border-[#b9cabe] text-[#0b8f5a]" /><span class="text-sm font-medium">AI enabled</span></label></section>
              <label v-if="props.mode === 'edit'"><span class="app-label">Reason for change</span><textarea v-model="form.reason" class="app-input min-h-20" placeholder="Required for pricing, feature, limit, or status changes." /><p class="app-error">{{ error('reason') }}</p></label>
              <label v-if="props.mode === 'edit'"><span class="app-label">Current password</span><input v-model="form.current_password" type="password" autocomplete="current-password" class="app-input" required /><p class="app-error">{{ error('current_password') }}</p></label>
             <div class="flex justify-end"><button type="submit" class="app-button app-button--primary" :disabled="form.processing">{{ props.mode === 'create' ? 'Create plan' : 'Save changes' }}</button></div>
        </form>
    </PlatformLayout>
</template>
