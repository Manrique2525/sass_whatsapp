import { expect, test } from '@playwright/test';
import { USERS } from './helpers/constants';

test.describe('U5 dashboard and navigation', () => {
    test.describe('owner', () => {
        test.use({ storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json` });

    test('owner sees dashboard KPIs, Knowledge and setup surfaces', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { name: /Hola, E2E Owner A/ })).toBeVisible();
        await expect(page.getByTestId('authenticated-navigation').getByRole('link', { name: 'Knowledge' })).toBeVisible();
        await expect(page.getByText('E2E Tenant A active')).toBeVisible();

        await page.goto('/settings/knowledge');
        await expect(page.getByRole('heading', { name: 'Knowledge' })).toBeVisible();
        await expect(page.getByText('Nueva base')).toBeVisible();
    });
    });

    test.describe('agent', () => {
        test.use({ storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json` });

    test('agent sees operational navigation without admin surfaces', async ({ page }) => {
        await page.goto('/dashboard');
        const navigation = page.getByTestId('authenticated-navigation');
        await expect(navigation.getByRole('link', { name: 'Conversaciones' })).toBeVisible();
        await expect(navigation.getByRole('link', { name: 'Knowledge' })).toBeVisible();
        await expect(navigation.getByRole('link', { name: 'Usuarios' })).toHaveCount(0);
        await expect(navigation.getByRole('link', { name: 'Analytics' })).toHaveCount(0);
        await expect(navigation.getByRole('link', { name: 'Billing' })).toHaveCount(0);

        await page.goto('/settings/knowledge');
        await expect(page.getByRole('heading', { name: 'Knowledge' })).toBeVisible();
        await expect(page.getByText('Nueva base')).toHaveCount(0);
    });
    });
});
