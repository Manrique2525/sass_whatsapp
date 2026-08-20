import { describe, expect, it } from 'vitest';
import {
    buildFaqQuery,
    extractErrorMessage,
    statusLabel,
    buildFaqPayload,
} from './faqUtils';

describe('statusLabel', () => {
    it('devuelve "Activa" para active', () => {
        expect(statusLabel('active')).toBe('Activa');
    });

    it('devuelve "Inactiva" para inactive', () => {
        expect(statusLabel('inactive')).toBe('Inactiva');
    });
});

describe('buildFaqQuery', () => {
    it('omite search y status vacíos', () => {
        expect(buildFaqQuery({ search: '', status: '', page: 1 })).toEqual({ page: 1 });
    });

    it('incluye search cuando tiene valor', () => {
        expect(buildFaqQuery({ search: 'horario', status: '', page: 1 })).toEqual({
            page: 1,
            search: 'horario',
        });
    });

    it('incluye status cuando tiene valor', () => {
        expect(buildFaqQuery({ search: '', status: 'active', page: 1 })).toEqual({
            page: 1,
            status: 'active',
        });
    });

    it('incluye ambos filtros', () => {
        expect(buildFaqQuery({ search: 'agenda', status: 'inactive', page: 2 })).toEqual({
            page: 2,
            search: 'agenda',
            status: 'inactive',
        });
    });

    it('incluye per_page cuando está definido', () => {
        expect(buildFaqQuery({ search: '', status: '', page: 1, per_page: 50 })).toEqual({
            page: 1,
            per_page: 50,
        });
    });
});

describe('buildFaqPayload', () => {
    it('recorta question y answer', () => {
        const payload = buildFaqPayload({
            question: '  ¿Qué hora abren?  ',
            answer: '  Abrimos de 9 a 18  ',
            priority: 50,
            status: 'active',
        });

        expect(payload.question).toBe('¿Qué hora abren?');
        expect(payload.answer).toBe('Abrimos de 9 a 18');
    });

    it('clampa priority entre 0 y 100', () => {
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: 150, status: 'active' }).priority).toBe(100);
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: -5, status: 'active' }).priority).toBe(0);
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: 75, status: 'active' }).priority).toBe(75);
    });

    it('acepta status active y inactive', () => {
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: 10, status: 'active' }).status).toBe('active');
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: 10, status: 'inactive' }).status).toBe('inactive');
    });

    it('default a active para valores no reconocidos', () => {
        expect(buildFaqPayload({ question: 'Q', answer: 'A', priority: 10, status: 'otro' }).status).toBe('active');
    });
});

describe('extractErrorMessage', () => {
    it('extrae message de respuesta Axios', () => {
        const err = {
            response: {
                data: { message: 'El campo question es obligatorio.' },
            },
        };
        expect(extractErrorMessage(err, 'fallback')).toBe('El campo question es obligatorio.');
    });

    it('devuelve fallback si no hay response', () => {
        expect(extractErrorMessage(new Error('fail'), 'fallback')).toBe('fallback');
    });

    it('devuelve fallback si response.data no tiene message', () => {
        expect(extractErrorMessage({ response: { data: {} } }, 'fallback')).toBe('fallback');
    });

    it('devuelve fallback para null', () => {
        expect(extractErrorMessage(null, 'fallback')).toBe('fallback');
    });

    it('devuelve fallback para undefined', () => {
        expect(extractErrorMessage(undefined, 'fallback')).toBe('fallback');
    });
});
