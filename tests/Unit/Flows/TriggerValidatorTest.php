<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Services\TriggerValidator;
use Illuminate\Support\Str;

/**
 * FASE 14 UNIDAD 1 — validación de config de triggers (backend autoritativo).
 * Unit test sin base de datos: el validador es dominio puro.
 */
function validate_trigger(FlowTriggerType $type, ?string $keyword = null, ?array $config = null, bool $clientProvided = false): array
{
    return app(TriggerValidator::class)->validate($type, $keyword, $config, $clientProvided);
}

test('U1-T01: un keyword válido pasa la validación sin config', function (): void {
    expect(validate_trigger(FlowTriggerType::Keyword, 'oferta'))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Keyword, '  venta  '))->toBe([]);
});

test('U1-T02: keyword inválido o con config es rechazado', function (): void {
    expect(validate_trigger(FlowTriggerType::Keyword, ''))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Keyword, '   '))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Keyword, str_repeat('a', 256)))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Keyword, 'oferta', ['extra' => true]))->not->toBe([]);
});

test('U1-T03: new_message y start no admiten config', function (): void {
    expect(validate_trigger(FlowTriggerType::NewMessage))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Start))->toBe([])
        ->and(validate_trigger(FlowTriggerType::NewMessage, null, ['extra' => true]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Start, null, ['extra' => true]))->not->toBe([]);
});

test('U1-T04: config de tag válida pasa (1 a 10 etiquetas únicas)', function (): void {
    expect(validate_trigger(FlowTriggerType::Tag, null, ['tags' => ['vip', 'nuevo']]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => ['vip']]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => array_map('strval', range(1, 10))]))->toBe([]);
});

test('U1-T05: config de tag inválida es rechazada', function (): void {
    expect(validate_trigger(FlowTriggerType::Tag))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, []))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => []]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => ['vip', 'vip']]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => ['']]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => [str_repeat('a', 101)]]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => range(1, 11)]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Tag, null, ['tags' => [42]]))->not->toBe([]);
});

test('U1-T06: config de schedule válida (cron determinista + UUID de conversación)', function (): void {
    $uuid = (string) Str::uuid();

    expect(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '0 9 * * 1-5', 'conversation_id' => $uuid]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '*/15 * * * *', 'conversation_id' => $uuid]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '5,10,15 8-18 * * 1,3,5', 'conversation_id' => $uuid]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '0 0 1 */2 7', 'conversation_id' => $uuid]))->toBe([]);
});

test('U1-T07: schedule inválido (cron mal formado o conversation_id ausente/no-UUID) es rechazado', function (): void {
    $uuid = (string) Str::uuid();

    expect(validate_trigger(FlowTriggerType::Schedule))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '60 * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* 24 * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* * 32 * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* * * 13 *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* * * * 8', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '* * * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => 'a-b * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '5-1 * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '*/0 * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '5,,10 * * * *', 'conversation_id' => $uuid]))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '0 9 * * 1-5']))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Schedule, null, ['cron' => '0 9 * * 1-5', 'conversation_id' => 'no-uuid']))->not->toBe([]);
});

test('U1-T08: cron determinista acepta solo sintaxis soportada y nunca evalúa código', function (): void {
    expect(TriggerValidator::isValidCron('* * * * *'))->toBeTrue()
        ->and(TriggerValidator::isValidCron('0 0 1 1 0'))->toBeTrue()
        ->and(TriggerValidator::isValidCron('*/10 8-18 * * 1,3,5'))->toBeTrue()
        ->and(TriggerValidator::isValidCron('5-10/2 * * * *'))->toBeTrue();

    expect(TriggerValidator::isValidCron('0 0 1 1 8'))->toBeFalse()
        ->and(TriggerValidator::isValidCron('@daily'))->toBeFalse()
        ->and(TriggerValidator::isValidCron('* * * *'))->toBeFalse()
        ->and(TriggerValidator::isValidCron('1; rm -rf /'))->toBeFalse()
        ->and(TriggerValidator::isValidCron('** * * * *'))->toBeFalse();
});

test('U1-T09: config de webhook del cliente válida (solo conversation_by, sin secretos)', function (): void {
    expect(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'conversation_id'], true))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'contact_id'], true))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone'], true))->toBe([]);
});

test('U1-T10: el cliente jamás envía token o token_hash en la config del webhook', function (): void {
    expect(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone', 'token_hash' => str_repeat('a', 64)], true))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone', 'token' => 'secreto'], true))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone', 'secret' => 'x'], true))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'celular'], true))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, [], true))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, null, true))->not->toBe([]);
});

test('U1-T11: config final de webhook exige token_hash sha256 y conversation_by válido', function (): void {
    $hash = hash('sha256', 'clave');

    expect(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone', 'token_hash' => $hash]))->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone']))->not->toBe([])
        ->and(validate_trigger(FlowTriggerType::Webhook, null, ['conversation_by' => 'phone', 'token_hash' => 'corto']))->not->toBe([]);
});

test('U1-T12: los límites de config se aplican a cualquier tipo', function (): void {
    $huge = ['tags' => [str_repeat('a', TriggerValidator::MAX_CONFIG_SIZE + 1)]];

    expect(validate_trigger(FlowTriggerType::Tag, null, $huge))->not->toBe([]);

    $longCron = str_repeat('0 ', 5).str_repeat('x', TriggerValidator::MAX_CRON_LENGTH + 1);

    expect(validate_trigger(FlowTriggerType::Schedule, null, [
        'cron' => $longCron,
        'conversation_id' => (string) Str::uuid(),
    ]))->not->toBe([]);
});

test('U1-T13: el token webhook es CSPRNG y su hash nunca revierte al valor original', function (): void {
    $token = TriggerValidator::generateWebhookToken();
    $hash = TriggerValidator::hashWebhookToken($token);

    expect(strlen($token))->toBe(64)
        ->and(preg_match('/^[a-f0-9]{64}$/', $token))->toBe(1)
        ->and(strlen($hash))->toBe(64)
        ->and(preg_match('/^[a-f0-9]{64}$/', $hash))->toBe(1)
        ->and($hash)->not->toBe($token);
});
