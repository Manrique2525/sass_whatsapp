import type { AnalyticsOverviewData } from './analyticsTypes';

export async function fetchAnalyticsOverview(
  tenantId: string,
  from?: string,
  to?: string,
): Promise<AnalyticsOverviewData> {
  const params: Record<string, string> = {};

  if (from) {
    params.from = from;
  }

  if (to) {
    params.to = to;
  }

  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/analytics/overview`, { params });

  return res.data.data as AnalyticsOverviewData;
}
