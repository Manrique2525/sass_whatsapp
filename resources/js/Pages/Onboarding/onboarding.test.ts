import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Onboarding from '@/Pages/Onboarding/Index.vue';

const mockGet = vi.fn();

Object.defineProperty(window, 'axios', {
  value: { get: mockGet },
  writable: true,
});

const mockPermissions = { value: ['whatsapp.manage', 'billing.view'] as string[] };
let mockTenantId: string | null = 't1';
const mockTenants = [
  { id: 't1', name: 'Mi Negocio', slug: 'mi-negocio', status: 'active', is_current: true },
];

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: { id: 1, name: 'Jane Doe', email: 'jane@example.com' },
        tenants: mockTenants,
        get current_tenant_id() { return mockTenantId; },
        current_role: 'owner',
        get permissions() { return mockPermissions.value; },
        is_super_admin: false,
      },
      flash: {},
      errors: {},
    },
  }),
  Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
}));

const SAMPLE_SUBSCRIPTION = {
  id: 'sub-uuid',
  plan: {
    id: 'plan-free-uuid',
    slug: 'free',
    name: 'Free',
    description: 'Free tier with basic limits',
    is_active: true,
    price_monthly: 0,
    price_yearly: 0,
    limits: {},
    features: {},
    sort_order: 0,
  },
  status: 'active',
  quantity: 1,
  cancel_at_period_end: false,
  current_period_start: '2026-09-01T00:00:00Z',
  current_period_end: '2026-10-01T00:00:00Z',
};

const mountPage = () =>
  mount(Onboarding, {
    global: {
      stubs: {
        AppLayout: {
          template: '<div class="app-layout-stub"><slot /></div>',
          props: ['user'],
        },
      },
    },
  });

beforeEach(() => {
  vi.clearAllMocks();
  mockTenantId = 't1';
  mockPermissions.value = ['whatsapp.manage', 'billing.view'];
});

describe('ONB-FE-U1-01: page renders', () => {
  it('renders welcome and workspace name', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('¡Bienvenido!');
    expect(wrapper.text()).toContain('Mi Negocio');
  });
});

describe('ONB-FE-U1-02: shows active free plan', () => {
  it('displays subscription plan name and active status', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Free');
    expect(wrapper.text()).toContain('Activo');
  });
});

describe('ONB-FE-U1-03: shows connect WhatsApp CTA for owner', () => {
  it('renders Conectar WhatsApp link to /settings/whatsapp', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Conectar WhatsApp');
    const links = wrapper.findAll('a');
    const whatsappLink = links.find((l) => l.text().includes('Conectar WhatsApp'));
    expect(whatsappLink).toBeDefined();
    expect(whatsappLink!.attributes('href')).toBe('/settings/whatsapp');
  });
});

describe('ONB-FE-U1-04: explore platform CTA', () => {
  it('renders Explorar la plataforma link to /dashboard', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    const links = wrapper.findAll('a');
    const exploreLink = links.find((l) => l.text().includes('Explorar la plataforma'));
    expect(exploreLink).toBeDefined();
    expect(exploreLink!.attributes('href')).toBe('/dashboard');
  });
});

describe('ONB-FE-U1-05: hides whatsapp CTA without permission', () => {
  it('does not render Conectar WhatsApp when no whatsapp.manage', async () => {
    mockPermissions.value = ['billing.view'];
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).not.toContain('Conectar WhatsApp');
  });
});

describe('ONB-FE-U1-06: handles missing subscription', () => {
  it('shows no active subscription state', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: null } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Sin suscripción activa');
  });
});

describe('ONB-FE-U1-07: no v-html', () => {
  it('page does not use v-html directive', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('v-html');
    expect(html).not.toContain('innerHTML');
    expect(html).not.toContain('eval(');
  });
});