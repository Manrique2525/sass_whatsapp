<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

const page = usePage();
const form = useForm({ tenant_name: '', owner_name: '', owner_email: '', confirm_existing_owner: false });
const submit = (): void => form.post('/platform/customers');
const error = (key: string): string | undefined => (form.errors as Record<string, string>)[key];
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <a href="/platform/customers" class="text-sm font-semibold text-[#0b8f5a]">← Back to Customers</a>
        <div class="mt-5"><p class="app-eyebrow">Platform operations</p><h1 class="mt-2 text-3xl font-semibold tracking-[-0.04em]">Create customer</h1><p class="mt-2 text-sm text-[#60766a]">Creates a Free tenant and Owner account. No paid plan or Stripe operation is involved.</p></div>
        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <section class="app-card grid gap-4 p-6 sm:grid-cols-2">
                <label><span class="app-label">Business / tenant name</span><input v-model="form.tenant_name" class="app-input" required /><p class="app-error">{{ error('tenant_name') }}</p></label>
                <label><span class="app-label">Owner name</span><input v-model="form.owner_name" class="app-input" required /><p class="app-error">{{ error('owner_name') }}</p></label>
                <label><span class="app-label">Owner email</span><input v-model="form.owner_email" type="email" class="app-input" required /><p class="app-error">{{ error('owner_email') }}</p></label>
                <label class="flex items-center gap-3 self-end text-sm text-[#52695c]"><input v-model="form.confirm_existing_owner" type="checkbox" class="h-5 w-5 rounded border-[#b9cabe] text-[#0b8f5a]" /> I confirm attaching an existing account as Owner if this email is already registered.</label>
            </section>
            <section class="app-card p-6"><h2 class="text-lg font-semibold">Provisioning summary</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-[#71877b]">Plan</dt><dd class="mt-1 font-medium">Free</dd></div><div><dt class="text-[#71877b]">Billing</dt><dd class="mt-1 font-medium">$0 / local subscription</dd></div><div><dt class="text-[#71877b]">Verification</dt><dd class="mt-1 font-medium">Required by email</dd></div><div><dt class="text-[#71877b]">Platform Admin membership</dt><dd class="mt-1 font-medium">None</dd></div></dl></section>
            <div class="flex justify-end"><button type="submit" class="app-button app-button--primary" :disabled="form.processing">Create customer</button></div>
        </form>
    </PlatformLayout>
</template>
