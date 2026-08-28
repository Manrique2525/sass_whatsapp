import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

const owner = USERS.ownerA;

test.describe('Rutas protegidas (E2E-AUTH-PROTECTED)', () => {
    test('un invitado es redirigido al login al intentar acceder a /dashboard', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('un invitado es redirigido al login al intentar acceder a una ruta de settings', async ({ page }) => {
        await page.goto('/settings/conversations');
        await expect(page).toHaveURL(/\/login$/);
    });

    test.describe('usuario autenticado (storageState)', () => {
        test.use({ storageState: `tests/e2e/.auth/${owner.storageKey}.json` });

        test('puede acceder al dashboard', async ({ page }) => {
            await page.goto('/dashboard');
            await expect(page.getByText(`Hola, ${owner.name}`)).toBeVisible();
        });

        test('puede acceder a la ruta de conversaciones (inbox)', async ({ page }) => {
            await page.goto('/settings/conversations');
            await expect(page).toHaveURL(/\/settings\/conversations$/);
        });
    });
});
