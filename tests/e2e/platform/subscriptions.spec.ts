import { expect, test } from '@playwright/test';
import { PASSWORD, USERS } from '../helpers/constants';

test.describe('Platform Subscriptions (FASE 36 U5)', () => {
    test.describe('super admin', () => {
        test.use({ storageState: `tests/e2e/.auth/${USERS.platformAdmin.storageKey}.json` });

        test('can review subscriptions, preview and apply a local plan change', async ({ page }) => {
            await page.goto('/platform/subscriptions');
        await expect(page.getByRole('heading', { name: 'Subscriptions', exact: true })).toBeVisible();
            await expect(page.getByRole('link', { name: 'E2E Tenant A', exact: true })).toBeVisible();
            await page.getByRole('link', { name: 'E2E Tenant A', exact: true }).click();
            await page.getByRole('link', { name: 'Change plan' }).first().click();
            await expect(page.getByRole('heading', { name: 'Change plan', exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Target plan' }).click();
            await page.getByRole('option', { name: /E2E Checkout/ }).click();
            await expect(page.getByText('Impact preview', { exact: true })).toBeVisible();
            await page.getByLabel('Reason for change').fill('E2E administrative adjustment');
            await page.getByLabel('Current password').fill(PASSWORD);
            page.once('dialog', (dialog) => dialog.accept());
            await page.getByRole('button', { name: 'Confirm plan change' }).click();
            await expect(page).toHaveURL(/\/platform\/customers\/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1$/);
            await expect(page.getByText('E2E Checkout', { exact: true })).toBeVisible();
            await expect(page.getByText('platform.subscription.plan_changed', { exact: true })).toBeVisible();
        });
    });

    test('tenant admin cannot access subscriptions or change plan', async ({ browser }) => {
        const context = await browser.newContext({ storageState: `tests/e2e/.auth/${USERS.adminA.storageKey}.json` });
        const page = await context.newPage();
        expect((await page.goto('/platform/subscriptions'))?.status()).toBe(403);
        expect((await page.goto('/platform/customers/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1/subscription/edit'))?.status()).toBe(403);
        await context.close();
    });
});
