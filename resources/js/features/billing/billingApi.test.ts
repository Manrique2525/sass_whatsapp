import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  fetchPlans,
  fetchCurrentSubscription,
  assignPlan,
  changePlan,
  cancelSubscription,
  fetchUsageSummary,
  fetchUsageHistory,
} from './billingApi';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockDelete = vi.fn();

Object.defineProperty(window, 'axios', {
  value: {
    get: mockGet,
    post: mockPost,
    patch: mockPatch,
    delete: mockDelete,
  },
  writable: true,
});

beforeEach(() => {
  vi.clearAllMocks();
});

const SAMPLE_PLAN = {
  id: 'plan-uuid-1',
  slug: 'free',
  name: 'Free',
  description: 'Plan gratuito',
  is_active: true,
  price_monthly: 0,
  price_yearly: 0,
  limits: { messages: 100, ai_tokens: 1000, contacts: 50, flow_executions: 20, users: 2, knowledge_documents: 5 },
  features: {},
  sort_order: 0,
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

const SAMPLE_SUBSCRIPTION = {
  id: 'sub-uuid-1',
  plan: SAMPLE_PLAN,
  status: 'active',
  quantity: 1,
  current_period_start: '2026-08-01T00:00:00Z',
  current_period_end: '2026-09-01T00:00:00Z',
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

const SAMPLE_USAGE = {
  subscription_id: 'sub-uuid-1',
  period_start: '2026-08-01T00:00:00Z',
  period_end: '2026-09-01T00:00:00Z',
  categories: {
    messages: { used: 50, limit: 100, remaining: 50 },
    ai_tokens: { used: 200, limit: 1000, remaining: 800 },
  },
};

describe('BILL-FE-U4-01: fetchPlans', () => {
  it('calls correct URL and returns plans', async () => {
    mockGet.mockResolvedValueOnce({ data: { plans: [SAMPLE_PLAN] } });

    const result = await fetchPlans('tenant-1');

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/plans');
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('plan-uuid-1');
    expect(result[0].name).toBe('Free');
  });
});

describe('BILL-FE-U4-02: fetchCurrentSubscription', () => {
  it('calls correct URL and returns subscription', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });

    const result = await fetchCurrentSubscription('tenant-1');

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/subscriptions');
    expect(result).not.toBeNull();
    expect(result!.status).toBe('active');
    expect(result!.plan.name).toBe('Free');
  });

  it('returns null when no subscription', async () => {
    mockGet.mockResolvedValueOnce({ data: { subscription: null } });

    const result = await fetchCurrentSubscription('tenant-1');

    expect(result).toBeNull();
  });
});

describe('BILL-FE-U4-03: assignPlan', () => {
  it('posts to correct URL with plan_id', async () => {
    mockPost.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });

    const result = await assignPlan('tenant-1', 'plan-uuid-1');

    expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/subscriptions', {
      plan_id: 'plan-uuid-1',
    });
    expect(result.status).toBe('active');
  });
});

describe('BILL-FE-U4-04: changePlan', () => {
  it('patches to correct URL with plan_id', async () => {
    mockPatch.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });

    const result = await changePlan('tenant-1', 'plan-uuid-2');

    expect(mockPatch).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/subscriptions', {
      plan_id: 'plan-uuid-2',
    });
    expect(result.status).toBe('active');
  });
});

describe('BILL-FE-U4-05: cancelSubscription', () => {
  it('calls DELETE on correct URL', async () => {
    mockDelete.mockResolvedValueOnce({ data: { message: 'Suscripción cancelada.' } });

    await cancelSubscription('tenant-1');

    expect(mockDelete).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/subscriptions');
  });
});

describe('BILL-FE-U4-06: fetchUsageSummary', () => {
  it('calls correct URL and returns usage summary', async () => {
    mockGet.mockResolvedValueOnce({ data: { usage: SAMPLE_USAGE } });

    const result = await fetchUsageSummary('tenant-1');

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/usage');
    expect(result.categories.messages.used).toBe(50);
    expect(result.categories.messages.limit).toBe(100);
    expect(result.categories.messages.remaining).toBe(50);
  });
});

describe('BILL-FE-U4-07: fetchUsageHistory', () => {
  it('calls correct URL and returns records with meta', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        usage_records: [
          {
            id: 'rec-1',
            category: 'messages',
            quantity: 5,
            description: 'test message',
            metadata: null,
            recorded_at: '2026-08-15T12:00:00Z',
            created_at: '2026-08-15T12:00:00Z',
          },
        ],
        meta: { current_page: 1, last_page: 3, per_page: 10, total: 25 },
      },
    });

    const result = await fetchUsageHistory('tenant-1', { page: 1, per_page: 10 });

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/usage/history', {
      params: { page: 1, per_page: 10 },
    });
    expect(result.records).toHaveLength(1);
    expect(result.meta.total).toBe(25);
    expect(result.meta.last_page).toBe(3);
  });

  it('sends category filter when provided', async () => {
    mockGet.mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    await fetchUsageHistory('tenant-1', { category: 'messages' });

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/usage/history', {
      params: { category: 'messages' },
    });
  });

  it('omits empty params', async () => {
    mockGet.mockResolvedValueOnce({ data: { usage_records: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } });

    await fetchUsageHistory('tenant-1');

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/usage/history', {
      params: {},
    });
  });
});

describe('BILL-FE-U4-25: security — no tenant_id in payload', () => {
  it('assignPlan sends only plan_id', async () => {
    mockPost.mockResolvedValueOnce({ data: { subscription: SAMPLE_SUBSCRIPTION } });

    await assignPlan('tenant-1', 'plan-uuid-1');

    const callArgs = mockPost.mock.calls[0];
    const body = callArgs[1];
    expect(Object.keys(body)).toEqual(['plan_id']);
    expect(body).not.toHaveProperty('tenant_id');
    expect(body).not.toHaveProperty('user_id');
    expect(body).not.toHaveProperty('status');
  });
});
