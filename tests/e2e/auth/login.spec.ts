import { expect, test } from '@playwright/test';
import { PASSWORD, USERS } from '../helpers/constants';
import { expectDashboard, loginViaUi } from '../helpers/auth';

const owner = USERS.ownerA;

test.describe('Login (E2E-AUTH-LOGIN)', () => {
    test('verifica que la página de login se renderiza', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('button', { name: 'Iniciar sesión' })).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByLabel('Contraseña')).toBeVisible();
    });

    test('login válido redirige al dashboard y saluda al usuario', async ({ page }) => {
        await loginViaUi(page, owner.email, PASSWORD);
        await expectDashboard(page);
        await expect(page.getByText(`Hola, ${owner.name}`)).toBeVisible();
    });

    test('tras el login se muestra el tenant activo', async ({ page }) => {
        await loginViaUi(page, owner.email, PASSWORD);
        await expectDashboard(page);
        await expect(page.getByText(`${owner.tenantName} active`)).toBeVisible();
    });

    test('credenciales inválidas muestran error de validación y no navegan', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(owner.email);
        await page.getByLabel('Contraseña').fill('clave-incorrecta');
        await page.getByRole('button', { name: 'Iniciar sesión' }).click();

        // El mensaje de error llega tras el POST de login (Inertia). En el
        // servidor E2E (php artisan serve, ~10-20s por request bajo carga) la
        // renderización del error puede superar el `expect` 15s por defecto.
        // Timeout TARGETED solo a esta aserción (espera una respuesta HTTP real).
        await expect(page.getByText('Las credenciales no coinciden con nuestros registros.'))
            .toBeVisible({ timeout: 30_000 });
        await expect(page).toHaveURL(/\/login$/);
    });
});
