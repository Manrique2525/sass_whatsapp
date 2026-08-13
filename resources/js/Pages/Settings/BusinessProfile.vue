<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface WorkingHoursEntry {
    day: string;
    open: string | null;
    close: string | null;
    closed: boolean;
}

interface BusinessProfile {
    id: string;
    name: string | null;
    description: string | null;
    category: string | null;
    address: string | null;
    website: string | null;
    email: string | null;
    phone: string | null;
    working_hours: WorkingHoursEntry[] | null;
    updated_at: string | null;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canUpdate = computed(() => can('business_profile.update'));

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const form = ref<BusinessProfile>({
    id: '',
    name: null,
    description: null,
    category: null,
    address: null,
    website: null,
    email: null,
    phone: null,
    working_hours: null,
    updated_at: null,
});

const days: Record<string, string> = {
    mon: 'Lunes',
    tue: 'Martes',
    wed: 'Miércoles',
    thu: 'Jueves',
    fri: 'Viernes',
    sat: 'Sábado',
    sun: 'Domingo',
};

const dayOptions = Object.keys(days);

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
        const res = await window.axios.get(`/api/v1/tenants/${tenantId}/business-profile`);
        form.value = res.data.business_profile;
    } catch (err) {
        error.value = errorMessage(err, 'No se pudo cargar el perfil de negocio.');
    } finally {
        loading.value = false;
    }
};

const addWorkingDay = (): void => {
    if (!form.value.working_hours) {
        form.value.working_hours = [];
    }

    form.value.working_hours.push({
        day: dayOptions[0],
        open: '09:00',
        close: '18:00',
        closed: false,
    });
};

const removeWorkingDay = (index: number): void => {
    if (!form.value.working_hours) {
        return;
    }

    form.value.working_hours.splice(index, 1);

    if (form.value.working_hours.length === 0) {
        form.value.working_hours = null;
    }
};

const save = (): void => {
    if (!tenantId) {
        return;
    }

    saving.value = true;
    success.value = null;
    error.value = null;

    const payload: Record<string, unknown> = {
        name: form.value.name,
        description: form.value.description,
        category: form.value.category,
        address: form.value.address,
        website: form.value.website,
        email: form.value.email,
        phone: form.value.phone,
        working_hours: form.value.working_hours,
    };

    window.axios
        .put(`/api/v1/tenants/${tenantId}/business-profile`, payload)
        .then((res) => {
            form.value = res.data.business_profile;
            success.value = 'Perfil de negocio actualizado.';
        })
        .catch((err) => {
            const data = err.response?.data;
            error.value = data?.message ?? 'No se pudo actualizar el perfil de negocio.';

            if (data?.errors && typeof data.errors === 'object') {
                const first = Object.values(data.errors as Record<string, string[]>)[0];
                if (Array.isArray(first) && first[0]) {
                    error.value = first[0];
                }
            }
        })
        .finally(() => {
            saving.value = false;
        });
};

onMounted(load);
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Perfil de negocio</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Información pública de tu negocio. Se usa en las respuestas del chatbot
                    (variable <code class="rounded bg-zinc-100 px-1 text-xs">business.name</code>).
                </p>
            </div>

            <div v-if="success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ success }}
            </div>
            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <p v-if="loading" class="text-sm text-zinc-500">Cargando...</p>

            <form v-else class="space-y-6" @submit.prevent="save">
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-zinc-900">Datos generales</h3>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="bp-name" class="mb-1 block text-sm font-medium text-zinc-700">Nombre del negocio</label>
                            <input
                                id="bp-name"
                                v-model="form.name"
                                type="text"
                                maxlength="255"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                        <div>
                            <label for="bp-category" class="mb-1 block text-sm font-medium text-zinc-700">Categoría</label>
                            <input
                                id="bp-category"
                                v-model="form.category"
                                type="text"
                                maxlength="100"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="bp-description" class="mb-1 block text-sm font-medium text-zinc-700">Descripción</label>
                            <textarea
                                id="bp-description"
                                v-model="form.description"
                                rows="3"
                                maxlength="5000"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-zinc-900">Contacto y ubicación</h3>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="bp-email" class="mb-1 block text-sm font-medium text-zinc-700">Email de contacto</label>
                            <input
                                id="bp-email"
                                v-model="form.email"
                                type="email"
                                maxlength="255"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                        <div>
                            <label for="bp-phone" class="mb-1 block text-sm font-medium text-zinc-700">Teléfono</label>
                            <input
                                id="bp-phone"
                                v-model="form.phone"
                                type="text"
                                maxlength="40"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                        <div>
                            <label for="bp-website" class="mb-1 block text-sm font-medium text-zinc-700">Sitio web</label>
                            <input
                                id="bp-website"
                                v-model="form.website"
                                type="url"
                                maxlength="255"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                        <div>
                            <label for="bp-address" class="mb-1 block text-sm font-medium text-zinc-700">Dirección</label>
                            <input
                                id="bp-address"
                                v-model="form.address"
                                type="text"
                                maxlength="255"
                                :disabled="!canUpdate"
                                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-zinc-100"
                            />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-zinc-900">Horarios</h3>
                        <button
                            v-if="canUpdate"
                            type="button"
                            class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50"
                            @click="addWorkingDay"
                        >
                            + Añadir día
                        </button>
                    </div>

                    <p v-if="!form.working_hours || form.working_hours.length === 0" class="mt-4 text-sm text-zinc-500">
                        Sin horarios configurados.
                    </p>

                    <ul v-else class="mt-4 divide-y divide-zinc-100">
                        <li v-for="(entry, index) in form.working_hours" :key="index" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
                            <select
                                v-model="entry.day"
                                :disabled="!canUpdate"
                                class="rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:bg-zinc-100"
                            >
                                <option v-for="day in dayOptions" :key="day" :value="day">{{ days[day] }}</option>
                            </select>
                            <label class="flex items-center gap-2 text-sm text-zinc-700">
                                <input v-model="entry.closed" type="checkbox" :disabled="!canUpdate" />
                                Cerrado
                            </label>
                            <div v-if="!entry.closed" class="flex items-center gap-2">
                                <input
                                    v-model="entry.open"
                                    type="time"
                                    :disabled="!canUpdate"
                                    class="rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:bg-zinc-100"
                                />
                                <span class="text-zinc-400">a</span>
                                <input
                                    v-model="entry.close"
                                    type="time"
                                    :disabled="!canUpdate"
                                    class="rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:bg-zinc-100"
                                />
                            </div>
                            <button
                                v-if="canUpdate"
                                type="button"
                                class="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                                @click="removeWorkingDay(index)"
                            >
                                Quitar
                            </button>
                        </li>
                    </ul>
                </div>

                <div v-if="canUpdate" class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="saving"
                        class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {{ saving ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
