import type { PageProps as BasePageProps } from '@inertiajs/core';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

export interface TenantOption {
    id: string;
    name: string;
    slug: string;
    status: 'active' | 'suspended';
    is_current: boolean;
}

declare module '@inertiajs/core' {
    interface PageProps extends BasePageProps {
        auth: {
            user: AuthUser | null;
            tenants: TenantOption[];
            current_tenant_id: string | null;
        };
        flash: {
            status?: string;
        };
        errors: Record<string, string>;
    }
}
