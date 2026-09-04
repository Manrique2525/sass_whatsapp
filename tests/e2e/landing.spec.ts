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
    await page.setViewportSize({ width: 375, height: 844 });
    await page.goto('/');

    const menu = page.locator('button[aria-controls="marketing-menu"]');
    await expect(menu).toBeVisible();
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toHaveAttribute('aria-controls', 'marketing-menu');
    await menu.focus();
    await menu.click();
    await expect(page.getByTestId('marketing-mobile-menu')).toBeVisible();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('marketing-mobile-menu').getByRole('link', { name: 'Seguridad', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('marketing-mobile-menu')).toBeHidden();
    await expect(menu).toBeFocused();
});

test('la landing publica metadata SEO absoluta y no desborda en móvil', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');

    await expect(page).toHaveTitle(/Plataforma de automatización para WhatsApp \| WhatsApp SaaS/);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /WhatsApp Business/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /^https?:\/\//);
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', /^https?:\/\//);
    await expect(page.locator('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary');
    expect(await page.locator('script[type="application/ld+json"]').textContent()).toContain('WebApplication');
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('sitemap y robots son accesibles públicamente', async ({ request }) => {
    const sitemap = await request.get('/sitemap.xml');
    expect(sitemap.ok()).toBe(true);
    expect(sitemap.headers()['content-type']).toContain('application/xml');
    expect(await sitemap.text()).toContain('http');
    expect(await sitemap.text()).not.toContain('/dashboard');

    const robots = await request.get('/robots.txt');
    expect(robots.ok()).toBe(true);
    expect(await robots.text()).toContain('Sitemap: http');
});

test('la landing permanece visible con movimiento reducido', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('#plan')).toBeVisible();
    await expect(page.locator('#faq')).toBeVisible();
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
