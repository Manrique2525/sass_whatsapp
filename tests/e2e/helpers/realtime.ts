import { expect, Page } from '@playwright/test';

export interface RealtimeObserver {
    waitUntilConnected: () => Promise<void>;
    frameCount: () => number;
    waitForFrameAfter: (count: number) => Promise<void>;
}

/** Observa sólo sockets Reverb; no depende de frames internos del protocolo. */
export function observeReverb(page: Page): RealtimeObserver {
    let socketCount = 0;
    let receivedFrames = 0;

    page.on('websocket', (socket) => {
        if (!socket.url().includes('/app/')) {
            return;
        }

        socketCount++;
        socket.on('framereceived', () => {
            receivedFrames++;
        });
    });

    return {
        waitUntilConnected: async (): Promise<void> => {
            await expect.poll(() => socketCount, { timeout: 30_000 }).toBeGreaterThan(0);
        },
        frameCount: (): number => receivedFrames,
        waitForFrameAfter: async (count: number): Promise<void> => {
            await expect.poll(() => receivedFrames, { timeout: 30_000 }).toBeGreaterThan(count);
        },
    };
}
