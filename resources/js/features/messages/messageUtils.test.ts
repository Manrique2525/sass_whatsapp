import { describe, expect, it } from 'vitest';
import type { Message } from './messageTypes';
import {
    applyMessageUpdate,
    buildMessageQuery,
    dayKey,
    formatMessageTimestamp,
    groupMessagesByDay,
    isNearBottom,
    isOutbound,
    mergeIncomingMessage,
    messageDayLabel,
    messagePreview,
    messageStatusLabel,
} from './messageUtils';

function makeMessage(overrides: Partial<Message> = {}): Message {
    return {
        id: 'msg-1',
        conversation_id: 'conv-1',
        provider_message_id: null,
        direction: 'inbound',
        type: 'text',
        status: 'sent',
        body: 'Hola',
        media_url: null,
        media_mime: null,
        media_size: null,
        metadata: null,
        sent_at: null,
        delivered_at: null,
        read_at: null,
        failed_at: null,
        created_at: '2026-08-14T12:00:00.000000Z',
        updated_at: '2026-08-14T12:00:00.000000Z',
        ...overrides,
    };
}

describe('buildMessageQuery', () => {
    it('omite page 1', () => {
        expect(buildMessageQuery(1)).toEqual({});
    });

    it('incluye page mayor a 1', () => {
        expect(buildMessageQuery(2)).toEqual({ page: '2' });
    });
});

describe('isNearBottom', () => {
    it('detecta proximidad al final', () => {
        expect(isNearBottom(900, 1000, 100)).toBe(true);
        expect(isNearBottom(0, 1000, 100)).toBe(false);
        expect(isNearBottom(780, 1000, 100)).toBe(true);
        expect(isNearBottom(779, 1000, 100)).toBe(false);
    });
});

describe('formatMessageTimestamp', () => {
    it('formatea hora local HH:MM', () => {
        const value = formatMessageTimestamp('2026-08-14T15:05:00.000000Z');
        expect(value).toMatch(/^\d{2}:\d{2}$/);
    });

    it('devuelve el valor si la fecha es inválida', () => {
        expect(formatMessageTimestamp('no-fecha')).toBe('no-fecha');
    });
});

describe('dayKey', () => {
    it('genera clave YYYY-MM-DD', () => {
        expect(dayKey('2026-08-14T12:00:00.000000Z')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('devuelve el valor si la fecha es inválida', () => {
        expect(dayKey('no-fecha')).toBe('no-fecha');
    });
});

describe('messageDayLabel', () => {
    it('marca mensajes de hoy', () => {
        const today = new Date();
        const iso = today.toISOString();
        expect(messageDayLabel(iso)).toBe('Hoy');
    });

    it('marca mensajes de ayer', () => {
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        expect(messageDayLabel(yesterday.toISOString())).toBe('Ayer');
    });

    it('devuelve el valor si la fecha es inválida', () => {
        expect(messageDayLabel('no-fecha')).toBe('no-fecha');
    });
});

describe('groupMessagesByDay', () => {
    it('agrupa por día manteniendo el orden', () => {
        const messages = [
            makeMessage({ id: 'b', created_at: '2026-08-13T12:00:00.000000Z' }),
            makeMessage({ id: 'a', created_at: '2026-08-14T12:00:00.000000Z' }),
            makeMessage({ id: 'c', created_at: '2026-08-14T13:00:00.000000Z' }),
        ];

        const groups = groupMessagesByDay(messages);

        expect(groups).toHaveLength(2);
        expect(groups[0].items.map((m) => m.id)).toEqual(['b']);
        expect(groups[1].items.map((m) => m.id)).toEqual(['a', 'c']);
    });

    it('devuelve lista vacía sin mensajes', () => {
        expect(groupMessagesByDay([])).toEqual([]);
    });
});

describe('mergeIncomingMessage', () => {
    it('anexa el mensaje entrante', () => {
        const base = makeMessage({ id: 'a' });
        const incoming = makeMessage({ id: 'b', created_at: '2026-08-14T13:00:00.000000Z' });

        const merged = mergeIncomingMessage([base], incoming);

        expect(merged.map((m) => m.id)).toEqual(['a', 'b']);
    });

    it('no duplica mensajes ya presentes', () => {
        const base = makeMessage({ id: 'a' });

        const merged = mergeIncomingMessage([base], base);

        expect(merged).toHaveLength(1);
    });

    it('mantiene el orden cronológico ASC', () => {
        const base = makeMessage({ id: 'a', created_at: '2026-08-14T12:00:00.000000Z' });
        const incoming = makeMessage({ id: 'b', created_at: '2026-08-14T11:00:00.000000Z' });

        const merged = mergeIncomingMessage([base], incoming);

        expect(merged.map((m) => m.id)).toEqual(['b', 'a']);
    });
});

describe('applyMessageUpdate', () => {
    it('reemplaza el mensaje por id', () => {
        const base = makeMessage({ id: 'a', status: 'sent' });
        const updated = makeMessage({ id: 'a', status: 'delivered' });

        const result = applyMessageUpdate([base], updated);

        expect(result[0].status).toBe('delivered');
    });

    it('deja intactos los demás mensajes', () => {
        const other = makeMessage({ id: 'b' });
        const updated = makeMessage({ id: 'a', status: 'read' });

        const result = applyMessageUpdate([other], updated);

        expect(result).toEqual([other]);
    });
});

describe('messagePreview', () => {
    it('devuelve cuerpo de texto', () => {
        expect(messagePreview(makeMessage({ body: 'Hola mundo' }))).toBe('Hola mundo');
    });

    it('devuelve etiqueta para mensajes multimedia', () => {
        expect(messagePreview(makeMessage({ body: null, type: 'image', media_url: 'http://x/y.jpg' }))).toBe('[imagen]');
    });

    it('devuelve string vacío sin mensaje', () => {
        expect(messagePreview(null)).toBe('');
    });
});

describe('isOutbound', () => {
    it('distingue dirección', () => {
        expect(isOutbound(makeMessage({ direction: 'outbound' }))).toBe(true);
        expect(isOutbound(makeMessage({ direction: 'inbound' }))).toBe(false);
    });
});

describe('messageStatusLabel', () => {
    it('mapea todos los estados', () => {
        expect(messageStatusLabel('pending')).toBe('Pendiente');
        expect(messageStatusLabel('sending')).toBe('Enviando');
        expect(messageStatusLabel('sent')).toBe('Enviado');
        expect(messageStatusLabel('delivered')).toBe('Entregado');
        expect(messageStatusLabel('read')).toBe('Leido');
        expect(messageStatusLabel('failed')).toBe('Error');
    });
});
