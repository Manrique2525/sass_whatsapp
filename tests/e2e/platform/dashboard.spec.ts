import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

const platformAdmin = USERS.platformAdmin;
const tenantAdmin = USERS.adminA;

test.describe('Platform Dashboard (FASE 36 U6)', () => {
    test.setTimeout(90_000);

    test.describe('super admin', () => {
        test.use({ storageState: `tests/e2e/.auth/${platformAdmin.storageKey}.json` });

    test('can review global metrics and operational widgets', async ({ page }) => {
            await page.goto('/platform', { waitUntil: 'domcontentloaded', timeout: 60_000 });
            await expect(page.getByRole('heading', { name: 'Operations overview' })).toBeVisible();
            await expect(page.getByText('Customers', { exact: true }).first()).toBeVisible();
            await expect(page.getByText('New customers', { exact: true })).toBeVisible();
            await expect(page.getByText('E2E Tenant A', { exact: true }).first()).toBeVisible();
            await expect(page.getByText('Recent platform activity', { exact: true })).toBeVisible();
            await expect(page.locator('a[href^="/platform/customers/"]').filter({ hasText: 'E2E Tenant A' }).first()).toBeVisible();
            await expect(page.locator('a[href^="/platform/plans/"]').filter({ hasText: 'Free' }).first()).toBeVisible();
            await page.getByRole('link', { name: 'Subscriptions', exact: true }).click();
            await expect(page).toHaveURL(/\/platform\/subscriptions$/);
        });

        test('can review security status', async ({ page }) => {
            await page.goto('/platform/security');
            await expect(page.getByRole('heading', { name: 'Multi-factor authentication' })).toBeVisible();
            await expect(page.getByText('MFA enabled', { exact: true })).toBeVisible();
        });

    });

    test.describe('tenant admin', () => {
        test.use({ storageState: `tests/e2e/.auth/${tenantAdmin.storageKey}.json` });

        test('cannot access the global dashboard', async ({ page }) => {
            const response = await page.goto('/platform');
            expect(response?.status()).toBe(403);
        });
    });
});
