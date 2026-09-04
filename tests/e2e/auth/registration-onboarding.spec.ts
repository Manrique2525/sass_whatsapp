import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test.describe('Registro self-service (E2E-ONB-CLOSE)', () => {
    test('registra, verifica el email y llega al onboarding provisionado', async ({ page }) => {
        const email = `u1-browser-${Date.now()}@e2e.local`;

        await page.goto('/register', { timeout: 60_000 });
        await page.getByLabel('Nombre').fill('U1 Browser Owner');
        await page.getByLabel('Email').fill(email);
        await page.getByLabel('Contraseña', { exact: true }).fill('e2e-password');
        await page.getByLabel('Confirmar contraseña').fill('e2e-password');
        await page.getByRole('button', { name: 'Registrarse' }).click();

        await page.waitForURL('**/verify-email', { timeout: 60_000, waitUntil: 'commit' });
        await expect(page.getByText('Te hemos enviado un enlace de verificación.')).toBeVisible();

        // El comando E2E genera el mismo enlace firmado que envía la aplicación;
        // la navegación siguiente atraviesa el middleware signed + verified real.
        const verificationUrl = execFileSync(
            'docker',
            [
                'compose',
                '-f',
                'docker-compose.e2e.yml',
                'exec',
                '-T',
                'e2e-app',
                'php',
                'artisan',
                'e2e:verification-url',
                email,
            ],
            { encoding: 'utf8' },
        ).trim();

        expect(verificationUrl).toMatch(/\/email\/verify\//);
        await page.goto(verificationUrl, { timeout: 60_000, waitUntil: 'commit' });
        await page.waitForURL('**/onboarding', { timeout: 60_000, waitUntil: 'commit' });

        const main = page.locator('main');
        await expect(main.getByText('¡Bienvenido!')).toBeVisible();
        await expect(main.getByText('U1 Browser Owner')).toBeVisible();
        await expect(main.getByText('● Creado', { exact: false })).toBeVisible();
        await expect(main.getByText('Free')).toBeVisible({ timeout: 30_000 });
        await expect(main.getByText('Activo')).toBeVisible();
        await expect(main.getByRole('link', { name: 'Conectar WhatsApp' })).toHaveAttribute(
            'href',
            '/settings/whatsapp',
        );
        await expect(main.getByRole('link', { name: 'Explorar la plataforma' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
    });
});
