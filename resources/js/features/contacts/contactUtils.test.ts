import { describe, expect, it } from 'vitest';
import {
    buildContactQuery,
    extractErrorMessage,
    hasValidPhoneDigits,
    normalizePhone,
    parseMetadata,
} from './contactUtils';

describe('normalizePhone', () => {
    it('normaliza a E.164 con + y solo dígitos', () => {
        expect(normalizePhone('+54 11 5555 4444')).toBe('+541155554444');
        expect(normalizePhone('54911-5555-4444')).toBe('+5491155554444');
        expect(normalizePhone('(54) 11 5555-4444')).toBe('+541155554444');
    });

    it('formatos equivalentes producen el mismo valor', () => {
        expect(normalizePhone('+54 11 5555 4444')).toBe(normalizePhone('541155554444'));
    });

    it('sin dígitos devuelve cadena vacía', () => {
        expect(normalizePhone('')).toBe('');
        expect(normalizePhone('abc')).toBe('');
        expect(normalizePhone('+-()')).toBe('');
    });
});

describe('hasValidPhoneDigits', () => {
    it('acepta entre 7 y 15 dígitos', () => {
        expect(hasValidPhoneDigits('1234567')).toBe(true);
        expect(hasValidPhoneDigits('+54 11 5555 4444')).toBe(true);
        expect(hasValidPhoneDigits('123456789012345')).toBe(true);
    });

    it('rechaza fuera del rango o sin dígitos', () => {
        expect(hasValidPhoneDigits('123456')).toBe(false);
        expect(hasValidPhoneDigits('1234567890123456')).toBe(false);
        expect(hasValidPhoneDigits('')).toBe(false);
        expect(hasValidPhoneDigits('abc')).toBe(false);
    });
});

describe('buildContactQuery', () => {
    it('omite filtros vacíos', () => {
        expect(buildContactQuery({})).toEqual({});
        expect(buildContactQuery({ search: '', phone: '', email: '' })).toEqual({});
        expect(buildContactQuery({ search: '  ' })).toEqual({});
    });

    it('recorta y normaliza el teléfono antes de enviarlo', () => {
        expect(buildContactQuery({ phone: '  +54 11 5555 4444  ' })).toEqual({
            phone: '+541155554444',
        });
    });

    it('incluye página y tamaño solo cuando corresponden', () => {
        expect(buildContactQuery({ page: 1 })).toEqual({});
        expect(buildContactQuery({ page: 2 })).toEqual({ page: '2' });
        expect(buildContactQuery({ perPage: 15 })).toEqual({ per_page: '15' });
    });

    it('combina todos los filtros', () => {
        expect(
            buildContactQuery({ search: 'ana', phone: '5411', email: 'a@x.com', page: 3, perPage: 25 }),
        ).toEqual({
            search: 'ana',
            phone: '+5411',
            email: 'a@x.com',
            page: '3',
            per_page: '25',
        });
    });
});

describe('extractErrorMessage', () => {
    it('extrae message del error estándar de la API', () => {
        const err = { response: { data: { message: 'Ya existe el contacto.' } } };
        expect(extractErrorMessage(err, 'fallback')).toBe('Ya existe el contacto.');
    });

    it('usa el fallback cuando el error no tiene el formato esperado', () => {
        expect(extractErrorMessage(null, 'fallback')).toBe('fallback');
        expect(extractErrorMessage('boom', 'fallback')).toBe('fallback');
        expect(extractErrorMessage({ response: {} }, 'fallback')).toBe('fallback');
    });
});

describe('parseMetadata', () => {
    it('serializa metadata a JSON', () => {
        expect(parseMetadata({ origen: 'whatsapp' })).toBe('{"origen":"whatsapp"}');
    });

    it('devuelve cadena vacía si no hay metadata', () => {
        expect(parseMetadata(null)).toBe('');
        expect(parseMetadata(undefined)).toBe('');
    });
});
