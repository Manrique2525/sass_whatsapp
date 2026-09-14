<script setup lang="ts">
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

const page = usePage();
const props = defineProps<{ enabled: boolean; enrollment: { secret: string; uri: string } | null }>();
const enrollment = useForm({ code: '' });
const challenge = useForm({ code: '' });
const action = useForm({ password: '', factor: '' });
const enrollmentPassword = ref('');
const showCodes = ref(false);
const start = (): void => { if (page.props.auth.is_super_admin) useForm({ password: enrollmentPassword.value }).post('/platform/security/enroll'); };
const confirm = (): void => enrollment.post('/platform/security/enroll/confirm');
const verify = (): void => challenge.post('/platform/security/challenge');
const disable = (): void => { if (window.confirm('Disable platform MFA?')) action.post('/platform/security/disable'); };
const regenerate = (): void => action.post('/platform/security/recovery-codes');
</script>

<template>
    <PlatformLayout :user="page.props.auth.user">
        <p class="app-eyebrow">Platform security</p>
        <h1 class="mt-2 text-3xl font-semibold">Multi-factor authentication</h1>
        <p class="mt-2 text-sm text-[#60766a]">Protects platform administration independently from tenant access.</p>
        <section v-if="!props.enabled && !props.enrollment" class="app-card mt-6 p-6">
            <h2 class="text-lg font-semibold">MFA is not enabled</h2><p class="mt-2 text-sm text-[#60766a]">Use an authenticator app to protect high-impact platform actions.</p>
            <div class="mt-5 flex flex-col gap-3 sm:flex-row"><input v-model="enrollmentPassword" type="password" autocomplete="current-password" class="app-input" placeholder="Current password" required /><button class="app-button app-button--primary" type="button" :disabled="!enrollmentPassword" @click="start">Begin enrollment</button></div>
        </section>
        <section v-if="props.enrollment" class="app-card mt-6 space-y-4 p-6">
            <h2 class="text-lg font-semibold">Scan this local authenticator setup</h2>
            <p class="break-all rounded-xl bg-[#f0f5ef] p-3 font-mono text-xs">{{ props.enrollment.uri }}</p>
            <p class="text-sm">Manual key: <strong>{{ props.enrollment.secret }}</strong></p>
            <form class="flex gap-3" @submit.prevent="confirm"><input v-model="enrollment.code" class="app-input" inputmode="numeric" maxlength="6" placeholder="6-digit code" required /><button class="app-button app-button--primary" :disabled="enrollment.processing">Confirm</button></form>
        </section>
        <section v-if="props.enabled" class="app-card mt-6 space-y-5 p-6">
            <h2 class="text-lg font-semibold">MFA enabled</h2>
            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="regenerate"><input v-model="action.password" type="password" autocomplete="current-password" class="app-input" placeholder="Current password" required /><input v-model="action.factor" class="app-input" placeholder="Current TOTP or recovery code" required /><button class="app-button app-button--secondary" :disabled="action.processing">Regenerate codes</button></form>
            <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="disable"><input v-model="action.password" type="password" autocomplete="current-password" class="app-input" placeholder="Current password" required /><input v-model="action.factor" class="app-input" placeholder="Current TOTP or recovery code" required /><button class="app-button app-button--secondary" :disabled="action.processing">Disable MFA</button></form>
        </section>
        <section v-if="page.props.flash.recovery_codes" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-950">
            <h2 class="font-semibold">Save these recovery codes now</h2><p class="mt-2 text-sm">They are shown once and cannot be retrieved later.</p>
            <pre v-if="!showCodes" class="mt-3 select-all whitespace-pre-wrap font-mono text-sm">{{ page.props.flash.recovery_codes.join('\n') }}</pre>
            <button v-else type="button" class="mt-3 text-sm underline" @click="showCodes = false">Hide codes</button>
        </section>
        <section v-if="props.enabled && page.url.endsWith('/challenge')" class="app-card mt-6 p-6"><h2 class="text-lg font-semibold">Verify your authenticator</h2><form class="mt-4 flex gap-3" @submit.prevent="verify"><input v-model="challenge.code" class="app-input" placeholder="TOTP or recovery code" required /><button class="app-button app-button--primary">Continue</button></form></section>
    </PlatformLayout>
</template>
