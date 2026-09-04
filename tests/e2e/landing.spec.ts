import { expect, test } from '@playwright/test';
import { PASSWORD, USERS } from './helpers/constants';
import { expectDashboard, loginViaUi } from './helpers/auth';

test('la landing presenta el producto y sus CTAs', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toContainText('Automatiza WhatsApp');
    await expect(page.getByRole('link', { name: /Empezar gratis/ }).first()).toHaveAttribute('href', '/register');
    await expect(page.locator('#funciones')).toBeVisible();
    await expect(page.getByText('Inbox compartido', { exact: false }).first()).toBeVisible();
});

test('la navegación móvil muestra sus enlaces al abrirse', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');

    const menu = page.getByRole('button', { name: 'Abrir menú' });
    await expect(menu).toBeVisible();
    await menu.click();
    await expect(page.getByTestId('marketing-mobile-menu')).toBeVisible();
    await expect(page.getByTestId('marketing-mobile-menu').getByRole('link', { name: 'Seguridad', exact: true })).toBeVisible();
});

test('el plan Free muestra límites reales y los enlaces legales funcionan', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('#plan')).toContainText('Hasta 100');
    await expect(page.locator('#plan')).toContainText('mensajes');
    await expect(page.locator('#plan')).toContainText('Hasta 50');
    await expect(page.locator('#plan')).toContainText('contactos');
    await expect(page.locator('#plan')).toContainText('La IA no está incluida');
    await expect(page.locator('#plan').getByRole('link', { name: 'Empezar gratis' })).toHaveAttribute('href', '/register');

    await page.getByRole('link', { name: 'Privacidad' }).click();
    await expect(page).toHaveURL(/\/privacy$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Política de privacidad');
    await page.getByRole('link', { name: 'Términos' }).click();
    await expect(page).toHaveURL(/\/terms$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Términos de servicio');
});

test('el usuario autenticado sólo ve CTAs hacia el panel', async ({ page }) => {
    await loginViaUi(page, USERS.ownerA.email, PASSWORD);
    await expectDashboard(page);
    await page.goto('/');

    await expect(page.getByRole('link', { name: 'Ir al panel' }).first()).toBeVisible();
    await expect(page.locator('a[href="/register"]')).toHaveCount(0);
    await page.getByRole('link', { name: 'Ir al panel' }).first().click();
    await expect(page).toHaveURL(/\/dashboard$/);
});
