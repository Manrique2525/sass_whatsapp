import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE_URL, PASSWORD, USERS } from './helpers/constants';
import { expectDashboard, loginViaUi, pollHealth } from './helpers/auth';

const here = path.dirname(fileURLToPath(import.meta.url));

/**
 * Global setup E2E (FASE 30 / ADR-110).
 *
 * 1. Comprueba que el server E2E responde en /up (health gate).
 * 2. Realiza un login UI por cada usuario de prueba y guarda su storageState
 *    (cookie de sesión + XSRF) en `tests/e2e/.auth/{storageKey}.json`
 *    (gitignored). Los specs autenticados los reutilizan vía `storageState`.
 *
 * Nunca se loguean ni persisten credenciales/secretos reales.
 */
export default async function globalSetup(): Promise<void> {
    await pollHealth();

    const authDir = path.resolve(here, '.auth');
    fs.mkdirSync(authDir, { recursive: true });

    const browser = await chromium.launch();

    try {
        for (const key of Object.keys(USERS)) {
            const user = USERS[key];
            const context = await browser.newContext({ baseURL: BASE_URL });
            const page = await context.newPage();

            await loginViaUi(page, user.email, PASSWORD);
            await expectDashboard(page);

            await context.storageState({ path: path.join(authDir, `${user.storageKey}.json`) });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
