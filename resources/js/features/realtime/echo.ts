import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo: Echo<'reverb'> | null = null;

export function initEcho(): Echo<'reverb'> | null {
    if (echo !== null) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (key === undefined || key === '') {
        return null;
    }

    window.Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return echo;
}

export function isRealtimeEnabled(): boolean {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    return key !== undefined && key !== '';
}
