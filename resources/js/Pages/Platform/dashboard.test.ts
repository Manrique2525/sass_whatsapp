import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Platform/Dashboard.vue';

vi.mock('vue3-apexcharts', () => ({
    default: { template: '<div data-testid="chart" />', props: ['type', 'height', 'options', 'series'] },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1, name: 'Admin', email: 'admin@example.com' } } } }),
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
}));

const dashboard = {
    summary: { total_tenants: 2, active_tenants: 2, new_tenants_period: 1, unique_users: 3, tenant_memberships: 3, total_subscriptions: 2, active_subscriptions: 2, free_tenants: 1, whatsapp_configured: 1, whatsapp_connected: 1 },
    trends: { new_customers: [{ period: '2026-04', count: 0 }, { period: '2026-05', count: 1 }] },
    plans: [{ plan_id: 'plan-1', name: 'Free', slug: 'free', subscribers: 1, is_active: true }],
    subscriptions: { statuses: [{ status: 'active', count: 2 }], providers: [{ provider: 'local', count: 2 }] },
    usage: { period_start: '2026-09-01T00:00:00Z', period_end: '2026-10-01T00:00:00Z', categories: [{ category: 'messages', quantity: 10 }] },
    whatsapp: { configured: 1, connected: 1, disconnected: 0, connected_phone_numbers: 1, banned_phone_numbers: 0 },
    operations: {
        alerts: [],
        recent_customers: [{ tenant: { id: 'tenant-1', name: 'Acme' }, owner: { name: 'Owner', email: 'owner@example.com' }, plan: { id: 'plan-1', name: 'Free' }, created_at: '2026-09-10T00:00:00Z' }],
        recent_activity: [{ id: 'audit-1', action: 'platform.plan.updated', actor: 'Admin', target: { id: 'tenant-1', name: 'Acme' }, created_at: '2026-09-10T00:00:00Z' }],
    },
};

describe('Platform dashboard', () => {
    it('renders global cards, chart, links and activity', () => {
        const wrapper = mount(Dashboard, { props: { dashboard } as never, global: { stubs: { PlatformLayout: { template: '<div><slot /></div>', props: ['user'] } } } });

        expect(wrapper.text()).toContain('Operations overview');
        expect(wrapper.text()).toContain('Acme');
        expect(wrapper.text()).toContain('platform.plan.updated');
        expect(wrapper.find('[data-testid="chart"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/platform/customers/tenant-1"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/platform/plans/plan-1"]').exists()).toBe(true);
    });

    it('renders empty states without requiring data rows', () => {
        const empty = { ...dashboard, plans: [], usage: { ...dashboard.usage, categories: [] }, operations: { ...dashboard.operations, alerts: [], recent_customers: [], recent_activity: [] } };
        const wrapper = mount(Dashboard, { props: { dashboard: empty } as never, global: { stubs: { PlatformLayout: { template: '<div><slot /></div>', props: ['user'] } } } });

        expect(wrapper.text()).toContain('No plans configured.');
        expect(wrapper.text()).toContain('No metered usage recorded.');
        expect(wrapper.text()).toContain('No customers yet.');
        expect(wrapper.text()).toContain('No platform activity recorded.');
    });
});
