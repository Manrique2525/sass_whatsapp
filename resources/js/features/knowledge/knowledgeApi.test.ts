import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createKnowledgeBase, fetchKnowledgeBases, fetchKnowledgeDocuments, uploadKnowledgeDocument } from './knowledgeApi';

const mockGet = vi.fn();
const mockPost = vi.fn();

Object.defineProperty(window, 'axios', { value: { get: mockGet, post: mockPost }, writable: true });

beforeEach(() => vi.clearAllMocks());

describe('knowledge API client', () => {
    it('loads tenant-scoped bases', async () => {
        mockGet.mockResolvedValueOnce({ data: { knowledge_bases: [], meta: { total: 0 } } });
        await fetchKnowledgeBases('tenant-1');
        expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/knowledge-bases', { params: undefined });
    });

    it('creates a knowledge base', async () => {
        mockPost.mockResolvedValueOnce({ data: { knowledge_base: { id: 'kb-1' } } });
        await createKnowledgeBase('tenant-1', { name: 'Support' });
        expect(mockPost).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/knowledge-bases', { name: 'Support' });
    });

    it('uploads a document to the selected base', async () => {
        mockPost.mockResolvedValueOnce({ data: {} });
        const file = new File(['content'], 'support.txt', { type: 'text/plain' });
        await uploadKnowledgeDocument('tenant-1', 'kb-1', file);
        const formData = mockPost.mock.calls[0][1] as FormData;
        expect(mockPost.mock.calls[0][0]).toBe('/api/v1/tenants/tenant-1/knowledge-bases/kb-1/documents');
        expect(formData.get('file')).toBe(file);
    });

    it('loads documents for the selected base', async () => {
        mockGet.mockResolvedValueOnce({ data: { documents: [], meta: { total: 0 } } });
        await fetchKnowledgeDocuments('tenant-1', 'kb-1');
        expect(mockGet).toHaveBeenCalledWith('/api/v1/tenants/tenant-1/knowledge-bases/kb-1/documents');
    });
});
