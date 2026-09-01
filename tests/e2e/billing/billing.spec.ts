import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

test.describe('Billing E2E-U4', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json` });

    test('owner ve plan y ejecuta checkout y portal sinteticos', async ({ page }) => {
        await page.route('https://stripe-e2e.local/**', (route) => route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<title>Stripe E2E boundary</title>',
        }));
        await page.goto('/settings/billing');
        await expect(page.getByRole('heading', { name: 'Billing' })).toBeVisible({ timeout: 60_000 });
        await expect(page.getByText('E2E Paid', { exact: true }).first()).toBeVisible({ timeout: 60_000 });
        await expect(page.getByText('E2E Checkout', { exact: true })).toBeVisible({ timeout: 60_000 });
        await expect(page.getByText('e2e-paid', { exact: true })).toBeVisible({ timeout: 60_000 });

        await page.getByText('E2E Checkout', { exact: true }).locator('..').getByRole('button', { name: 'Cambiar a este plan' }).click();
        await expect(page.getByRole('dialog', { name: 'Cambiar plan' })).toBeVisible({ timeout: 30_000 });
        const checkoutResponsePromise = page.waitForResponse((response) =>
            response.url().includes('/billing/checkout') && response.request().method() === 'POST',
            { timeout: 60_000 },
        );
        await page.getByRole('button', { name: 'Ir a pagar' }).click();
        const checkoutResponse = await checkoutResponsePromise;
        expect(checkoutResponse.ok()).toBeTruthy();
        expect(checkoutResponse.status()).toBe(200);
        await expect(page).toHaveURL(/https:\/\/stripe-e2e\.local\/checkout\/price_e2e_checkout_monthly/, { timeout: 60_000 });
    });

    test('owner abre el portal sintetico', async ({ page }) => {
        await page.route('https://stripe-e2e.local/**', (route) => route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<title>Stripe E2E boundary</title>',
        }));
        await page.goto('/settings/billing');
        await expect(page.getByRole('heading', { name: 'Billing' })).toBeVisible({ timeout: 60_000 });
        const portalButton = page.getByRole('button', { name: 'Gestionar facturación' });
        await expect(portalButton).toBeVisible({ timeout: 30_000 });
        const portalResponsePromise = page.waitForResponse((response) =>
            response.url().includes('/billing/portal') && response.request().method() === 'POST',
            { timeout: 60_000 },
        );
        await portalButton.click();
        const portalResponse = await portalResponsePromise;
        expect(portalResponse.ok()).toBeTruthy();
        expect(portalResponse.status()).toBe(200);
        await expect(page).toHaveURL(/https:\/\/stripe-e2e\.local\/portal\//, { timeout: 60_000 });
    });

    test('admin puede ver pero no gestionar billing', async ({ browser }) => {
        const context = await browser.newContext({
            storageState: `tests/e2e/.auth/${USERS.adminA.storageKey}.json`,
        });
        const admin = await context.newPage();
        await admin.goto('/settings/billing');
        await expect(admin.getByRole('heading', { name: 'Billing' })).toBeVisible({ timeout: 60_000 });
        await expect(admin.getByText('E2E Paid', { exact: true }).first()).toBeVisible({ timeout: 60_000 });
        await expect(admin.getByRole('button', { name: 'Cambiar a este plan' })).toHaveCount(0);
        await expect(admin.getByRole('button', { name: 'Gestionar facturación' })).toHaveCount(0);
        await context.close();
    });

    test('agent no puede ver billing', async ({ browser }) => {
        const context = await browser.newContext({ storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json` });
        const page = await context.newPage();
        await page.goto('/settings/billing');
        await expect(page.getByText('No tienes permiso para ver billing.', { exact: true })).toBeVisible({ timeout: 60_000 });
        await context.close();
    });
});
