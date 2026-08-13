<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

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
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-900">Usuarios del tenant</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Gestiona miembros e invitaciones. Tu rol actual:
                    <span class="font-medium text-zinc-800">{{ roleLabel[page.props.auth.current_role ?? ''] ?? '—' }}</span>
                </p>
            </div>

            <div v-if="success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ success }}
            </div>
            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <div v-if="canInvite" class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Invitar</h3>
                <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submitInvite">
                    <div class="flex-1">
                        <label for="invite-email" class="mb-1 block text-sm font-medium text-zinc-700">Email</label>
                        <input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            required
                            class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label for="invite-role" class="mb-1 block text-sm font-medium text-zinc-700">Rol</label>
                        <select
                            id="invite-role"
                            v-model="inviteForm.role"
                            class="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="admin">Administrador</option>
                            <option value="agent">Agente</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                        Enviar invitación
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Miembros</h3>

                <p v-if="loading" class="mt-4 text-sm text-zinc-500">Cargando...</p>
                <p v-else-if="members.length === 0" class="mt-4 text-sm text-zinc-500">Sin miembros.</p>

                <ul v-else class="mt-4 divide-y divide-zinc-100">
                    <li v-for="member in members" :key="member.id" class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-800">{{ member.user.name }}</p>
                            <p class="text-xs text-zinc-500">{{ member.user.email }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700">
                                {{ roleLabel[member.role] }}
                            </span>
                            <select
                                v-if="canUpdateUsers && member.role !== 'owner'"
                                class="rounded-md border border-zinc-300 px-2 py-1 text-xs"
                                :value="member.role"
                                @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="admin">Administrador</option>
                                <option value="agent">Agente</option>
                            </select>
                            <button
                                v-if="canRemoveUsers"
                                type="button"
                                class="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                                @click="removeMember(member)"
                            >
                                Remover
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <div v-if="canViewUsers" class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Invitaciones pendientes</h3>

                <p v-if="loading" class="mt-4 text-sm text-zinc-500">Cargando...</p>
                <p v-else-if="invitations.length === 0" class="mt-4 text-sm text-zinc-500">Sin invitaciones pendientes.</p>

                <ul v-else class="mt-4 divide-y divide-zinc-100">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-800">{{ invitation.email }}</p>
                            <p class="text-xs text-zinc-500">
                                {{ roleLabel[invitation.role] }} · expira el {{ new Date(invitation.expires_at).toLocaleDateString() }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="canInvite"
                                type="button"
                                class="rounded-md border border-zinc-300 px-2 py-1 text-xs text-zinc-700 hover:bg-zinc-50"
                                @click="resendInvitation(invitation)"
                            >
                                Reenviar
                            </button>
                            <button
                                v-if="canInvite"
                                type="button"
                                class="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
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
