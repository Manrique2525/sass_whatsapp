import type { PageProps as BasePageProps } from '@inertiajs/core';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

declare module '@inertiajs/core' {
    interface PageProps extends BasePageProps {
        auth: {
            user: AuthUser | null;
        };
        flash: {
            status?: string;
        };
        errors: Record<string, string>;
    }
}
