import { defineConfig, devices } from '@playwright/test';

/**
 * Configuración E2E (Playwright) — FASE 30 / ADR-110.
 *
 * Apunta al entorno E2E dedicado (docker-compose.e2e.yml): el app sirve en el
 * puerto 8082 con APP_ENV=e2e, BD *_e2e_test y Redis índice 15. Nunca contra
 * local/production/testing.
 *
 * - workers=1 y retries=0: los specs dependen del estado del servidor E2E y de
 *   storageState compartidos; el paralelismo/flaky añade ruido sin valor.
 * - Chromium únicamente: el objetivo de esta fase es auth + aislamiento tenant.
 * - trace/screenshot solo al fallar; video off (sin residuos innecesarios).
 */
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    outputDir: './test-results/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8082',
        headless: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        // El entorno E2E sirve con `php artisan serve` (PHP built-in server,
        // SAPI CLI). Medido: cada request tarda 2-10s porque la OPcache CLI no
        // persiste entre los workers `php -S` del pool, así que cada request
        // recompila/bootstrap Laravel. Un login completo son 22s (cálido) y
        // hasta 45s bajo carga (global-setup hace 5 logins). El 60s de
        // navegación absorbe esa latencia determinista (fase A/E), NO flakiness.
        // `expect.timeout` se mantiene en 15s: las aserciones post-carga son rápidas.
        navigationTimeout: 60_000,
        expect: {
            timeout: 15_000,
        },
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
