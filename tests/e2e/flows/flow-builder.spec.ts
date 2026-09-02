import { expect, test } from '@playwright/test';
import { apiGet } from '../helpers/auth';
import { TENANT_A_ID, TENANT_B_ID, USERS } from '../helpers/constants';

async function clickVisibleEdge(page: import('@playwright/test').Page): Promise<void> {
    const path = page.locator('.vue-flow__edge-path').first();
    const box = await path.boundingBox();
    if (!box) {
        throw new Error('El edge no tiene un hit-area visible.');
    }

    for (let column = 1; column < 20; column++) {
        for (let row = 1; row < 20; row++) {
            const point = { x: box.x + (box.width * column) / 20, y: box.y + (box.height * row) / 20 };
            const isEdge = await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest('.vue-flow__edge') !== null, point);
            if (isEdge) {
                await page.mouse.click(point.x, point.y);
                return;
            }
        }
    }

    throw new Error('No se encontró un punto visible del edge para la interacción normal.');
}

test.describe('Flow Builder E2E-U4', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json` });

    test('abre el draft, edita nodos y persiste etiqueta y mensaje', async ({ page }) => {
        test.setTimeout(120_000);
        await page.goto('/settings/flows');

        await expect(page.getByRole('button', { name: 'E2E Flow Builder Bot' })).toBeVisible({ timeout: 60_000 });
        const flowsResponse = page.waitForResponse((response) => response.url().includes('/chatbots/') && response.url().endsWith('/flows') && response.request().method() === 'GET');
        await page.getByRole('button', { name: 'E2E Flow Builder Bot' }).click();
        expect((await flowsResponse).status()).toBe(200);
        await expect(page.getByText('E2E Flow Builder Draft', { exact: true })).toBeVisible({ timeout: 60_000 });
        await page.getByRole('link', { name: 'Abrir editor' }).click();
        await page.waitForURL('**/settings/flows/*/*');
        await expect(page.getByText('E2E Flow Builder Draft', { exact: true })).toBeVisible({ timeout: 60_000 });
        await expect(page.locator('.vue-flow')).toBeVisible({ timeout: 60_000 });

        await page.getByRole('button', { name: '+ Agregar nodo' }).click();
        await expect(page.getByRole('button', { name: /Transferir a humano/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Fin/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /IA/ })).toBeVisible();
        await page.getByRole('button', { name: '+ Agregar nodo' }).click();
        await expect(page.locator('.vue-flow__node-human')).toHaveCount(0);
        await expect(page.locator('.vue-flow__node-ai')).toHaveCount(0);
        await expect(page.locator('.vue-flow__node-end')).toHaveCount(1);

        await page.locator('.vue-flow__node').filter({ hasText: 'Mensaje inicial' }).click();
        await page.getByLabel('Texto del mensaje').fill('Mensaje U4 E2E');

        const edge = page.locator('.vue-flow__edge').first();
        await expect(edge).toHaveCount(1);
        await clickVisibleEdge(page);
        await expect(page.getByText('Salida determinista sin etiqueta.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Rama / etiqueta')).toHaveCount(0);

        await page.locator('.vue-flow__pane').click({ position: { x: 500, y: 500 } });
        await clickVisibleEdge(page);
        await expect(page.getByText('Salida determinista sin etiqueta.', { exact: true })).toBeVisible();

        const saveResponse = page.waitForResponse((response) => response.url().includes('/draft') && response.request().method() === 'PUT');
        await page.getByRole('button', { name: /Guardar/ }).click();
        const saved = await saveResponse;
        expect(saved.status()).toBe(200);
        await expect(page.getByText('Guardado', { exact: true })).toBeVisible();

        const publishResponse = page.waitForResponse(
            (response) => response.url().includes('/publish') && response.request().method() === 'POST',
            { timeout: 60_000 },
        );
        await page.getByRole('button', { name: 'Publicar' }).click();
        expect((await publishResponse).status()).toBe(200);
        await expect(page.getByRole('button', { name: 'Desactivar' })).toBeVisible();

        await page.reload();
        await expect(page.locator('.vue-flow')).toBeVisible({ timeout: 60_000 });
        await expect(page.locator('.vue-flow__node-end')).toHaveCount(1);
        await page.locator('.vue-flow__node-message').filter({ hasText: 'Mensaje U4 E2E' }).click();
        await expect(page.getByLabel('Texto del mensaje')).toHaveValue('Mensaje U4 E2E');
        await clickVisibleEdge(page);
        await expect(page.getByText('Salida determinista sin etiqueta.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Rama / etiqueta')).toHaveCount(0);
    });

    test('mantiene aislamiento y permisos de Flow Builder', async ({ browser }) => {
        const agentContext = await browser.newContext({
            storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json`,
        });
        const agent = await agentContext.newPage();
        const chatbots = await apiGet(agent, `${`/api/v1/tenants/${TENANT_A_ID}`}/chatbots?search=E2E%20Flow%20Builder%20Bot`);
        expect(chatbots.status()).toBe(200);
        const chatbotId = (await chatbots.json()).chatbots[0].id as string;
        const flows = await apiGet(agent, `/api/v1/tenants/${TENANT_A_ID}/chatbots/${chatbotId}/flows`);
        expect(flows.status()).toBe(200);
        const flowId = (await flows.json()).flows[0].id as string;
        const foreign = await apiGet(agent, `/api/v1/tenants/${TENANT_B_ID}/flows/${flowId}`);
        expect(foreign.status()).toBe(404);
        const own = await apiGet(agent, `/api/v1/tenants/${TENANT_A_ID}/flows/${flowId}`);
        expect(own.status()).toBe(200);
        await agentContext.close();
    });
});
