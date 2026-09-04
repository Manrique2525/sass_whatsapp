import { expect, test } from '@playwright/test';
import { PASSWORD, USERS } from '../helpers/constants';
import { expectDashboard, loginViaUi } from '../helpers/auth';

const owner = USERS.ownerA;

test.describe('Logout (E2E-AUTH-LOGOUT)', () => {
    // Este spec hace su propio login por UI (sin storageState) + logout + checks;
    // en el servidor E2E (lento) roza el timeout por defecto de 30 s. Subimos el
    // límite y los timeouts de navegación para absorber la variación de carga y
    // evitar flakiness (el POST de Inertia /logout puede tardar bajo carga).
    test.setTimeout(90_000);

    // NO usa storageState compartido: hace su propio login por UI para no
    // invalidar la sesión de owner que otros specs (protected-routes,
    // multi-tenancy) reutilizan.
    test('cerrar sesión muestra la landing y deja la sesión invalidada', async ({ page }) => {
        await loginViaUi(page, owner.email, PASSWORD);
        await expectDashboard(page);
        await expect(page.getByText(`Hola, ${owner.name}`)).toBeVisible();

        await page.getByRole('button', { name: 'Cerrar sesión' }).click();

        await expect(page).toHaveURL(/\/$/, { timeout: 45_000 });
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Automatiza WhatsApp');
        await expect(page.getByRole('link', { name: 'Iniciar sesión' }).first()).toBeVisible();

        // Tras el logout, un intento de acceso a /dashboard vuelve a redirigir al login.
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/, { timeout: 45_000 });
    });
});
