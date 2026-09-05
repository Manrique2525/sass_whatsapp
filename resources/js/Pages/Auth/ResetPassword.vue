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
                <label for="email" class="mb-1.5 block text-sm font-semibold text-[#33483e]">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    readonly
                    class="app-field bg-[#f0f5ef] text-[#71877b]"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-semibold text-[#33483e]">Nueva contraseña</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="app-field"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-1.5 block text-sm font-semibold text-[#33483e]">
                    Confirmar contraseña
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="app-field"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="app-button app-button--primary w-full"
            >
                Restablecer contraseña
            </button>
        </form>
    </AuthLayout>
</template>
