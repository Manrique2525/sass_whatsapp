import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { USERS } from '../helpers/constants';
import { apiGet, apiPost, readXsrfToken } from '../helpers/auth';

const TENANT_A_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
const TENANT_B_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
const DOCUMENT_TEXT = 'U4 Knowledge soporte disponible de 09:00 a 18:00.';

type InspectorResult = {
    document_exists: boolean;
    status: string | null;
    source_exists: boolean;
    chunk_count: number;
    tenant_ids: string[];
    document_ids: string[];
    embedded_count: number;
    vector_dimensions: number[];
    search_count: number | null;
    search_contents: string[];
    error?: string;
};

function inspect(tenantId: string, knowledgeBaseId: string, documentId: string, query = ''): InspectorResult {
    const output = execFileSync('docker', [
        'compose', '-f', 'docker-compose.e2e.yml', 'exec', '-T', 'e2e-app',
        'php', 'tests/e2e/knowledge/knowledge-inspector.php',
        tenantId, knowledgeBaseId, documentId, query,
    ], { encoding: 'utf8' });

    return JSON.parse(output.trim()) as InspectorResult;
}

async function createKnowledgeBase(page: import('@playwright/test').Page, tenantId: string, name: string): Promise<string> {
    const response = await apiPost(page, `/api/v1/tenants/${tenantId}/knowledge-bases`, { name });
    expect(response.status()).toBe(201);
    return ((await response.json()).knowledge_base.id) as string;
}

async function uploadDocument(page: import('@playwright/test').Page, tenantId: string, knowledgeBaseId: string): Promise<string> {
    const xsrf = await readXsrfToken(page);
    const response = await page.request.post(
        `/api/v1/tenants/${tenantId}/knowledge-bases/${knowledgeBaseId}/documents`,
        {
            headers: {
                Origin: 'http://localhost:8082',
                'X-XSRF-TOKEN': xsrf,
                Accept: 'application/json',
            },
            multipart: {
                file: {
                    name: 'u4-knowledge.txt',
                    mimeType: 'text/plain',
                    buffer: Buffer.from(DOCUMENT_TEXT, 'utf8'),
                },
            },
        },
    );
    expect(response.status()).toBe(201);
    return ((await response.json()).document.id) as string;
}

async function deleteDocument(page: import('@playwright/test').Page, tenantId: string, knowledgeBaseId: string, documentId: string): Promise<void> {
    const xsrf = await readXsrfToken(page);
    const response = await page.request.delete(
        `/api/v1/tenants/${tenantId}/knowledge-bases/${knowledgeBaseId}/documents/${documentId}`,
        {
            headers: {
                Origin: 'http://localhost:8082',
                'X-XSRF-TOKEN': xsrf,
                Accept: 'application/json',
            },
        },
    );
    expect(response.status()).toBe(200);
}

test.describe('Knowledge System Integration E2E-U4', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json` });

    test('procesa, busca, aísla y limpia documentos con worker real', async ({ page, browser }) => {
        test.setTimeout(300_000);
        const tenantBContext = await browser.newContext({
            storageState: `tests/e2e/.auth/${USERS.ownerB.storageKey}.json`,
        });
        const tenantBPage = await tenantBContext.newPage();

        try {
            for (let cycle = 1; cycle <= 3; cycle++) {
                console.log(`Knowledge cycle ${cycle}: creating knowledge bases`);
                const kbA = await createKnowledgeBase(page, TENANT_A_ID, `U4 Knowledge A ${cycle}`);
                const kbB = await createKnowledgeBase(tenantBPage, TENANT_B_ID, `U4 Knowledge B ${cycle}`);
                console.log(`Knowledge cycle ${cycle}: uploading document`);
                const documentId = await uploadDocument(page, TENANT_A_ID, kbA);

                await expect.poll(async () => {
                    const response = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/knowledge-bases/${kbA}/documents/${documentId}`);
                    expect(response.status()).toBe(200);
                    return (await response.json()).document.status as string;
                }, { timeout: 60_000, intervals: [500, 1_000, 2_000] }).toBe('ready');

                console.log(`Knowledge cycle ${cycle}: document ready, inspecting search`);
                const processed = inspect(TENANT_A_ID, kbA, documentId, DOCUMENT_TEXT);
                expect(processed.status).toBe('ready');
                expect(processed.source_exists).toBe(true);
                expect(processed.chunk_count).toBeGreaterThan(0);
                expect(processed.embedded_count).toBe(processed.chunk_count);
                expect(processed.vector_dimensions).toEqual([1536]);
                expect(processed.tenant_ids).toEqual([TENANT_A_ID]);
                expect(processed.document_ids).toEqual([documentId]);
                expect(processed.search_count).toBeGreaterThan(0);
                expect(processed.search_contents.join(' ')).toContain(DOCUMENT_TEXT);

                const wrongKbResponse = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/knowledge-bases/${kbB}/documents/${documentId}`);
                expect(wrongKbResponse.status()).toBe(404);
                const wrongKbXsrf = await readXsrfToken(page);
                const wrongKbDelete = await page.request.delete(
                    `/api/v1/tenants/${TENANT_A_ID}/knowledge-bases/${kbB}/documents/${documentId}`,
                    {
                        headers: {
                            Origin: 'http://localhost:8082',
                            'X-XSRF-TOKEN': wrongKbXsrf,
                            Accept: 'application/json',
                        },
                    },
                );
                expect(wrongKbDelete.status()).toBe(404);

                const crossTenantResponse = await tenantBPage.request.get(
                    `/api/v1/tenants/${TENANT_B_ID}/knowledge-bases/${kbB}/documents/${documentId}`,
                    { headers: { Origin: 'http://localhost:8082', Accept: 'application/json' } },
                );
                expect(crossTenantResponse.status()).toBe(404);

                console.log(`Knowledge cycle ${cycle}: deleting document`);
                await deleteDocument(page, TENANT_A_ID, kbA, documentId);
                const deleted = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/knowledge-bases/${kbA}/documents/${documentId}`);
                expect(deleted.status()).toBe(404);

                const afterDelete = inspect(TENANT_A_ID, kbA, documentId, DOCUMENT_TEXT);
                expect(afterDelete.document_exists).toBe(false);
                expect(afterDelete.source_exists).toBe(false);
                expect(afterDelete.chunk_count).toBe(0);
                expect(afterDelete.search_count).toBe(0);
                console.log(`Knowledge cycle ${cycle}: complete`);
            }
        } finally {
            await tenantBContext.close();
            console.log('Knowledge test context closed');
        }
    });
});
