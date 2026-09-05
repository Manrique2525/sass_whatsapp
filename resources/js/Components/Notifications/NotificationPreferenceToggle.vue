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
    <div v-if="isOwnerOrAdmin" class="app-card p-4">
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-[#10261f]">Notificaciones por correo</h4>
                <p class="mt-0.5 text-xs leading-5 text-[#71877b]">
                    Recibir un correo cuando una conversación requiera atención humana.
                </p>
            </div>
            <button
                v-if="!isLoading"
                type="button"
                role="switch"
                :aria-checked="emailEnabled"
                :disabled="isSaving"
                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-[#10261f] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                :class="emailEnabled ? 'bg-[#0b8f5a]' : 'bg-[#dce8df]'"
                @click="togglePreference"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="emailEnabled ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>
        <div v-if="isLoading" class="mt-2">
            <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#cbdacf] border-t-[#0b8f5a]" />
        </div>
        <p v-if="error" class="app-alert app-alert--error mt-2 px-3 py-2 text-xs">{{ error }}</p>
        <p v-if="successMessage" class="app-alert app-alert--success mt-2 px-3 py-2 text-xs">{{ successMessage }}</p>
    </div>
</template>
