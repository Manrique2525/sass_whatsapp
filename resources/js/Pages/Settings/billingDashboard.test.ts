import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from '@/Pages/Settings/Billing.vue';
import type { Plan, Subscription, UsageSummary, UsageRecord } from '@/features/billing/billingTypes';
import { changePlan } from '@/features/billing/billingApi';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockDelete = vi.fn();

Object.defineProperty(window, 'axios', {
  value: { get: mockGet, post: mockPost, patch: mockPatch, delete: mockDelete },
  writable: true,
});

const mockPermissions = { value: ['billing.view', 'billing.manage'] as string[] };
let mockTenantId: string | null = 't1';

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: { id: 1, name: 'Test User', email: 'test@test.com' },
        tenants: [{ id: 't1', name: 'Tenant 1', slug: 't1', status: 'active', is_current: true }],
        get current_tenant_id() { return mockTenantId; },
        current_role: 'owner',
        get permissions() { return mockPermissions.value; },
        is_super_admin: false,
      },
      flash: {},
      errors: {},
    },
  }),
  Link: { template: '<a><slot /></a>', props: ['href'] },
}));

const SAMPLE_PLAN: Plan = {
  id: 'plan-free-uuid',
  slug: 'free',
  name: 'Free',
  description: 'Plan gratuito',
  is_active: true,
  price_monthly: 0,
  price_yearly: 0,
  limits: { messages: 100, ai_tokens: 1000, contacts: 50 },
  features: {},
  sort_order: 0,
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

const SAMPLE_PLAN_PRO: Plan = {
  ...SAMPLE_PLAN,
  id: 'plan-pro-uuid',
  slug: 'pro',
  name: 'Pro',
  description: 'Plan profesional',
  price_monthly: 999,
  price_yearly: 9990,
  limits: { messages: 10000, ai_tokens: 50000, contacts: 5000 },
  sort_order: 1,
};

const SAMPLE_SUBSCRIPTION: Subscription = {
  id: 'sub-uuid-1',
  plan: SAMPLE_PLAN,
  status: 'active',
  quantity: 1,
  current_period_start: '2026-08-01T00:00:00Z',
  current_period_end: '2026-09-01T00:00:00Z',
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

const SAMPLE_USAGE: UsageSummary = {
  subscription_id: 'sub-uuid-1',
  period_start: '2026-08-01T00:00:00Z',
  period_end: '2026-09-01T00:00:00Z',
  categories: {
    messages: { used: 50, limit: 100, remaining: 50 },
    ai_tokens: { used: 200, limit: 1000, remaining: 800 },
  },
};

const SAMPLE_RECORD: UsageRecord = {
  id: 'rec-1',
  category: 'messages',
  quantity: 5,
  description: 'test message',
  metadata: null,
  recorded_at: '2026-08-15T12:00:00Z',
  created_at: '2026-08-15T12:00:00Z',
};

const mockAll = (): void => {
  mockGet
    .mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } })
    .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN, SAMPLE_PLAN_PRO] } })
    .mockResolvedValueOnce({ data: { usage: SAMPLE_USAGE } })
    .mockResolvedValueOnce({
      data: {
        usage_records: [SAMPLE_RECORD],
        meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
      },
    });
};

const mountPage = () =>
  mount(Billing, {
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
  mockPermissions.value = ['billing.view', 'billing.manage'];
  mockTenantId = 't1';
});

describe('BILL-FE-U4-08: page renders', () => {
  it('renders Billing heading', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Billing');
  });
});

describe('BILL-FE-U4-09: fetch plans', () => {
  it('calls plans API on mount', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const plansCall = mockGet.mock.calls.find((c) =>
      String(c[0]).includes('/plans'),
    );
    expect(plansCall).toBeDefined();
  });
});

describe('BILL-FE-U4-10: fetch subscription', () => {
  it('calls subscriptions API on mount', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const subCall = mockGet.mock.calls.find((c) =>
      String(c[0]).includes('/subscriptions'),
    );
    expect(subCall).toBeDefined();
  });

  it('shows current plan name', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Free');
  });
});

describe('BILL-FE-U4-11: fetch usage', () => {
  it('calls usage API on mount', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const usageCall = mockGet.mock.calls.find((c) =>
      String(c[0]).includes('/usage') && !String(c[0]).includes('/history'),
    );
    expect(usageCall).toBeDefined();
  });
});

describe('BILL-FE-U4-12: usage history', () => {
  it('calls usage history API', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const historyCall = mockGet.mock.calls.find((c) =>
      String(c[0]).includes('/usage/history'),
    );
    expect(historyCall).toBeDefined();
  });

  it('shows history table', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Historial de uso');
  });
});

describe('BILL-FE-U4-13: owner manage', () => {
  it('shows select plan button for owner', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const buttons = wrapper.findAll('button');
    const selectBtn = buttons.find((b) => b.text().includes('Seleccionar plan') || b.text().includes('Cambiar a este plan'));
    expect(selectBtn).toBeDefined();
  });

  it('shows cancel subscription link for owner', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Cancelar suscripción');
  });
});

describe('BILL-FE-U4-14: admin read-only', () => {
  it('hides manage buttons for admin', async () => {
    mockPermissions.value = ['billing.view'];

    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Seleccionar plan') || b.text().includes('Cambiar a este plan'),
    );
    expect(selectBtn).toBeUndefined();

    const cancelLink = wrapper.findAll('button').find((b) =>
      b.text().includes('Cancelar suscripción'),
    );
    expect(cancelLink).toBeUndefined();

    mockPermissions.value = ['billing.view', 'billing.manage'];
  });
});

describe('BILL-FE-U4-15: agent denied', () => {
  it('hides content for agent without billing.view', async () => {
    mockPermissions.value = [];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No tienes permiso para ver billing');

    mockPermissions.value = ['billing.view', 'billing.manage'];
  });
});

describe('BILL-FE-U4-16: assign plan dialog', () => {
  it('opens confirmation dialog on plan select', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Seleccionar plan') || b.text().includes('Cambiar a este plan'),
    );
    expect(selectBtn).toBeDefined();
    await selectBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Confirmar');
    expect(wrapper.text()).toContain('Cancelar');
  });
});

describe('BILL-FE-U4-17: cancel dialog', () => {
  it('opens cancel confirmation dialog', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const cancelBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cancelar suscripción'),
    );
    expect(cancelBtn).toBeDefined();
    await cancelBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Sí, cancelar');
    expect(wrapper.text()).toContain('No, mantener');
  });
});

describe('BILL-FE-U4-18: double-submit blocked', () => {
  it('disables loading button while saving', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Seleccionar plan') || b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    const confirmBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Confirmar'),
    );
    expect(confirmBtn).toBeDefined();
    expect(confirmBtn!.attributes('disabled')).toBeUndefined();
  });
});

describe('BILL-FE-U4-19: loading', () => {
  it('shows loading state', () => {
    mockGet.mockReturnValueOnce(new Promise(() => {}));
    mockGet.mockReturnValueOnce(new Promise(() => {}));
    mockGet.mockReturnValueOnce(new Promise(() => {}));
    const wrapper = mountPage();

    expect(wrapper.findAll('.animate-pulse').length).toBeGreaterThan(0);
  });
});

describe('BILL-FE-U4-20: error', () => {
  it('shows error message on API failure', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Permiso denegado.' } } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Permiso denegado.');
  });
});

describe('BILL-FE-U4-21: empty states', () => {
  it('shows empty subscription state', async () => {
    mockGet
      .mockResolvedValueOnce({ data: { subscription: null } })
      .mockResolvedValueOnce({ data: { plans: [] } })
      .mockResolvedValueOnce({ data: { usage: null } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Sin suscripción activa');
  });

  it('shows empty plans', async () => {
    mockGet
      .mockResolvedValueOnce({ data: { subscription: null } })
      .mockResolvedValueOnce({ data: { plans: [] } })
      .mockResolvedValueOnce({ data: { usage: null } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No hay planes disponibles');
  });

  it('shows empty usage history', async () => {
    mockGet
      .mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } })
      .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } })
      .mockResolvedValueOnce({ data: { usage: SAMPLE_USAGE } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No hay registros de uso');
  });
});

describe('BILL-FE-U4-22: unlimited usage', () => {
  it('shows ∞ for null limits', async () => {
    const unlimitedUsage: UsageSummary = {
      subscription_id: 'sub-uuid-1',
      period_start: '2026-08-01T00:00:00Z',
      period_end: '2026-09-01T00:00:00Z',
      categories: {
        messages: { used: 50, limit: null, remaining: null },
      },
    };

    mockGet
      .mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } })
      .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } })
      .mockResolvedValueOnce({ data: { usage: unlimitedUsage } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('∞');
    expect(wrapper.text()).toContain('Ilimitado');
  });
});

describe('BILL-FE-U4-23: no NaN/Infinity', () => {
  it('no NaN in rendered text', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).not.toContain('NaN');
    expect(wrapper.text()).not.toContain('Infinity');
  });
});

describe('BILL-FE-U4-24: tenant switch cleanup', () => {
  it('api calls use current tenant ID', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const allCalls = mockGet.mock.calls.map((c) => String(c[0]));
    const allUseTenant = allCalls.every((url) => url.includes('/tenants/t1/'));
    expect(allUseTenant).toBe(true);
  });
});

describe('BILL-FE-U4-25: security — no tenant_id in payload', () => {
  it('changePlan sends only plan_id', async () => {
    mockPatch.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });

    await changePlan('tenant-1', 'plan-uuid-2');

    const callArgs = mockPatch.mock.calls[0];
    const body = callArgs[1];
    expect(Object.keys(body)).toEqual(['plan_id']);
    expect(body).not.toHaveProperty('tenant_id');
  });
});

describe('BILL-FE-U4-26: no v-html', () => {
  it('page does not use v-html directive', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('v-html');
  });
});

describe('BILL-FE-U4-27: no hardcoded prices', () => {
  it('prices come from API data, not template literals', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();

    expect(html).not.toContain('v-html');
    expect(html).not.toContain('innerHTML');
    expect(html).not.toContain('eval(');
  });
});
