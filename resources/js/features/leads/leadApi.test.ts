import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchLeads, createLead, updateLead, deleteLead } from './leadApi';

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

describe('fetchLeads', () => {
  it('LEAD-V14: calls endpoint with correct params', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        leads: [{ id: '1', name: 'Juan', status: 'new' }],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
      },
    });

    const result = await fetchLeads('tenant-1', { search: '', status: '', source: '', page: 1 });

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads', {
      params: { page: 1, per_page: 15 },
    });
    expect(result.leads).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('includes search, status, and source in params', async () => {
    mockGet.mockResolvedValueOnce({ data: { leads: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } });

    await fetchLeads('tenant-1', { search: 'juan', status: 'new', source: 'web', page: 2 });

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads', {
      params: { page: 2, per_page: 15, search: 'juan', status: 'new', source: 'web' },
    });
  });

  it('propagates errors', async () => {
    mockGet.mockRejectedValueOnce({ response: { data: { message: 'Forbidden' } } });

    await expect(fetchLeads('tenant-1', { search: '', status: '', source: '', page: 1 })).rejects.toBeDefined();
  });
});

describe('createLead', () => {
  it('LEAD-V15: POST with correct payload', async () => {
    mockPost.mockResolvedValueOnce({});

    await createLead('tenant-1', { name: 'Juan Pérez', phone: '+529931234567' });

    expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads', {
      name: 'Juan Pérez',
      phone: '+529931234567',
    });
  });
});

describe('updateLead', () => {
  it('LEAD-V16: PATCH with payload and id', async () => {
    mockPatch.mockResolvedValueOnce({});

    await updateLead('tenant-1', 'lead-uuid', { name: 'Nuevo Nombre', status: 'contacted' });

    expect(mockPatch).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads/lead-uuid', {
      name: 'Nuevo Nombre',
      status: 'contacted',
    });
  });
});

describe('deleteLead', () => {
  it('LEAD-V17: DELETE with id', async () => {
    mockDelete.mockResolvedValueOnce({});

    await deleteLead('tenant-1', 'lead-uuid');

    expect(mockDelete).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads/lead-uuid');
  });
});

describe('tenant URL construction', () => {
  it('LEAD-V18: proper tenant URL for all operations', async () => {
    mockGet.mockResolvedValueOnce({ data: { leads: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } });
    mockPost.mockResolvedValueOnce({});
    mockPatch.mockResolvedValueOnce({});
    mockDelete.mockResolvedValueOnce({});

    const tid = 'abc-123-tenant';

    await fetchLeads(tid, { search: '', status: '', source: '', page: 1 });
    await createLead(tid, { name: 'Test' });
    await updateLead(tid, 'lead-1', { name: 'Test' });
    await deleteLead(tid, 'lead-1');

    expect(mockGet).toHaveBeenCalledWith(`/api/v1/tenants/${tid}/leads`, expect.anything());
    expect(mockPost).toHaveBeenCalledWith(`/api/v1/tenants/${tid}/leads`, expect.anything());
    expect(mockPatch).toHaveBeenCalledWith(`/api/v1/tenants/${tid}/leads/lead-1`, expect.anything());
    expect(mockDelete).toHaveBeenCalledWith(`/api/v1/tenants/${tid}/leads/lead-1`);
  });
});

describe('filter serialization', () => {
  it('LEAD-V19: source filter serialization', async () => {
    mockGet.mockResolvedValueOnce({ data: { leads: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } });

    await fetchLeads('tenant-1', { search: '', status: '', source: 'whatsapp', page: 1 });

    expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/leads', {
      params: { page: 1, per_page: 15, source: 'whatsapp' },
    });
  });
});
