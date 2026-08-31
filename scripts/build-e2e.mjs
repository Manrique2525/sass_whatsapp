import { spawnSync } from 'node:child_process';

const e2eViteEnv = {
    VITE_REVERB_APP_KEY: 'whatsapp-saas-e2e-key',
    VITE_REVERB_HOST: 'localhost',
    VITE_REVERB_PORT: '8083',
    VITE_REVERB_SCHEME: 'http',
};

for (const [name, value] of Object.entries(e2eViteEnv)) {
    if (value === '') {
        throw new Error(`Missing required E2E Vite variable: ${name}`);
    }
}

const result = spawnSync(process.execPath, ['node_modules/vite/bin/vite.js', 'build'], {
    env: { ...process.env, ...e2eViteEnv },
    stdio: 'inherit',
});

if (result.error !== undefined) {
    throw result.error;
}

process.exit(result.status ?? 1);
