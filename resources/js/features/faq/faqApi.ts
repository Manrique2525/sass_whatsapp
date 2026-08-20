import type { FaqFilters, FaqListResponse, FaqPayload } from './faqTypes';

export async function fetchFaqs(tenantId: string, filters: FaqFilters): Promise<FaqListResponse> {
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

  const res = await window.axios.get(`/api/v1/tenants/${tenantId}/faqs`, { params });

  return {
    faqs: res.data.faqs,
    meta: res.data.meta,
  };
}

export async function createFaq(tenantId: string, payload: FaqPayload): Promise<void> {
  await window.axios.post(`/api/v1/tenants/${tenantId}/faqs`, payload);
}

export async function updateFaq(tenantId: string, id: string, payload: FaqPayload): Promise<void> {
  await window.axios.patch(`/api/v1/tenants/${tenantId}/faqs/${id}`, payload);
}

export async function deleteFaq(tenantId: string, id: string): Promise<void> {
  await window.axios.delete(`/api/v1/tenants/${tenantId}/faqs/${id}`);
}
