import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard.vue';

const auth = {
    user: { id: 7, name: 'Agent', email: 'agent@example.test' },
    current_tenant_id: 'tenant-a',
    tenants: [
        { id: 'tenant-a', name: 'Acme', slug: 'acme', status: 'active' },
    ],
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth } }),
}));

const mountPage = () => mount(Dashboard, {
    global: {
        stubs: {
            AppLayout: { template: '<div><slot /></div>', props: ['user'] },
            NotificationPreferenceToggle: { template: '<div data-test="notifications" />' },
        },
    },
});

describe('Dashboard page', () => {
    beforeEach(() => {
        auth.current_tenant_id = 'tenant-a';
    });

    it('renders the authenticated user and active tenant', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Hola, Agent');
        expect(wrapper.text()).toContain('Acme');
        expect(wrapper.text()).toContain('active');
        expect(wrapper.find('[data-test="notifications"]').exists()).toBe(true);
    });

    it('shows the empty state when there is no active tenant', () => {
        auth.current_tenant_id = 'missing-tenant';

        expect(mountPage().text()).toContain('Sin tenant activo.');
    });
});
