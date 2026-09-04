import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import AppLayout from './AppLayout.vue';

const auth = {
    user: { id: 1, name: 'Test User', email: 'test@example.test' },
    tenants: [{ id: 'tenant-a', name: 'Acme', slug: 'acme', status: 'active', is_current: true }],
    current_tenant_id: 'tenant-a',
    permissions: [] as string[],
};

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth }, url: '/settings/knowledge' }),
}));

const mountLayout = () => mount(AppLayout, {
    props: { user: auth.user },
    global: { stubs: { NotificationBell: { template: '<span />' } } },
});

describe('authenticated navigation', () => {
    beforeEach(() => { auth.permissions = []; });

    it('shows the owner navigation', () => {
        auth.permissions = [
            'conversations.view', 'flows.view', 'faqs.view', 'leads.view', 'knowledge.view',
            'contacts.view', 'analytics.view', 'users.view', 'business_profile.view',
            'whatsapp.view', 'billing.view',
        ];
        const wrapper = mountLayout();
        expect(wrapper.get('[data-testid="authenticated-navigation"]').text()).toContain('Knowledge');
        expect(wrapper.text()).toContain('Usuarios');
        expect(wrapper.text()).toContain('Billing');
    });

    it('shows admin billing read access without using manage permission', () => {
        auth.permissions = ['conversations.view', 'flows.view', 'knowledge.view', 'billing.view'];
        const wrapper = mountLayout();
        expect(wrapper.text()).toContain('Billing');
        expect(wrapper.text()).toContain('Knowledge');
    });

    it('keeps agent navigation free of admin-only surfaces', () => {
        auth.permissions = ['conversations.view', 'flows.view', 'faqs.view', 'leads.view', 'knowledge.view', 'contacts.view', 'business_profile.view', 'whatsapp.view'];
        const wrapper = mountLayout();
        expect(wrapper.text()).toContain('Conversaciones');
        expect(wrapper.text()).toContain('Knowledge');
        expect(wrapper.text()).not.toContain('Usuarios');
        expect(wrapper.text()).not.toContain('Analytics');
        expect(wrapper.text()).not.toContain('Billing');
    });

    it('marks the current route and closes the mobile menu with Escape', async () => {
        auth.permissions = ['knowledge.view'];
        const wrapper = mountLayout();
        const active = wrapper.get('[data-testid="authenticated-navigation"] a[href="/settings/knowledge"]');
        expect(active.attributes('aria-current')).toBe('page');
        await wrapper.get('button[aria-controls="mobile-navigation"]').trigger('click');
        expect(wrapper.find('[data-testid="mobile-navigation"]').exists()).toBe(true);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(wrapper.find('[data-testid="mobile-navigation"]').exists()).toBe(false);
    });
});
