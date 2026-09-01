import { expect, test } from '@playwright/test';
import { apiGet } from '../helpers/auth';
import { CONVERSATION_A_ID, TENANT_A_ID, TENANT_B_ID, USERS } from '../helpers/constants';
import { openConversation, openInbox, waitForConversationListResponse } from '../helpers/inbox';

test.describe('Inbox E2E-U2', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json` });

    test('lista, abre una conversación y muestra su historial', async ({ page }) => {
        await openInbox(page);
        await expect(page.getByRole('button', { name: /María A/ })).toBeVisible();
        await expect(page.getByText('Perfecto, muchas gracias.', { exact: true })).toBeVisible();

        await openConversation(page, 'María A');
        await expect(page.getByText('Hola, ¿me ayudan?', { exact: true })).toBeVisible();
        await expect(page.getByText('¿Tienen el plan pro?', { exact: true })).toBeVisible();
    });

    test('filtra por búsqueda, estado y scopes del inbox', async ({ page }) => {
        test.setTimeout(120_000);
        await openInbox(page);

        const search = page.getByPlaceholder('Buscar por nombre o telefono');
        await search.fill('Juan A2');
        const searchResponse = waitForConversationListResponse(page);
        await search.press('Enter');
        expect((await searchResponse).status()).toBe(200);
        await expect(page.getByRole('button', { name: /Juan A2/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /María A/ })).toHaveCount(0);

        const clearResponse = waitForConversationListResponse(page);
        await page.getByRole('button', { name: 'Limpiar', exact: true }).click();
        expect((await clearResponse).status()).toBe(200);
        const mineResponse = waitForConversationListResponse(page);
        await page.getByRole('tab', { name: /Mias/ }).click();
        expect((await mineResponse).status()).toBe(200);
        await expect(page.getByRole('button', { name: /Juan A2/ })).toHaveCount(0);

        const unassignedResponse = waitForConversationListResponse(page);
        await page.getByRole('tab', { name: /Sin asignar/ }).click();
        expect((await unassignedResponse).status()).toBe(200);
        await expect(page.getByRole('button', { name: /Rosa Handoff/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /María A/ })).toHaveCount(0);
    });

    test('preserva aislamiento de conversaciones del tenant B', async ({ page }) => {
        const own = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/conversations/${CONVERSATION_A_ID}`);
        expect(own.status()).toBe(200);

        const other = await apiGet(page, `/api/v1/tenants/${TENANT_B_ID}/conversations`);
        expect(other.status()).toBe(404);
    });
});
