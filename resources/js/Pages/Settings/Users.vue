<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppSelect from '@/Components/AppSelect.vue';

interface MemberUser {
    id: number;
    name: string;
    email: string;
}

interface Member {
    id: number;
    user: MemberUser;
    role: 'owner' | 'admin' | 'agent';
    status: 'active' | 'invited' | 'disabled';
    joined_at: string | null;
    invited_at: string | null;
}

interface Invitation {
    id: string;
    email: string;
    role: 'admin' | 'agent';
    status: string;
    expires_at: string;
    created_at: string;
}

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const members = ref<Member[]>([]);
const invitations = ref<Invitation[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canViewUsers = computed(() => can('users.view'));
const canInvite = computed(() => can('users.invite'));
const canUpdateUsers = computed(() => can('users.update'));
const canRemoveUsers = computed(() => can('users.remove'));

const roleLabel: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    agent: 'Agente',
};

const inviteForm = useForm({
    email: '',
    role: 'agent',
});

const load = async (): Promise<void> => {
    if (!tenantId) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const [membersRes, invitationsRes] = await Promise.all([
            window.axios.get(`/api/v1/tenants/${tenantId}/users`),
            window.axios.get(`/api/v1/tenants/${tenantId}/users/invitations`),
        ]);

        members.value = membersRes.data.members ?? [];
        invitations.value = invitationsRes.data.invitations ?? [];
    } catch (err) {
        error.value = 'No se pudieron cargar los usuarios.';
    } finally {
        loading.value = false;
    }
};

const submitInvite = (): void => {
    if (!tenantId) {
        return;
    }

    success.value = null;
    error.value = null;

    window.axios
        .post(`/api/v1/tenants/${tenantId}/users/invitations`, inviteForm.data())
        .then(() => {
            inviteForm.reset();
            success.value = 'Invitación enviada.';
            return load();
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'No se pudo enviar la invitación.';
        });
};

const changeRole = (member: Member, role: string): void => {
    if (!tenantId) {
        return;
    }

    success.value = null;
    error.value = null;

    window.axios
        .patch(`/api/v1/tenants/${tenantId}/users/${member.user.id}`, { role })
        .then(() => {
            success.value = 'Rol actualizado.';
            return load();
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'No se pudo actualizar el rol.';
        });
};

const removeMember = (member: Member): void => {
    if (!tenantId || !window.confirm(`¿Remover a ${member.user.name} del tenant?`)) {
        return;
    }

    success.value = null;
    error.value = null;

    window.axios
        .delete(`/api/v1/tenants/${tenantId}/users/${member.user.id}`)
        .then(() => {
            success.value = 'Miembro removido.';
            return load();
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'No se pudo remover al miembro.';
        });
};

const revokeInvitation = (invitation: Invitation): void => {
    if (!tenantId) {
        return;
    }

    success.value = null;
    error.value = null;

    window.axios
        .post(`/api/v1/tenants/${tenantId}/users/invitations/${invitation.id}/revoke`)
        .then(() => {
            success.value = 'Invitación revocada.';
            return load();
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'No se pudo revocar la invitación.';
        });
};

const resendInvitation = (invitation: Invitation): void => {
    if (!tenantId) {
        return;
    }

    success.value = null;
    error.value = null;

    window.axios
        .post(`/api/v1/tenants/${tenantId}/users/invitations/${invitation.id}/resend`)
        .then(() => {
            success.value = 'Invitación reenviada.';
            return load();
        })
        .catch((err) => {
            error.value = err.response?.data?.message ?? 'No se pudo reenviar la invitación.';
        });
};

onMounted(load);
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="app-card relative overflow-hidden p-6 sm:p-8">
                <p class="app-eyebrow">Equipo y acceso</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-[#10261f]">Usuarios del tenant</h2>
                <p class="mt-2 text-sm leading-6 text-[#71877b]">
                    Gestiona miembros e invitaciones. Tu rol actual:
                    <span class="font-medium text-[#33483e]">{{ roleLabel[page.props.auth.current_role ?? ''] ?? '—' }}</span>
                </p>
            </div>

            <div v-if="success" class="app-alert app-alert--success px-4">
                {{ success }}
            </div>
            <div v-if="error" class="app-alert app-alert--error px-4">
                {{ error }}
            </div>

            <div v-if="canInvite" class="app-card p-5 sm:p-6">
                <h3 class="font-semibold text-[#10261f]">Invitar</h3>
                <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submitInvite">
                    <div class="flex-1">
                        <label for="invite-email" class="mb-2 block text-sm font-medium text-[#33483e]">Email</label>
                        <input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            required
                            class="app-field"
                        />
                    </div>
                    <div>
                        <label for="invite-role" class="mb-2 block text-sm font-medium text-[#33483e]">Rol</label>
                        <AppSelect
                            id="invite-role"
                            v-model="inviteForm.role"
                            class="w-full sm:min-w-[150px]"
                            :options="[
                                { value: 'admin', label: 'Administrador' },
                                { value: 'agent', label: 'Agente' },
                            ]"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="app-button app-button--primary"
                    >
                        Enviar invitación
                    </button>
                </form>
            </div>

            <div class="app-card p-5 sm:p-6">
                <h3 class="font-semibold text-[#10261f]">Miembros</h3>

                <p v-if="loading" class="mt-4 text-sm text-[#71877b]">Cargando...</p>
                <p v-else-if="members.length === 0" class="mt-4 text-sm text-[#71877b]">Sin miembros.</p>

                <ul v-else class="mt-4 divide-y divide-[#dce8df]">
                    <li v-for="member in members" :key="member.id" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[#10261f]">{{ member.user.name }}</p>
                            <p class="break-all text-xs text-[#71877b]">{{ member.user.email }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-[#eef3ed] px-2.5 py-1 text-xs font-medium text-[#4b6557]">
                                {{ roleLabel[member.role] }}
                            </span>
                            <AppSelect
                                v-if="canUpdateUsers && member.role !== 'owner'"
                                class="w-auto min-w-[140px]"
                                :model-value="member.role"
                                :options="[
                                    { value: 'admin', label: 'Administrador' },
                                    { value: 'agent', label: 'Agente' },
                                ]"
                                @update:model-value="changeRole(member, $event as string)"
                            />
                            <button
                                v-if="canRemoveUsers"
                                type="button"
                                class="app-button app-button--secondary border-red-200 px-2 py-1 text-xs text-red-600 hover:border-red-300 hover:bg-red-50"
                                @click="removeMember(member)"
                            >
                                Remover
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <div v-if="canViewUsers" class="app-card p-5 sm:p-6">
                <h3 class="font-semibold text-[#10261f]">Invitaciones pendientes</h3>

                <p v-if="loading" class="mt-4 text-sm text-[#71877b]">Cargando...</p>
                <p v-else-if="invitations.length === 0" class="mt-4 text-sm text-[#71877b]">Sin invitaciones pendientes.</p>

                <ul v-else class="mt-4 divide-y divide-[#dce8df]">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="break-all text-sm font-medium text-[#10261f]">{{ invitation.email }}</p>
                            <p class="text-xs text-[#71877b]">
                                {{ roleLabel[invitation.role] }} · expira el {{ new Date(invitation.expires_at).toLocaleDateString() }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="canInvite"
                                type="button"
                                class="app-button app-button--secondary px-2 py-1 text-xs"
                                @click="resendInvitation(invitation)"
                            >
                                Reenviar
                            </button>
                            <button
                                v-if="canInvite"
                                type="button"
                                class="app-button app-button--secondary border-red-200 px-2 py-1 text-xs text-red-600 hover:border-red-300 hover:bg-red-50"
                                @click="revokeInvitation(invitation)"
                            >
                                Revocar
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
