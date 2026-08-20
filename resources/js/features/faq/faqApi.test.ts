import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchFaqs, createFaq, updateFaq, deleteFaq } from './faqApi';

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

describe('fetchFaqs', () => {
    it('llama al endpoint con los params correctos', async () => {
        mockGet.mockResolvedValueOnce({
            data: {
                data: [{ id: '1', question: 'Horario', answer: '9 a 18' }],
                meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
            },
        });

        const result = await fetchFaqs('tenant-1', { search: '', status: '', page: 1 });

        expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/faqs', {
            params: { page: 1, per_page: 15 },
        });
        expect(result.data).toHaveLength(1);
        expect(result.meta.total).toBe(1);
    });

    it('incluye search y status en params', async () => {
        mockGet.mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } });

        await fetchFaqs('tenant-1', { search: 'horario', status: 'active', page: 2 });

        expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/faqs', {
            params: { page: 2, per_page: 15, search: 'horario', status: 'active' },
        });
    });

    it('propaga errores', async () => {
        mockGet.mockRejectedValueOnce({ response: { data: { message: 'Forbidden' } } });

        await expect(fetchFaqs('tenant-1', { search: '', status: '', page: 1 })).rejects.toBeDefined();
    });
});

describe('createFaq', () => {
    it('POST con payload correcto', async () => {
        mockPost.mockResolvedValueOnce({});

        await createFaq('tenant-1', { question: '¿Horario?', answer: '9 a 18', priority: 50, status: 'active' });

        expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/faqs', {
            question: '¿Horario?',
            answer: '9 a 18',
            priority: 50,
            status: 'active',
        });
    });
});

describe('updateFaq', () => {
    it('PATCH con payload e id', async () => {
        mockPatch.mockResolvedValueOnce({});

        await updateFaq('tenant-1', 'faq-uuid', { question: 'Nuevo', answer: 'Nuevo', priority: 80, status: 'inactive' });

        expect(mockPatch).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/faqs/faq-uuid', {
            question: 'Nuevo',
            answer: 'Nuevo',
            priority: 80,
            status: 'inactive',
        });
    });
});

describe('deleteFaq', () => {
    it('DELETE con id', async () => {
        mockDelete.mockResolvedValueOnce({});

        await deleteFaq('tenant-1', 'faq-uuid');

        expect(mockDelete).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/faqs/faq-uuid');
    });
});
