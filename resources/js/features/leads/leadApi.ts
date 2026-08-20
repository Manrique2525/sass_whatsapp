import type { LeadFilters, LeadListResponse, LeadPayload } from './leadTypes';

export async function fetchLeads(tenantId: string, filters: LeadFilters): Promise<LeadListResponse> {
  const params: Record<string, string | number> = {
    page: filters.page,
    per_page: filters.per_page ?? 15,
  };

  if (filters.search.trim() !== '') {
    params.search = filters.search.trim();
  }

  if (filters.status !== '') {
    params.status = filters.status;
  }

  if (filters.source !== '') {
    params.source = filters.source;
  }

  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/leads`, { params });

  return {
    leads: res.data.leads,
    meta: res.data.meta,
  };
}

export async function createLead(tenantId: string, payload: LeadPayload): Promise<void> {
  await window.axios.post(`/api/v1/tenants/${tenantId}/leads`, payload);
}

export async function updateLead(tenantId: string, id: string, payload: LeadPayload): Promise<void> {
  await window.axios.patch(`/api/v1/tenants/${tenantId}/leads/${id}`, payload);
}

export async function deleteLead(tenantId: string, id: string): Promise<void> {
  await window.axios.delete(`/api/v1/tenants/${tenantId}/leads/${id}`);
}
