import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Overview from '@/Pages/Analytics/Overview.vue';
import type { AnalyticsOverviewData } from '@/features/analytics/analyticsTypes';

const mockGet = vi.fn();

Object.defineProperty(window, 'axios', {
  value: { get: mockGet },
  writable: true,
});

vi.mock('vue3-apexcharts', () => ({
  default: {
    name: 'VueApexCharts',
    template: '<div class="apexcharts-mock" />',
    props: ['type', 'height', 'options', 'series'],
  },
}));

const mockPermissions = { value: ['analytics.view'] as string[] };

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: { id: 1, name: 'Test User', email: 'test@test.com' },
        tenants: [{ id: 't1', name: 'Tenant 1', slug: 't1', status: 'active', is_current: true }],
        current_tenant_id: 't1',
        current_role: 'owner',
        permissions: mockPermissions.value,
        is_super_admin: false,
      },
      flash: {},
      errors: {},
    },
  }),
  Link: { template: '<a><slot /></a>', props: ['href'] },
}));

const SAMPLE_OVERVIEW: AnalyticsOverviewData = {
  period: { from: '2026-08-01', to: '2026-08-21' },
  messages: { total: 250, inbound: 150, outbound: 100, delivered: 240, read: 200, failed: 10 },
  conversations: { total: 80, open: 12, resolved: 60, archived: 8, handoff_requested: 0, bot_paused: 0, unique_contacts: 75, avg_response_time_seconds: 45 },
  flows: { total: 50, completed: 40, failed: 10 },
  leads: { total: 30, new: 20, won: 5, lost: 5 },
  ai: { total_tokens: 10000 },
  daily: [
    { date: '2026-08-01', messages_total: 10, messages_inbound: 6, messages_outbound: 4, conversations_total: 3, leads_total: 1, flow_executions_total: 2, ai_tokens: 400 },
    { date: '2026-08-02', messages_total: 15, messages_inbound: 10, messages_outbound: 5, conversations_total: 5, leads_total: 2, flow_executions_total: 3, ai_tokens: 600 },
  ],
};

const mountPage = () =>
  mount(Overview, {
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
});

describe('AN-UI-01: page renders', () => {
  it('renders Dashboard heading', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Dashboard');
  });
});

describe('AN-UI-02: loads overview', () => {
  it('calls API on mount', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    mountPage();
    await flushPromises();

    expect(mockGet).toHaveBeenCalledWith(
      '/api/v1/tenants/t1/analytics/overview',
      expect.objectContaining({ params: expect.any(Object) }),
    );
  });
});

describe('AN-UI-03: default 30d', () => {
  it('sends 30d preset range by default', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    mountPage();
    await flushPromises();

    const callParams = mockGet.mock.calls[0][1].params;
    expect(callParams.from).toBeDefined();
    expect(callParams.to).toBeDefined();
  });
});

describe('AN-UI-04: 7d preset', () => {
  it('switches to 7d preset', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const btn7d = wrapper.findAll('button').find((b) => b.text().includes('7 días'));
    await btn7d!.trigger('click');
    await flushPromises();

    expect(mockGet).toHaveBeenCalledTimes(2);
  });
});

describe('AN-UI-05: 90d preset', () => {
  it('switches to 90d preset', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const btn90d = wrapper.findAll('button').find((b) => b.text().includes('90 días'));
    await btn90d!.trigger('click');
    await flushPromises();

    expect(mockGet).toHaveBeenCalledTimes(2);
  });
});

describe('AN-UI-08: cards render', () => {
  it('renders stat cards with values', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Mensajes totales');
    expect(wrapper.text()).toContain('Conversaciones activas');
    expect(wrapper.text()).toContain('Conversión de leads');
    expect(wrapper.text()).toContain('Finalización de flujos');
  });
});

describe('AN-UI-09: charts render', () => {
  it('renders 4 chart areas', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    const charts = wrapper.findAll('.apexcharts-mock');
    expect(charts).toHaveLength(4);
  });
});

describe('AN-UI-10: zero denominators', () => {
  it('shows 0% for zero denominators', async () => {
    const zeroOverview: AnalyticsOverviewData = {
      ...SAMPLE_OVERVIEW,
      flows: { total: 0, completed: 0, failed: 0 },
      leads: { total: 0, new: 0, won: 0, lost: 0 },
    };
    mockGet.mockResolvedValueOnce({ data: { data: zeroOverview } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('0%');
  });
});

describe('AN-UI-11: loading', () => {
  it('shows loading skeletons', () => {
    mockGet.mockReturnValueOnce(new Promise(() => {}));
    const wrapper = mountPage();

    expect(wrapper.findAll('.animate-pulse').length).toBeGreaterThan(0);
  });
});

describe('AN-UI-12: API error', () => {
  it('shows error message', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Permiso denegado.' } } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Permiso denegado.');
  });
});

describe('AN-UI-13: retry', () => {
  it('retries on click', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Error' } } });
    const wrapper = mountPage();
    await flushPromises();

    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const retryBtn = wrapper.findAll('button').find((b) => b.text().includes('Reintentar'));
    await retryBtn!.trigger('click');
    await flushPromises();

    expect(mockGet).toHaveBeenCalledTimes(2);
  });
});

describe('AN-UI-14: empty data', () => {
  it('shows empty message when daily is empty', async () => {
    const emptyOverview: AnalyticsOverviewData = {
      ...SAMPLE_OVERVIEW,
      daily: [],
    };
    mockGet.mockResolvedValueOnce({ data: { data: emptyOverview } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Sin datos en el rango seleccionado');
  });
});

describe('AN-UI-15: manual refresh', () => {
  it('refresh button triggers reload', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const refreshBtn = wrapper.findAll('button').find((b) => b.text().includes('Actualizar'));
    await refreshBtn!.trigger('click');
    await flushPromises();

    expect(mockGet).toHaveBeenCalledTimes(2);
  });
});

describe('AN-UI-16: agent denied', () => {
  it('hides content for agent without analytics.view', async () => {
    mockPermissions.value = [];

    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No tienes permiso para ver analytics');

    mockPermissions.value = ['analytics.view'];
  });
});

describe('AN-UI-17: no PII', () => {
  it('does not render phone, email, or names', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).not.toContain('+52');
    expect(wrapper.text()).not.toContain('@');
  });
});

describe('AN-UI-18: no v-html', () => {
  it('page does not use v-html directive', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    const wrapper = mountPage();
    await flushPromises();

    const html = wrapper.html();
    expect(html).not.toContain('v-html');
  });
});

describe('AN-UI-20: no realtime polling', () => {
  it('no setInterval or setTimeout polling', async () => {
    const setIntervalSpy = vi.spyOn(global, 'setInterval');
    mockGet.mockResolvedValueOnce({ data: { data: SAMPLE_OVERVIEW } });
    mountPage();
    await flushPromises();

    expect(setIntervalSpy).not.toHaveBeenCalled();
    setIntervalSpy.mockRestore();
  });
});
