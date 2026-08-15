<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Flows\Services\TriggerMatcher;

/**
 * FASE 14 UNIDAD 1 — TriggerMatcher: precedencia, prioridad y no-matching de
 * los triggers de FASE 14 (tag/schedule/webhook) contra mensajes entrantes.
 */
function matcher_trigger(FlowTriggerType $type, array $attributes = []): Trigger
{
    return new Trigger(array_merge([
        'type' => $type,
        'keyword' => null,
        'config' => null,
        'priority' => 0,
        'active' => true,
    ], $attributes));
}

function match_triggers(array $triggers, string $body = 'hola', bool $isFirst = true): ?Trigger
{
    return (new TriggerMatcher)->match($triggers, $body, $isFirst);
}

test('U1-M01: keyword dispara solo en el primer mensaje y es case-insensitive', function (): void {
    $trigger = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'OFERTA']);

    expect(match_triggers([$trigger], 'Quiero ofertas'))->toBe($trigger)
        ->and(match_triggers([$trigger], 'Quiero ofertas', false))->toBeNull()
        ->and(match_triggers([$trigger], 'sin la palabra'))->toBeNull();
});

test('U1-M02: new_message dispara con cualquier mensaje y start solo en el primero', function (): void {
    $newMessage = matcher_trigger(FlowTriggerType::NewMessage);
    $start = matcher_trigger(FlowTriggerType::Start);

    expect(match_triggers([$newMessage], 'cualquier cosa', false))->toBe($newMessage)
        ->and(match_triggers([$start], 'primero', true))->toBe($start)
        ->and(match_triggers([$start], 'segundo', false))->toBeNull();
});

test('U1-M03: precedencia keyword > new_message > start', function (): void {
    $keyword = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'oferta']);
    $newMessage = matcher_trigger(FlowTriggerType::NewMessage);
    $start = matcher_trigger(FlowTriggerType::Start);

    expect(match_triggers([$start, $newMessage, $keyword], 'ofertas'))->toBe($keyword)
        ->and(match_triggers([$start, $newMessage], 'hola'))->toBe($newMessage)
        ->and(match_triggers([$start, $newMessage], 'hola', false))->toBe($newMessage);
});

test('U1-M04: dentro del mismo tipo gana la mayor priority', function (): void {
    $low = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'a', 'priority' => 1]);
    $high = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'a', 'priority' => 10]);

    expect(match_triggers([$low, $high], 'a'))->toBe($high);
});

test('U1-M05: los triggers inactivos jamás se evalúan', function (): void {
    $active = matcher_trigger(FlowTriggerType::NewMessage);
    $inactive = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'x', 'active' => false]);

    expect(match_triggers([$inactive], 'x'))->toBeNull()
        ->and(match_triggers([$inactive, $active], 'x'))->toBe($active);
});

test('U1-M06: tag, schedule y webhook jamás matchean un mensaje entrante', function (): void {
    $tag = matcher_trigger(FlowTriggerType::Tag, ['config' => ['tags' => ['vip']]]);
    $schedule = matcher_trigger(FlowTriggerType::Schedule, ['config' => ['cron' => '0 9 * * *']]);
    $webhook = matcher_trigger(FlowTriggerType::Webhook, ['config' => ['conversation_by' => 'phone']]);

    expect(match_triggers([$tag], 'hola'))->toBeNull()
        ->and(match_triggers([$schedule], 'hola'))->toBeNull()
        ->and(match_triggers([$webhook], 'hola'))->toBeNull();

    $newMessage = matcher_trigger(FlowTriggerType::NewMessage);

    expect(match_triggers([$webhook, $tag, $schedule, $newMessage], 'hola'))->toBe($newMessage);
});

test('U1-M07: el orden de tipo preserva específicos antes que genéricos de mensaje', function (): void {
    $keyword = matcher_trigger(FlowTriggerType::Keyword, ['keyword' => 'oferta']);
    $tag = matcher_trigger(FlowTriggerType::Tag, ['config' => ['tags' => ['vip']]]);
    $schedule = matcher_trigger(FlowTriggerType::Schedule, ['config' => ['cron' => '0 9 * * *']]);
    $webhook = matcher_trigger(FlowTriggerType::Webhook, ['config' => ['conversation_by' => 'phone']]);
    $newMessage = matcher_trigger(FlowTriggerType::NewMessage);
    $start = matcher_trigger(FlowTriggerType::Start);

    // Tag/schedule/webhook quedan antes en el orden pero jamás matchean: el
    // keyword sigue siendo elegido y los genéricos de mensaje conservan su
    // precedencia relativa.
    expect(match_triggers([$webhook, $tag, $schedule, $newMessage, $start, $keyword], 'ofertas'))->toBe($keyword)
        ->and(match_triggers([$webhook, $tag, $schedule, $start, $newMessage], 'hola'))->toBe($newMessage)
        ->and(match_triggers([$webhook, $tag, $schedule, $start, $newMessage], 'hola', false))->toBe($newMessage);
});
