<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

interface Props {
    token: string;
}

const props = defineProps<Props>();

const page = usePage();
const isAuthenticated = page.props.auth.user !== null;

const errorMessage = (err: unknown, fallback: string): string => {
    if (
        typeof err === 'object' &&
        err !== null &&
        'response' in err &&
        typeof err.response === 'object' &&
        err.response !== null &&
        'data' in err.response &&
        typeof err.response.data === 'object' &&
        err.response.data !== null &&
        'message' in err.response.data &&
        typeof err.response.data.message === 'string'
    ) {
        return err.response.data.message;
    }

    return fallback;
};

const loading = ref(true);
const error = ref<string | null>(null);
const accepted = ref(false);
const sending = ref(false);

const tenantName = ref<string | null>(null);
const role = ref<string | null>(null);
const email = ref<string | null>(null);

const load = async (): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/invitations/${props.token}`);
        tenantName.value = res.data.tenant?.name ?? null;
        role.value = res.data.role ?? null;
        email.value = res.data.email ?? null;
    } catch (err) {
        error.value = errorMessage(err, 'La invitación no es válida.');
    } finally {
        loading.value = false;
    }
};

const accept = async (): Promise<void> => {
    sending.value = true;
    error.value = null;

    try {
        await window.axios.post(`/api/v1/invitations/${props.token}/accept`);
        accepted.value = true;
    } catch (err) {
        error.value = errorMessage(err, 'No se pudo aceptar la invitación.');
    } finally {
        sending.value = false;
    }
};

onMounted(load);
</script>

<template>
    <AuthLayout title="Invitación">
        <p v-if="loading" class="text-sm text-zinc-500">Cargando invitación...</p>

        <div v-else-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ error }}
        </div>

        <div v-else-if="accepted" class="space-y-4 text-center">
            <p class="text-sm text-zinc-700">Invitación aceptada. Ya eres parte de
                <span class="font-semibold text-zinc-900">{{ tenantName }}</span>.</p>
            <a
                href="/dashboard"
                class="inline-block rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
            >
                Ir al dashboard
            </a>
        </div>

        <div v-else class="space-y-4">
            <p class="text-sm text-zinc-700">
                Has sido invitado a unirte a
                <span class="font-semibold text-zinc-900">{{ tenantName }}</span>
                como <span class="font-medium text-zinc-900">{{ role }}</span>.
            </p>
            <p class="text-xs text-zinc-500">Invitación enviada a {{ email }}.</p>

            <button
                v-if="isAuthenticated"
                type="button"
                :disabled="sending"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                @click="accept"
            >
                Aceptar invitación
            </button>

            <p v-else class="text-sm text-zinc-600">
                Para aceptar la invitación debes
                <a href="/login" class="font-medium text-emerald-700 hover:underline">iniciar sesión</a>
                con el email <span class="font-medium text-zinc-800">{{ email }}</span>.
            </p>
        </div>
    </AuthLayout>
</template>
