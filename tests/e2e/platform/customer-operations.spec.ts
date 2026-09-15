import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

test.describe('Platform customer operations MVP (FASE 42)', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.platformAdmin.storageKey}.json` });

    test('creates, suspends, reactivates, and sends owner verification for a customer', async ({ page }) => {
        await page.goto('/platform/customers');
        await page.goto('/platform/customers/create');
        await expect(page.getByRole('heading', { name: 'Create customer', exact: true })).toBeVisible();

        await page.getByLabel('Business / tenant name').fill('E2E Assisted Customer');
        await page.getByLabel('Owner name').fill('E2E Assisted Owner');
        await page.getByLabel('Owner email').fill(`assisted-owner-${Date.now()}@e2e.local`);
        await page.getByRole('button', { name: 'Create customer', exact: true }).click();

        await expect(page).toHaveURL(/\/platform\/customers\/[0-9a-f-]+$/, { timeout: 60_000 });
        await expect(page.getByRole('heading', { name: 'E2E Assisted Customer', exact: true })).toBeVisible();
        await expect(page.getByText('Free', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Email not verified', { exact: true })).toBeVisible();

        page.on('dialog', (dialog) => dialog.type() === 'prompt' ? dialog.accept('E2E administrative operation') : dialog.accept());
        await page.getByRole('button', { name: 'Suspend tenant', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Reactivate tenant', exact: true })).toBeVisible();

        await page.getByRole('button', { name: 'Reactivate tenant', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Suspend tenant', exact: true })).toBeVisible();

        await page.getByRole('button', { name: 'Resend verification', exact: true }).click();
        await expect(page).toHaveURL(/\/platform\/customers\/[0-9a-f-]+$/);
    });
});
