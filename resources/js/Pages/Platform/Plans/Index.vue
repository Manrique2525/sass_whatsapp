<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
const page = usePage();
const props = defineProps<{ plans: Record<string, any>[] }>();
</script>
<template>
    <PlatformLayout :user="page.props.auth.user">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="app-eyebrow">Platform Admin</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">Plans</h1><p class="mt-2 text-sm text-[#60766a]">Global catalog, with subscriber impact visibility and no destructive actions.</p></div><Link href="/platform/plans/create" class="app-button app-button--primary">Create plan</Link></div>
        <section class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3"><article v-for="plan in props.plans" :key="plan.id" class="app-card p-6"><div class="flex items-start justify-between gap-3"><div><Link :href="`/platform/plans/${plan.id}`" class="text-lg font-semibold hover:text-[#0b8f5a]">{{ plan.name }}</Link><p class="mt-1 text-xs text-[#71877b]">{{ plan.slug }}</p></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="plan.is_active ? 'bg-[#eff9e8] text-[#176b42]' : 'bg-zinc-100 text-zinc-600'">{{ plan.is_active ? 'Active' : 'Inactive' }}</span></div><p class="mt-5 text-2xl font-semibold">{{ plan.price_monthly }} <span class="text-sm font-normal text-[#71877b]">monthly</span></p><p class="mt-3 text-sm text-[#60766a]">{{ plan.impact.active_subscriptions }} active · {{ plan.impact.past_due_subscriptions }} past due</p><Link :href="`/platform/plans/${plan.id}/edit`" class="mt-5 inline-block text-sm font-semibold text-[#0b8f5a]">Edit plan</Link></article></section>
        <section v-if="props.plans.length === 0" class="app-card mt-6 p-10 text-center"><p class="text-sm text-[#60766a]">No plans configured.</p></section>
    </PlatformLayout>
</template>
