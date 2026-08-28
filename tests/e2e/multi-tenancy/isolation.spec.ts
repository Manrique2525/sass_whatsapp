import { expect, test } from '@playwright/test';
import {
    CONVERSATION_A_ID,
    TENANT_A_ID,
    TENANT_B_ID,
    USERS,
} from '../helpers/constants';
import { apiGet, apiPost } from '../helpers/auth';

const ownerA = USERS.ownerA;
const switchUser = USERS.switchUser;

test.describe('Aislamiento multi-tenant (E2E-MT)', () => {
    test.describe('owner del tenant A', () => {
        test.use({ storageState: `tests/e2e/.auth/${ownerA.storageKey}.json` });

        test('lee su propia conversación del tenant A (200)', async ({ page }) => {
            const res = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/conversations`);
            expect(res.status()).toBe(200);

            const body = await res.json();
            expect(Array.isArray(body.conversations)).toBe(true);
            expect(body.conversations.some((c: { id: string }) => c.id === CONVERSATION_A_ID)).toBe(true);
        });

        test('NO puede leer las conversaciones del tenant B (404) — aislamiento P0', async ({ page }) => {
            const res = await apiGet(page, `/api/v1/tenants/${TENANT_B_ID}/conversations`);
            expect(res.status()).toBe(404);
        });

        test('NO puede cambiar a un tenant del que no es miembro (404)', async ({ page }) => {
            const res = await apiPost(page, `/api/v1/tenants/${TENANT_B_ID}/switch`, {});
            expect(res.status()).toBe(404);
        });
    });

    test.describe('usuario multi-tenant (switch@e2e.local)', () => {
        test.use({ storageState: `tests/e2e/.auth/${switchUser.storageKey}.json` });

        test('puede cambiar a un tenant del que es miembro (200)', async ({ page }) => {
            const res = await apiPost(page, `/api/v1/tenants/${TENANT_A_ID}/switch`, {});
            expect(res.status()).toBe(200);

            const body = await res.json();
            expect(body.current_tenant_id).toBe(TENANT_A_ID);
        });
    });
});
