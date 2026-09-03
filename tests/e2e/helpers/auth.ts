import { Page } from '@playwright/test';
import { BASE_URL, PASSWORD } from './constants';

/**
 * Helpers de autenticación y utilidades HTTP del entorno E2E (FASE 30/ADR-110).
 */

/** Espera a que el server E2E responda en /up (sin sleeps); lanza si agota. */
export async function pollHealth(): Promise<void> {
    const deadline = Date.now() + 60_000;

    while (Date.now() < deadline) {
        try {
            const res = await fetch(`${BASE_URL}/up`);
            if (res.ok) {
                return;
            }
        } catch {
            // servidor aún no listo
        }
        await new Promise((resolve) => setTimeout(resolve, 1_000));
    }

    throw new Error(`E2E server no responde en ${BASE_URL}/up. ¿Levantaste docker-compose.e2e.yml y corriste e2e:setup?`);
}

/** Realiza el login por UI (formulario Inertia) usando el email dado. */
export async function loginViaUi(page: Page, email: string, password = PASSWORD): Promise<void> {
    await page.goto('/login', { timeout: 60_000, waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Contraseña').fill(password);
    await page.getByRole('button', { name: 'Iniciar sesión' }).click();
}

/** Espera a que la navegación llegue al dashboard (tras el login). */
export async function expectDashboard(page: Page): Promise<void> {
    await page.waitForURL('**/dashboard', { timeout: 60_000, waitUntil: 'commit' });
}

/**
 * GET autenticado compartiendo la sesión del contexto del navegador.
 *
 * Se envía `Origin` para que Sanctum (`EnsureFrontendRequestsAreStateful`)
 * trate la petición como estatal (mismo origen) y autentique vía la cookie de
 * sesión; sin cabecera de origen la trata como stateless y responde 401.
 */
export function apiGet(page: Page, path: string) {
    return page.request.get(path, {
        headers: {
            'Origin': BASE_URL,
            'Accept': 'application/json',
        },
    });
}

/**
 * POST autenticado con el header CSRF (X-XSRF-TOKEN) que Laravel/Sanctum espera
 * para peticiones stateful. El token se lee de la cookie `XSRF-TOKEN`.
 */
export async function apiPost<TBody>(page: Page, path: string, body: TBody) {
    const xsrf = await readXsrfToken(page);

    return page.request.post(path, {
        headers: {
            'Origin': BASE_URL,
            'X-XSRF-TOKEN': xsrf,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        data: body,
    });
}

/** Lee la cookie XSRF-TOKEN (decodificada) del contexto del navegador. */
export async function readXsrfToken(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const tokenCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');

    if (!tokenCookie) {
        throw new Error('No se encontró la cookie XSRF-TOKEN. ¿Sesión autenticada?');
    }

    return decodeURIComponent(tokenCookie.value);
}
