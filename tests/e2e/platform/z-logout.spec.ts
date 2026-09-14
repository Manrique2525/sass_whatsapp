import { expect, test } from '@playwright/test';
import path from 'node:path';
import { BASE_URL, USERS } from '../helpers/constants';

const platformAdmin = USERS.platformAdmin;

test.describe('Platform logout (FASE 36 U7)', () => {
    test.use({ storageState: `tests/e2e/.auth/${platformAdmin.storageKey}.json` });

    test('logout invalidates an isolated Platform session', async ({ browser }) => {
        const context = await browser.newContext({
            baseURL: BASE_URL,
            storageState: path.join('tests/e2e/.auth', `${platformAdmin.storageKey}.json`),
        });
        const page = await context.newPage();
        await page.goto('/platform/dashboard', { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/\/platform\/dashboard$/);
        await page.getByRole('button', { name: 'Cerrar sesión' }).first().click();
        await expect(page).toHaveURL(/\/$/);
        await page.goto('/platform');
        await expect(page).toHaveURL(/\/login$/);
        await context.close();
    });
});
