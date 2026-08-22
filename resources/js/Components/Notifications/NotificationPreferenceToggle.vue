<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { fetchNotificationPreference, updateNotificationPreference } from '@/features/notifications/notificationApi';

const page = usePage();
const tenantId = computed(() => page.props.auth.current_tenant_id as string | null);
const currentRole = computed(() => page.props.auth.current_role as string | null);

const isOwnerOrAdmin = computed(() =>
    currentRole.value === 'owner' || currentRole.value === 'admin',
);

const emailEnabled = ref(false);
const isLoading = ref(true);
const isSaving = ref(false);
const error = ref<string | null>(null);
const successMessage = ref<string | null>(null);

async function loadPreference(): Promise<void> {
    if (tenantId.value === null || !isOwnerOrAdmin.value) {
        isLoading.value = false;
        return;
    }

    isLoading.value = true;

    try {
        const pref = await fetchNotificationPreference(tenantId.value);
        emailEnabled.value = pref.email_notifications_enabled;
    } catch {
        error.value = 'No se pudo cargar la preferencia.';
    } finally {
        isLoading.value = false;
    }
}

async function togglePreference(): Promise<void> {
    if (tenantId.value === null || isSaving.value) return;

    isSaving.value = true;
    error.value = null;
    successMessage.value = null;

    try {
        const newValue = !emailEnabled.value;
        await updateNotificationPreference(tenantId.value, newValue);
        emailEnabled.value = newValue;
        successMessage.value = newValue
            ? 'Notificaciones por correo activadas.'
            : 'Notificaciones por correo desactivadas.';
    } catch {
        error.value = 'No se pudo actualizar la preferencia.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadPreference);
</script>

<template>
    <div v-if="isOwnerOrAdmin" class="rounded-lg border border-zinc-200 bg-white p-4">
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-zinc-900">Notificaciones por correo</h4>
                <p class="mt-0.5 text-xs text-zinc-500">
                    Recibir un correo cuando una conversación requiera atención humana.
                </p>
            </div>
            <button
                v-if="!isLoading"
                type="button"
                role="switch"
                :aria-checked="emailEnabled"
                :disabled="isSaving"
                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                :class="emailEnabled ? 'bg-indigo-600' : 'bg-zinc-200'"
                @click="togglePreference"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="emailEnabled ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>
        <div v-if="isLoading" class="mt-2">
            <div class="h-4 w-4 animate-spin rounded-full border-2 border-zinc-300 border-t-indigo-600" />
        </div>
        <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>
        <p v-if="successMessage" class="mt-2 text-xs text-green-600">{{ successMessage }}</p>
    </div>
</template>
