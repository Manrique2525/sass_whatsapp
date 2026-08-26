/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string;
    readonly VITE_REVERB_HOST?: string;
    readonly VITE_REVERB_PORT?: string;
    readonly VITE_REVERB_SCHEME?: string;
    readonly VITE_REVERB_APP_KEY?: string;
    readonly VITE_SENTRY_DSN?: string;
    readonly VITE_SENTRY_ENVIRONMENT?: string;
    readonly VITE_SENTRY_RELEASE?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
