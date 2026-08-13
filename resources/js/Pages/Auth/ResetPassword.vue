<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps<{
    token: string;
    email: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = (): void => {
    form.post('/reset-password');
};
</script>

<template>
    <AuthLayout title="Restablecer contraseña">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-zinc-700">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    readonly
                    class="w-full rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-zinc-700">Nueva contraseña</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-zinc-700">
                    Confirmar contraseña
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
                Restablecer contraseña
            </button>
        </form>
    </AuthLayout>
</template>
