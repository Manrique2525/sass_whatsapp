export interface Contact {
    id: string;
    phone: string;
    name: string;
    email: string | null;
    avatar_url: string | null;
    metadata: Record<string, unknown> | null;
    provider_contact_id: string | null;
    last_interaction_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ContactFilters {
    search?: string;
    phone?: string;
    email?: string;
    page?: number;
    perPage?: number;
}

/**
 * Normaliza un teléfono a E.164 canónico con `+` inicial y solo dígitos.
 * Espejo de `ContactService::normalizePhone` (backend): `'+54 11 5555 4444'`
 * y `'5491155554444'` producen el mismo valor.
 */
export function normalizePhone(phone: string): string {
    const digits = phone.replace(/\D/g, '');

    return digits === '' ? '' : `+${digits}`;
}

/**
 * Valida que el teléfono tenga entre 7 y 15 dígitos (misma regla del backend).
 */
export function hasValidPhoneDigits(phone: string): boolean {
    const digits = phone.replace(/\D/g, '');

    return digits.length >= 7 && digits.length <= 15;
}

/**
 * Construye los query params del listado. El `phone` se normaliza antes de
 * enviarlo (el backend filtra por prefijo E.164). Omite filtros vacíos.
 */
export function buildContactQuery(filters: ContactFilters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.search !== undefined && filters.search.trim() !== '') {
        params.search = filters.search.trim();
    }

    if (filters.phone !== undefined && filters.phone.trim() !== '') {
        const normalized = normalizePhone(filters.phone.trim());

        if (normalized !== '') {
            params.phone = normalized;
        }
    }

    if (filters.email !== undefined && filters.email.trim() !== '') {
        params.email = filters.email.trim();
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.page = String(filters.page);
    }

    if (filters.perPage !== undefined && filters.perPage > 0) {
        params.per_page = String(filters.perPage);
    }

    return params;
}

/**
 * Extrae `message` del body de error de la API o devuelve el fallback.
 * (mismo formato de error estándar del backend).
 */
export function extractErrorMessage(err: unknown, fallback: string): string {
    if (
        typeof err === 'object' &&
        err !== null &&
        'response' in err &&
        typeof err.response === 'object' &&
        err.response !== null &&
        'data' in err.response &&
        typeof err.response.data === 'object' &&
        err.response.data !== null &&
        'message' in err.response.data &&
        typeof err.response.data.message === 'string'
    ) {
        return err.response.data.message;
    }

    return fallback;
}

/**
 * Serializa el `metadata` del contacto para mostrarlo en la tabla.
 */
export function parseMetadata(metadata: Record<string, unknown> | null | undefined): string {
    if (metadata === null || metadata === undefined) {
        return '';
    }

    try {
        return JSON.stringify(metadata);
    } catch {
        return '';
    }
}
