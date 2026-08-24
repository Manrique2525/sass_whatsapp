import type {
  Plan,
  Subscription,
  UsageSummary,
  UsageRecord,
  UsageHistoryMeta,
} from './billingTypes';

export async function fetchPlans(tenantId: string): Promise<Plan[]> {
  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/plans`);
  return res.data.plans as Plan[];
}

export async function fetchCurrentSubscription(
  tenantId: string,
): Promise<Subscription | null> {
  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/subscriptions`);
  return res.data.subscription as Subscription | null;
}

export async function assignPlan(
  tenantId: string,
  planId: string,
): Promise<Subscription> {
  const res = await window.axios.post(`/api/v1/tenants/${tenantId}/subscriptions`, {
    plan_id: planId,
  });
  return res.data.subscription as Subscription;
}

export async function changePlan(
  tenantId: string,
  planId: string,
): Promise<Subscription> {
  const res = await window.axios.patch(`/api/v1/tenants/${tenantId}/subscriptions`, {
    plan_id: planId,
  });
  return res.data.subscription as Subscription;
}

export async function cancelSubscription(tenantId: string): Promise<void> {
  await window.axios.delete(`/api/v1/tenants/${tenantId}/subscriptions`);
}

export async function fetchUsageSummary(tenantId: string): Promise<UsageSummary> {
  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/usage`);
  return res.data.usage as UsageSummary;
}

export interface UsageHistoryResult {
  records: UsageRecord[];
  meta: UsageHistoryMeta;
}

export async function fetchUsageHistory(
  tenantId: string,
  params: { page?: number; per_page?: number; category?: string } = {},
): Promise<UsageHistoryResult> {
  const queryParams: Record<string, string | number> = {};

  if (params.page) {
    queryParams.page = params.page;
  }

  if (params.per_page) {
    queryParams.per_page = params.per_page;
  }

  if (params.category) {
    queryParams.category = params.category;
  }

  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/usage/history`, {
    params: queryParams,
  });

  return {
    records: res.data.usage_records as UsageRecord[],
    meta: res.data.meta as UsageHistoryMeta,
  };
}
