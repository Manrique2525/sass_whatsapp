import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchAnalyticsOverview } from './analyticsApi';

const mockGet = vi.fn();

Object.defineProperty(window, 'axios', {
  value: {
    get: mockGet,
  },
  writable: true,
});

beforeEach(() => {
  vi.clearAllMocks();
});

const SAMPLE_RESPONSE = {
  data: {
    data: {
      period: { from: '2026-08-01', to: '2026-08-21' },
      messages: { total: 100, inbound: 60, outbound: 40, delivered: 95, read: 80, failed: 5 },
      conversations: { total: 50, open: 10, resolved: 35, archived: 5, handoff_requested: 0, bot_paused: 0, unique_contacts: 45, avg_response_time_seconds: 42 },
      flows: { total: 30, completed: 25, failed: 5 },
      leads: { total: 20, new: 15, won: 3, lost: 2 },
      ai: { total_tokens: 5000 },
      daily: [
        {
          date: '2026-08-01',
          messages_total: 5,
          messages_inbound: 3,
          messages_outbound: 2,
          conversations_total: 2,
          leads_total: 1,
          flow_executions_total: 1,
          ai_tokens: 200,
        },
      ],
    },
  },
};

describe('fetchAnalyticsOverview', () => {
  it('AN-V15: calls correct URL', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    await fetchAnalyticsOverview('tenant-1');

    expect(mockGet).toHaveBeenCalledWith(
      '/api/v1/tenants/tenant-1/analytics/overview',
      { params: {} },
    );
  });

  it('AN-V16: default request sends no params', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    await fetchAnalyticsOverview('tenant-1');

    expect(mockGet).toHaveBeenCalledWith(
      '/api/v1/tenants/tenant-1/analytics/overview',
      { params: {} },
    );
  });

  it('AN-V17: explicit range sends from and to', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    await fetchAnalyticsOverview('tenant-1', '2026-08-01', '2026-08-21');

    expect(mockGet).toHaveBeenCalledWith(
      '/api/v1/tenants/tenant-1/analytics/overview',
      { params: { from: '2026-08-01', to: '2026-08-21' } },
    );
  });

  it('AN-V18: tenant path is correctly interpolated', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    await fetchAnalyticsOverview('abc-xyz-tenant');

    expect(mockGet).toHaveBeenCalledWith(
      '/api/v1/tenants/abc-xyz-tenant/analytics/overview',
      { params: {} },
    );
  });

  it('AN-V19: error propagation', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Forbidden' } } });

    await expect(fetchAnalyticsOverview('tenant-1')).rejects.toBeDefined();
  });

  it('AN-V20: no tenant body/query injection', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    await fetchAnalyticsOverview('tenant-1', undefined, undefined);

    const callArgs = mockGet.mock.calls[0];
    expect(callArgs[0]).toBe('/api/v1/tenants/tenant-1/analytics/overview');
    expect(Object.keys(callArgs[1].params)).toHaveLength(0);
  });

  it('returns typed overview data', async () => {
    mockGet.mockResolvedValueOnce(SAMPLE_RESPONSE);

    const result = await fetchAnalyticsOverview('tenant-1');

    expect(result.period.from).toBe('2026-08-01');
    expect(result.messages.total).toBe(100);
    expect(result.conversations.open).toBe(10);
    expect(result.flows.completed).toBe(25);
    expect(result.leads.won).toBe(3);
    expect(result.ai.total_tokens).toBe(5000);
    expect(result.daily).toHaveLength(1);
  });
});
