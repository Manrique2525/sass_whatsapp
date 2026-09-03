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
        // E2E sirve por Nginx hacia PHP-FPM para admitir varias solicitudes
        // simultaneas durante los journeys con varios contextos Reverb.
        // `expect.timeout` se mantiene en 15s: las aserciones post-carga son rapidas.
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
