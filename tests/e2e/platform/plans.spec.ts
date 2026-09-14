import { expect, test } from '@playwright/test';
import { PASSWORD, USERS } from '../helpers/constants';

test.describe('Platform Plans (FASE 36 U4)', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.platformAdmin.storageKey}.json` });

    test('platform admin can review the catalog and open the create form', async ({ page }) => {
        await page.goto('/platform/plans');
        await expect(page.getByRole('heading', { name: 'Plans', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Free', exact: true })).toBeVisible();
        await page.getByTestId('platform-navigation').getByRole('link', { name: 'Plans' }).click();
        await page.getByRole('link', { name: 'Create plan' }).click();
        await expect(page).toHaveURL(/\/platform\/plans\/create$/);
        await expect(page.getByRole('heading', { name: 'Create plan', exact: true })).toBeVisible();
        await expect(page.getByText('No currency field or Stripe network operation is performed.')).toBeVisible();
    });

    test('platform admin can create and edit a synthetic plan', async ({ page }) => {
        await page.goto('/platform/plans/create');
        await page.getByLabel('Name').fill('E2E Managed Plan');
        await page.getByLabel('Slug').fill('e2e-managed-plan');
        await page.getByRole('button', { name: 'Create plan' }).click();
        await expect(page).toHaveURL(/\/platform\/plans\/[^/]+$/);
        await expect(page.getByRole('heading', { name: 'E2E Managed Plan', exact: true })).toBeVisible();

        await page.getByRole('link', { name: 'Edit plan' }).click();
        await page.getByLabel('Reason for change').fill('Enable AI for E2E validation');
        await page.getByLabel('Current password').fill(PASSWORD);
        await page.getByRole('checkbox', { name: 'AI enabled' }).check();
        await page.locator('section').filter({ hasText: 'Limits' }).locator('input[type="number"]').first().fill('250');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('AI enabled: Yes')).toBeVisible();
        await expect(page.locator('dd').filter({ hasText: '250' }).first()).toBeVisible();
    });

    test('tenant admin cannot access platform plans', async ({ browser }) => {
        const context = await browser.newContext({ storageState: `tests/e2e/.auth/${USERS.adminA.storageKey}.json` });
        const page = await context.newPage();
        const response = await page.goto('/platform/plans');
        expect(response?.status()).toBe(403);
        await context.close();
    });
});
