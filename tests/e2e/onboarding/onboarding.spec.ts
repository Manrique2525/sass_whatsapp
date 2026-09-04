import { expect, test } from '@playwright/test';
import { USERS } from '../helpers/constants';

const onboarding = USERS.onboardingUser;

test.describe('Onboarding self-service (E2E-ONB)', () => {
    test.describe('usuario recién provisionado (owner)', () => {
        test.use({ storageState: `tests/e2e/.auth/${onboarding.storageKey}.json` });

        test('el onboarding muestra workspace creado, plan free activo y CTAs reales', async ({ page }) => {
            await page.goto('/onboarding', { timeout: 60_000 });

            // El contenido del onboarding vive en <main>; el nombre de tenant también
            // aparece en el selector/header, así que se acota a main para evitar el
            // strict-mode violation.
            const main = page.locator('main');

            await expect(main.getByText('¡Bienvenido!')).toBeVisible({ timeout: 30_000 });
            await expect(main.getByText(onboarding.tenantName)).toBeVisible();
            await expect(main.getByText('● Creado', { exact: false })).toBeVisible();

            // Plan free con suscripción ACTIVA (la card pasa de "Cargando..." a "Free"/"Activo"
            // cuando responde la API de suscripción).
            await expect(main.getByText('Free')).toBeVisible({ timeout: 30_000 });
            await expect(main.getByText('Activo')).toBeVisible();

            // Workspace recién provisionado: SIN WhatsApp conectado.
            await expect(main.getByText('● Falta conectar WhatsApp', { exact: false })).toBeVisible();

            const connectLink = main.getByRole('link', { name: 'Conectar WhatsApp' });
            await expect(connectLink).toBeVisible();
            await expect(connectLink).toHaveAttribute('href', '/settings/whatsapp');

            const exploreLink = main.getByRole('link', { name: 'Explorar la plataforma' });
            await expect(exploreLink).toBeVisible();
            await expect(exploreLink).toHaveAttribute('href', '/dashboard');
        });
    });
});