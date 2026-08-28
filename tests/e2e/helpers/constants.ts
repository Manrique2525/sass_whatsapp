/**
 * Constantes deterministas del entorno E2E (FASE 30 / ADR-110).
 *
 * Espejo de `database/seeders/E2ETenantSeeder.php`: los UUID de
 * tenants/contactos/conversación y las credenciales de los usuarios de prueba.
 * Mantener sincronizado con el seeder.
 */

export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8082';

/** Contraseña de los usuarios de prueba (misma que el seeder lee de .env.e2e). */
export const PASSWORD = process.env.E2E_TEST_PASSWORD ?? 'e2e-password';

export const TENANT_A_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
export const TENANT_B_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';
export const CONTACT_A_ID = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3';
export const CONTACT_B_ID = 'dddddddd-dddd-4ddd-8ddd-ddddddddddd4';
export const CONVERSATION_A_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee5';

export interface E2EUser {
    email: string;
    name: string;
    role: 'owner' | 'admin' | 'agent';
    tenantId: string;
    tenantName: string;
    /** Clave del archivo de storageState en tests/e2e/.auth/. */
    storageKey: string;
}

export const USERS: Record<string, E2EUser> = {
    ownerA: {
        email: 'owner@e2e.local',
        name: 'E2E Owner A',
        role: 'owner',
        tenantId: TENANT_A_ID,
        tenantName: 'E2E Tenant A',
        storageKey: 'owner-a',
    },
    adminA: {
        email: 'admin@e2e.local',
        name: 'E2E Admin A',
        role: 'admin',
        tenantId: TENANT_A_ID,
        tenantName: 'E2E Tenant A',
        storageKey: 'admin-a',
    },
    agentA: {
        email: 'agent@e2e.local',
        name: 'E2E Agent A',
        role: 'agent',
        tenantId: TENANT_A_ID,
        tenantName: 'E2E Tenant A',
        storageKey: 'agent-a',
    },
    ownerB: {
        email: 'tenantb-owner@e2e.local',
        name: 'E2E Owner B',
        role: 'owner',
        tenantId: TENANT_B_ID,
        tenantName: 'E2E Tenant B',
        storageKey: 'owner-b',
    },
    switchUser: {
        email: 'switch@e2e.local',
        name: 'E2E Switch',
        role: 'owner',
        tenantId: TENANT_A_ID,
        tenantName: 'E2E Tenant A',
        storageKey: 'switch',
    },
};

/** URL del base de los endpoints API tenant-scoped. */
export const apiTenant = (tenantId: string, suffix = ''): string =>
    `/api/v1/tenants/${tenantId}${suffix}`;
