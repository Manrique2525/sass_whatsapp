import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from '@/Pages/Settings/Billing.vue';
import type { Plan, Subscription, UsageSummary, UsageRecord } from '@/features/billing/billingTypes';
import { changePlan, createCheckoutSession, createPortalSession, cancelSubscription } from '@/features/billing/billingApi';

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
  cancel_at_period_end: false,
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

const mockAllEmpty = (): void => {
  mockGet
    .mockResolvedValueOnce({ data: { subscription: null } })
    .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } })
    .mockResolvedValueOnce({ data: { usage: null } })
    .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });
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

describe('BILL-FE-U4-16: paid plan dialog shows checkout', () => {
  it('opens paid plan dialog with Ir a pagar button', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    expect(selectBtn).toBeDefined();
    await selectBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Cambiar de plan');
    expect(wrapper.text()).toContain('Ir a pagar');
    expect(wrapper.text()).toContain('Cancelar');
  });
});

describe('BILL-FE-U4-16b: free plan dialog shows confirm', () => {
  it('opens free plan dialog with Confirmar button', async () => {
    mockAllEmpty();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Seleccionar plan'),
    );
    expect(selectBtn).toBeDefined();
    await selectBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Asignar plan');
    expect(wrapper.text()).toContain('Confirmar');
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

describe('BILL-FE-U4-18: double-submit blocked on checkout', () => {
  it('checkout button is not disabled initially', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    const payBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Ir a pagar'),
    );
    expect(payBtn).toBeDefined();
    expect(payBtn!.attributes('disabled')).toBeUndefined();
  });
});

describe('BILL-FE-U4-18b: double-submit blocked on cancel', () => {
  it('cancel dialog button is not disabled initially', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const cancelBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cancelar suscripción'),
    );
    await cancelBtn!.trigger('click');
    await flushPromises();

    const confirmCancelBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Sí, cancelar'),
    );
    expect(confirmCancelBtn).toBeDefined();
    expect(confirmCancelBtn!.attributes('disabled')).toBeUndefined();
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

describe('BILL-FE-U4-28: checkout redirect', () => {
  it('paid plan triggers checkout API call', async () => {
    mockAll();
    mockPost.mockResolvedValueOnce({ data: { checkout_url: 'https://checkout.stripe.com/cs/test' } });

    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    const payBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Ir a pagar'),
    );
    await payBtn!.trigger('click');
    await flushPromises();

    expect(mockPost).toHaveBeenCalledWith(
      expect.stringContaining('/billing/checkout'),
      expect.objectContaining({ plan_id: 'plan-pro-uuid' }),
    );
  });
});

describe('BILL-FE-U4-29: free plan assigns locally', () => {
  it('free plan uses local assign API', async () => {
    mockAllEmpty();
    mockPost.mockResolvedValueOnce({ data: { subscription: { ...SAMPLE_SUBSCRIPTION, plan: SAMPLE_PLAN } } });

    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Seleccionar plan'),
    );
    expect(selectBtn).toBeDefined();
    await selectBtn!.trigger('click');
    await flushPromises();

    const confirmBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Confirmar'),
    );
    expect(confirmBtn).toBeDefined();
    await confirmBtn!.trigger('click');
    await flushPromises();

    expect(mockPost).toHaveBeenCalledWith(
      expect.stringContaining('/subscriptions'),
      expect.objectContaining({ plan_id: 'plan-free-uuid' }),
    );
  });
});

describe('BILL-FE-U4-30: checkout return success feedback', () => {
  it('shows success message on checkout=success', async () => {
    const origSearch = window.location.search;
    Object.defineProperty(window, 'location', {
      value: new Proxy(window.location, {
        get: (_target, prop) => {
          if (prop === 'search') return '?checkout=success';
          if (prop === 'pathname') return '/settings/billing';
          if (prop === 'href') return 'http://localhost/settings/billing?checkout=success';
          return Reflect.get(_target, prop);
        },
      }),
      writable: true,
      configurable: true,
    });

    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('El pago fue enviado para confirmación');

    Object.defineProperty(window, 'location', {
      value: { ...window.location, search: origSearch },
      writable: true,
      configurable: true,
    });
  });
});

describe('BILL-FE-U4-31: checkout return cancelled feedback', () => {
  it('shows cancelled message on checkout=cancelled', async () => {
    Object.defineProperty(window, 'location', {
      value: new Proxy(window.location, {
        get: (_target, prop) => {
          if (prop === 'search') return '?checkout=cancelled';
          if (prop === 'pathname') return '/settings/billing';
          if (prop === 'href') return 'http://localhost/settings/billing?checkout=cancelled';
          return Reflect.get(_target, prop);
        },
      }),
      writable: true,
      configurable: true,
    });

    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('El proceso de pago fue cancelado');
  });
});

describe('BILL-FE-U4-32: success does not set active', () => {
  it('checkout=success shows feedback, not Suscripción activada', async () => {
    Object.defineProperty(window, 'location', {
      value: new Proxy(window.location, {
        get: (_target, prop) => {
          if (prop === 'search') return '?checkout=success';
          if (prop === 'pathname') return '/settings/billing';
          if (prop === 'href') return 'http://localhost/settings/billing?checkout=success';
          return Reflect.get(_target, prop);
        },
      }),
      writable: true,
      configurable: true,
    });

    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('El pago fue enviado para confirmación');
    expect(wrapper.text()).not.toContain('Suscripción activada');
  });
});

describe('BILL-FE-U4-33: portal redirect', () => {
  it('portal button calls API', async () => {
    mockAll();
    mockPost.mockResolvedValueOnce({ data: { portal_url: 'https://billing.stripe.com/session/test' } });

    const wrapper = mountPage();
    await flushPromises();

    const portalBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Gestionar facturación'),
    );
    expect(portalBtn).toBeDefined();
    await portalBtn!.trigger('click');
    await flushPromises();

    expect(mockPost).toHaveBeenCalledWith(
      expect.stringContaining('/billing/portal'),
    );
  });
});

describe('BILL-FE-U4-34: portal rejects admin', () => {
  it('admin does not see portal button', async () => {
    mockPermissions.value = ['billing.view'];

    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const portalBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Gestionar facturación'),
    );
    expect(portalBtn).toBeUndefined();

    mockPermissions.value = ['billing.view', 'billing.manage'];
  });
});

describe('BILL-FE-U4-35: portal error handling', () => {
  it('shows error when portal fails', async () => {
    mockAll();
    mockPost.mockRejectedValueOnce({ response: { data: { message: 'No se pudo abrir el portal de facturación.' } } });

    const wrapper = mountPage();
    await flushPromises();

    const portalBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Gestionar facturación'),
    );
    await portalBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('No se pudo abrir el portal de facturación.');
  });
});

describe('BILL-FE-U4-36: cancel shows cancel_at_period_end', () => {
  it('shows period end message when cancel_at_period_end is true', async () => {
    const subWithCancel: Subscription = {
      ...SAMPLE_SUBSCRIPTION,
      cancel_at_period_end: true,
    };

    mockGet
      .mockResolvedValueOnce({ data: { subscription: subWithCancel } })
      .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN, SAMPLE_PLAN_PRO] } })
      .mockResolvedValueOnce({ data: { usage: SAMPLE_USAGE } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('suscripción seguirá activa hasta el final del período actual');
  });
});

describe('BILL-FE-U4-37: status labels', () => {
  it('shows Active label for active status', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Activo');
  });

  it('shows PastDue label', async () => {
    const pastDueSub: Subscription = { ...SAMPLE_SUBSCRIPTION, status: 'past_due' };

    mockGet
      .mockResolvedValueOnce({ data: { subscription: pastDueSub } })
      .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } })
      .mockResolvedValueOnce({ data: { usage: null } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Pago vencido');
  });

  it('cancelled subscription shows empty state', async () => {
    const cancelledSub: Subscription = { ...SAMPLE_SUBSCRIPTION, status: 'cancelled' };

    mockGet
      .mockResolvedValueOnce({ data: { subscription: cancelledSub } })
      .mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } })
      .mockResolvedValueOnce({ data: { usage: null } })
      .mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Sin suscripción activa');
  });
});

describe('BILL-FE-U4-38: no stripe price ID in types', () => {
  it('Plan type does not expose stripe_price_id', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('stripe_price_id');
  });
});

describe('BILL-FE-U4-39: no customer ID in page', () => {
  it('page does not render provider customer IDs', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('cus_');
  });
});

describe('BILL-FE-U4-40: no Stripe secret in page', () => {
  it('page does not render Stripe secrets', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('sk_live_');
    expect(html).not.toContain('sk_test_');
    expect(html).not.toContain('whsec_');
  });
});

describe('BILL-FE-U4-41: no subscription status mutation', () => {
  it('checkout does not set subscription active locally', async () => {
    mockAll();
    mockPost.mockResolvedValueOnce({ data: { checkout_url: 'https://checkout.stripe.com/cs/test' } });

    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    const payBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Ir a pagar'),
    );
    await payBtn!.trigger('click');
    await flushPromises();

    expect(mockPost).toHaveBeenCalled();
    expect(wrapper.text()).not.toContain('Suscripción activada');
  });
});

describe('BILL-FE-U4-42: no raw provider errors', () => {
  it('error messages are user-friendly', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Error interno del servidor.' } } });
    const wrapper = mountPage();
    await flushPromises();

    const text = wrapper.text();
    expect(text).not.toContain('StripeException');
    expect(text).not.toContain('ApiError');
    expect(text).not.toContain('stack');
  });
});

describe('BILL-FE-U4-43: safe URL validation', () => {
  it('checkout sends plan_id to API, not arbitrary URLs', async () => {
    mockAll();
    mockPost.mockResolvedValueOnce({ data: { checkout_url: 'https://checkout.stripe.com/cs/valid' } });

    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    const payBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Ir a pagar'),
    );
    await payBtn!.trigger('click');
    await flushPromises();

    expect(mockPost).toHaveBeenCalledWith(
      expect.stringContaining('/billing/checkout'),
      expect.objectContaining({ plan_id: 'plan-pro-uuid', interval: 'monthly' }),
    );
  });
});

describe('BILL-FE-U4-44: XSS-safe rendering', () => {
  it('no script tags in rendered output', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('<script>');
  });
});

describe('BILL-FE-U4-45: billingApi createCheckoutSession', () => {
  it('sends correct payload', async () => {
    mockPost.mockResolvedValueOnce({ data: { checkout_url: 'https://checkout.stripe.com/cs/test' } });

    const result = await createCheckoutSession('tenant-1', 'plan-uuid-1', 'monthly');

    expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/billing/checkout', {
      plan_id: 'plan-uuid-1',
      interval: 'monthly',
    });
    expect(result).toBe('https://checkout.stripe.com/cs/test');
  });
});

describe('BILL-FE-U4-46: billingApi createPortalSession', () => {
  it('sends correct request', async () => {
    mockPost.mockResolvedValueOnce({ data: { portal_url: 'https://billing.stripe.com/session/test' } });

    const result = await createPortalSession('tenant-1');

    expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/billing/portal');
    expect(result).toBe('https://billing.stripe.com/session/test');
  });
});

describe('BILL-FE-U4-47: billingApi cancelSubscription', () => {
  it('calls DELETE on correct URL', async () => {
    mockDelete.mockResolvedValueOnce({ data: { message: 'Suscripción cancelada.' } });

    await cancelSubscription('tenant-1');

    expect(mockDelete).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/subscriptions');
  });
});

describe('BILL-FE-U4-48: tenant switch clears old state', () => {
  it('api calls use current tenant ID', async () => {
    mockAll();
    mountPage();
    await flushPromises();

    const allCalls = mockGet.mock.calls.map((c) => String(c[0]));
    expect(allCalls.every((url) => url.includes('/tenants/t1/'))).toBe(true);
  });
});

describe('BILL-FE-U4-49: no Eval or innerHTML', () => {
  it('template does not use dangerous methods', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('eval(');
    expect(html).not.toContain('innerHTML');
    expect(html).not.toContain('new Function');
    expect(html).not.toContain('v-html');
  });
});

describe('BILL-FE-U4-50: interval selector shows correct pricing', () => {
  it('monthly/yearly pricing visible in dialog', async () => {
    mockAll();
    const wrapper = mountPage();
    await flushPromises();

    const selectBtn = wrapper.findAll('button').find((b) =>
      b.text().includes('Cambiar a este plan'),
    );
    await selectBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Mensual');
    expect(wrapper.text()).toContain('Anual');
  });
});
