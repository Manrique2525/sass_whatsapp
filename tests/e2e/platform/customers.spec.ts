import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

const platformAdmin = USERS.platformAdmin;
const tenantAdmin = USERS.adminA;

test.describe('Platform Customers (FASE 36 U3)', () => {
    test.describe('super admin', () => {
        test.use({ storageState: `tests/e2e/.auth/${platformAdmin.storageKey}.json` });

        test('can open platform and review customers', async ({ page }) => {
            await page.goto('/platform');
            await expect(page.getByText('Platform Admin', { exact: true })).toBeVisible();
        await page.getByTestId('platform-navigation').getByRole('link', { name: 'Clientes' }).click();
            await expect(page).toHaveURL(/\/platform\/customers$/);
            await expect(page.getByRole('link', { name: 'E2E Tenant A', exact: true }).first()).toBeVisible();
            await expect(page.getByRole('link', { name: 'E2E Tenant B', exact: true }).first()).toBeVisible();
        });

        test('can open a tenant detail without selecting a tenant', async ({ page }) => {
            await page.goto('/platform/customers');
            await page.getByRole('link', { name: 'E2E Tenant A', exact: true }).click();
            await expect(page).toHaveURL(/\/platform\/customers\/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1$/);
            await expect(page.getByRole('heading', { name: 'E2E Tenant A' })).toBeVisible();
            await expect(page.getByText('private message content')).toHaveCount(0);
        });
    });

    test.describe('tenant admin', () => {
        test.use({ storageState: `tests/e2e/.auth/${tenantAdmin.storageKey}.json` });

        test('cannot access platform customers', async ({ page }) => {
            const response = await page.goto('/platform/customers');
            expect(response?.status()).toBe(403);
        });
    });
});
