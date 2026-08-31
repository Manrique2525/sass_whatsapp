import { expect, Page, Response } from '@playwright/test';

export async function openInbox(page: Page): Promise<void> {
    await page.goto('/settings/conversations');
    await expect(page.getByRole('heading', { name: 'Conversaciones' })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Todas/ })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('Cargando...', { exact: true })).toBeHidden({ timeout: 30_000 });
}

export async function openConversation(page: Page, contactName: string): Promise<void> {
    const conversationButton = page.getByRole('button', { name: new RegExp(contactName) });
    await expect(conversationButton).toBeVisible({ timeout: 60_000 });
    const messagesResponse = waitForConversationMessagesResponse(page);
    await conversationButton.click();
    expect((await messagesResponse).status()).toBe(200);
    await expect(page.getByText(contactName, { exact: true }).last()).toBeVisible();
    await expect(page.getByText('Cargando mensajes...', { exact: true })).toBeHidden({ timeout: 30_000 });
}

export async function waitForInboxRefresh(page: Page): Promise<void> {
    await expect(page.getByText('Cargando...', { exact: true })).toBeHidden({ timeout: 30_000 });
}

export function waitForConversationListResponse(page: Page): Promise<Response> {
    return page.waitForResponse(
        (response) =>
            response.url().includes('/api/v1/tenants/') &&
            response.url().includes('/conversations') &&
            !response.url().includes('/messages') &&
            response.request().method() === 'GET',
    );
}

export function waitForConversationMessagesResponse(page: Page): Promise<Response> {
    return page.waitForResponse(
        (response) =>
            response.url().includes('/api/v1/tenants/') &&
            response.url().includes('/conversations/') &&
            response.url().includes('/messages') &&
            response.request().method() === 'GET',
        { timeout: 60_000 },
    );
}

export async function claimConversation(page: Page): Promise<void> {
    const responsePromise = page.waitForResponse((response) =>
        response.url().includes('/claim') && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Reclamar', exact: true }).click();
    expect((await responsePromise).status()).toBe(200);
}

export async function sendReply(page: Page, body: string): Promise<{ id: string }> {
    await page.getByPlaceholder('Escribi un mensaje...').fill(body);
    const responsePromise = page.waitForResponse((response) =>
        response.url().includes('/messages') && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Enviar mensaje', exact: true }).click();
    const response = await responsePromise;
    const payload = await response.json();
    expect(response.status(), JSON.stringify(payload)).toBe(201);
    await expect(page.getByText(body, { exact: true })).toBeVisible();

    return payload.created_message;
}
