import { expect, test } from '@playwright/test';

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
