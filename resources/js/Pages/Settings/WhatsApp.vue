<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface WhatsAppPhoneNumber {
    id: string;
    phone_id: string;
    display_phone_number: string | null;
    verified_name: string | null;
    quality_rating: string | null;
    status: string;
    is_default: boolean;
}

interface WhatsAppAccount {
    id: string;
    whatsapp_business_account_id: string | null;
    display_name: string | null;
    status: string;
    phone_numbers: WhatsAppPhoneNumber[];
}

interface ConnectPayload {
    whatsapp_business_account_id: string;
    phone_number_id: string;
    phone_number: string;
    display_name: string;
    access_token: string;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canManage = computed(() => can('whatsapp.manage'));

const loading = ref(true);
const connecting = ref(false);
const disconnecting = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const account = ref<WhatsAppAccount | null>(null);
const webhookSubscribed = ref(false);

const form = ref<ConnectPayload>({
    whatsapp_business_account_id: '',
    phone_number_id: '',
    phone_number: '',
    display_name: '',
    access_token: '',
});

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

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/whatsapp`);
        account.value = res.data.whatsapp_account;
    } catch (err) {
        error.value = errorMessage(err, 'No se pudo cargar el estado de WhatsApp.');
    } finally {
        loading.value = false;
    }
};

const connect = (): void => {
    if (!tenantId) {
        return;
    }

    connecting.value = true;
    success.value = null;
    error.value = null;

    window.axios
        .post(`/api/v1/tenants/${tenantId}/whatsapp/connect`, form.value)
        .then((res) => {
            account.value = res.data.whatsapp_account;
            webhookSubscribed.value = res.data.webhook_subscribed === true;
            form.value = {
                whatsapp_business_account_id: '',
                phone_number_id: '',
                phone_number: '',
                display_name: '',
                access_token: '',
            };
            success.value = webhookSubscribed.value
                ? 'Cuenta de WhatsApp conectada y suscrita al webhook.'
                : 'Cuenta de WhatsApp conectada. Revisa la suscripción del webhook en el dashboard de Meta.';
        })
        .catch((err) => {
            error.value = errorMessage(err, 'No se pudo conectar la cuenta de WhatsApp.');
        })
        .finally(() => {
            connecting.value = false;
        });
};

const disconnect = (): void => {
    if (!tenantId || !account.value) {
        return;
    }

    if (!window.confirm('¿Desconectar la cuenta de WhatsApp? El historial se conserva.')) {
        return;
    }

    disconnecting.value = true;
    success.value = null;
    error.value = null;

    window.axios
        .post(`/api/v1/tenants/${tenantId}/whatsapp/disconnect`)
        .then((res) => {
            account.value = res.data.whatsapp_account;
            success.value = 'Cuenta de WhatsApp desconectada.';
        })
        .catch((err) => {
            error.value = errorMessage(err, 'No se pudo desconectar la cuenta de WhatsApp.');
        })
        .finally(() => {
            disconnecting.value = false;
        });
};

onMounted(load);
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">WhatsApp</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Conecta la WhatsApp Business Account (WABA) de tu negocio mediante la
                    Meta WhatsApp Cloud API oficial. El token de acceso se guarda cifrado y
                    nunca se muestra.
                </p>
            </div>

            <div v-if="success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ success }}
            </div>
            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <p v-if="loading" class="text-sm text-zinc-500">Cargando...</p>

            <template v-else>
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-zinc-900">Estado de la conexión</h3>

                    <p v-if="!account" class="mt-4 text-sm text-zinc-500">
                        No hay una cuenta de WhatsApp conectada.
                    </p>

                    <dl v-else class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-zinc-500">Estado</dt>
                            <dd>
                                <span
                                    :class="account.status === 'connected' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600'"
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                >
                                    {{ account.status === 'connected' ? 'Conectada' : 'Desconectada' }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-zinc-500">Nombre</dt>
                            <dd class="text-zinc-900">{{ account.display_name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-zinc-500">WhatsApp Business Account ID</dt>
                            <dd class="text-zinc-900">{{ account.whatsapp_business_account_id ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-zinc-500">Teléfono</dt>
                            <dd class="text-zinc-900">
                                {{
                                    account.phone_numbers[0]?.display_phone_number ?? account.phone_numbers[0]?.phone_id ?? '—'
                                }}
                            </dd>
                        </div>
                        <div v-if="webhookSubscribed" class="rounded-md bg-emerald-50 px-3 py-2 text-emerald-700">
                            El webhook está suscrito a la app.
                        </div>
                    </dl>

                    <div v-if="account && account.status === 'connected' && canManage" class="mt-6">
                        <button
                            type="button"
                            :disabled="disconnecting"
                            class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                            @click="disconnect"
                        >
                            {{ disconnecting ? 'Desconectando...' : 'Desconectar' }}
                        </button>
                    </div>
                </div>

                <form
                    v-if="canManage && (!account || account.status !== 'connected')"
                    class="space-y-6"
                    @submit.prevent="connect"
                >
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-zinc-900">Conectar cuenta</h3>
                        <p class="mt-1 text-sm text-zinc-500">
                            Completa estos datos desde tu app de Meta (WhatsApp &gt; API Setup) y
                            con un token de usuario del sistema con permisos de la WABA.
                        </p>

                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="wa-waba" class="mb-1 block text-sm font-medium text-zinc-700">WhatsApp Business Account ID</label>
                                <input
                                    id="wa-waba"
                                    v-model="form.whatsapp_business_account_id"
                                    type="text"
                                    required
                                    maxlength="255"
                                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label for="wa-phone-id" class="mb-1 block text-sm font-medium text-zinc-700">Phone Number ID</label>
                                <input
                                    id="wa-phone-id"
                                    v-model="form.phone_number_id"
                                    type="text"
                                    required
                                    maxlength="255"
                                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label for="wa-phone" class="mb-1 block text-sm font-medium text-zinc-700">Número (formato E.164)</label>
                                <input
                                    id="wa-phone"
                                    v-model="form.phone_number"
                                    type="text"
                                    placeholder="+15550783881"
                                    maxlength="40"
                                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label for="wa-name" class="mb-1 block text-sm font-medium text-zinc-700">Nombre (opcional)</label>
                                <input
                                    id="wa-name"
                                    v-model="form.display_name"
                                    type="text"
                                    maxlength="255"
                                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label for="wa-token" class="mb-1 block text-sm font-medium text-zinc-700">
                                    Access token (se guarda cifrado, nunca se muestra)
                                </label>
                                <input
                                    id="wa-token"
                                    v-model="form.access_token"
                                    type="password"
                                    required
                                    autocomplete="off"
                                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <button
                                type="submit"
                                :disabled="connecting"
                                class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            >
                                {{ connecting ? 'Conectando...' : 'Conectar' }}
                            </button>
                        </div>
                    </div>
                </form>
            </template>
        </div>
    </AppLayout>
</template>
