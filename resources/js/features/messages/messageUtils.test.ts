import { describe, expect, it } from 'vitest';
import type { Message } from './messageTypes';
import {
    applyMessageUpdate,
    buildMessageQuery,
    compareMessagesChronologically,
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

    it('desempata por id (UUIDv7) cuando created_at es idéntico', () => {
        const sameTs = '2026-08-14T12:00:00.000000Z';
        const olderId = makeMessage({ id: '018f6b20-0000-7000-8000-000000000001', created_at: sameTs });
        const newerId = makeMessage({ id: '0192b3c4-0000-7000-8000-000000000002', created_at: sameTs });

        const merged = mergeIncomingMessage([newerId], olderId);

        expect(merged.map((m) => m.id)).toEqual([
            '018f6b20-0000-7000-8000-000000000001',
            '0192b3c4-0000-7000-8000-000000000002',
        ]);
    });

    it('produce la misma secuencia final ante cualquier orden de llegada', () => {
        const sameTs = '2026-08-14T12:00:00.000000Z';
        const ids = [
            '018f6b20-0000-7000-8000-000000000001',
            '0192b3c4-0000-7000-8000-000000000002',
            '0193a5d6-0000-7000-8000-000000000003',
        ];
        const expected = [...ids].sort();

        const permutations: string[][] = [
            ids,
            [ids[1], ids[0], ids[2]],
            [ids[2], ids[0], ids[1]],
            [ids[1], ids[2], ids[0]],
            [ids[0], ids[2], ids[1]],
            [ids[2], ids[1], ids[0]],
        ];

        for (const order of permutations) {
            let current: Message[] = [];
            for (const id of order) {
                current = mergeIncomingMessage(current, makeMessage({ id, created_at: sameTs }));
            }
            expect(current.map((m) => m.id)).toEqual(expected);
        }
    });
});

describe('compareMessagesChronologically', () => {
    it('ordena por created_at antes que por id', () => {
        const earlier = makeMessage({ id: 'z', created_at: '2026-08-14T11:00:00.000000Z' });
        const later = makeMessage({ id: 'a', created_at: '2026-08-14T12:00:00.000000Z' });

        const sorted = [later, earlier].sort(compareMessagesChronologically);

        expect(sorted.map((m) => m.id)).toEqual(['z', 'a']);
    });

    it('usa id como tie-breaker determinista cuando created_at empata', () => {
        const sameTs = '2026-08-14T12:00:00.000000Z';
        const a = makeMessage({ id: '0192b3c4-0000-7000-8000-000000000002', created_at: sameTs });
        const b = makeMessage({ id: '018f6b20-0000-7000-8000-000000000001', created_at: sameTs });

        expect(compareMessagesChronologically(a, b)).toBeGreaterThan(0);
        expect(compareMessagesChronologically(b, a)).toBeLessThan(0);

        const sorted = [a, b].sort(compareMessagesChronologically);
        expect(sorted.map((m) => m.id)).toEqual([
            '018f6b20-0000-7000-8000-000000000001',
            '0192b3c4-0000-7000-8000-000000000002',
        ]);
    });

    it('es consistente con reload y realtime (mismo orden final)', () => {
        const sameTs = '2026-08-14T12:00:00.000000Z';
        const messages = [
            makeMessage({ id: '0193a5d6-0000-7000-8000-000000000003', created_at: sameTs }),
            makeMessage({ id: '018f6b20-0000-7000-8000-000000000001', created_at: sameTs }),
            makeMessage({ id: '0192b3c4-0000-7000-8000-000000000002', created_at: sameTs }),
        ];

        // Equivalente al resultado que devolvería el backend ORDER BY created_at, id
        // tras invertir la página DESC.
        const reloadLike = [...messages]
            .sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id.localeCompare(a.id))
            .reverse();

        // Realtime: merge incremental.
        let realtimeLike: Message[] = [];
        for (const message of messages) {
            realtimeLike = mergeIncomingMessage(realtimeLike, message);
        }

        expect(realtimeLike.map((m) => m.id)).toEqual(reloadLike.map((m) => m.id));
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
