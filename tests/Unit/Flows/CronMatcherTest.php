<?php

declare(strict_types=1);

use App\Domain\Flows\Services\TriggerValidator;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| FASE 14 — UNIDAD 2 — MATCHER CRON DETERMINISTA (ADR-048)
|--------------------------------------------------------------------------
|
| Valida matchesCron() de TriggerValidator: coincidencia de expresiones
| cron de 5 campos contra un instante Carbon dado. Sin eval/exec.
|
*/

function cronMatch(string $expr, string $datetime): bool
{
    return TriggerValidator::matchesCron($expr, Carbon::parse($datetime));
}

test('U2-CRON-01: */15 matchea en minutos 0, 15, 30, 45', function (): void {
    expect(cronMatch('*/15 * * * *', '2026-08-15 10:00:00'))->toBeTrue()
        ->and(cronMatch('*/15 * * * *', '2026-08-15 10:15:00'))->toBeTrue()
        ->and(cronMatch('*/15 * * * *', '2026-08-15 10:30:00'))->toBeTrue()
        ->and(cronMatch('*/15 * * * *', '2026-08-15 10:45:00'))->toBeTrue();
});

test('U2-CRON-02: */15 NO matchea en minutos 1, 14, 16', function (): void {
    expect(cronMatch('*/15 * * * *', '2026-08-15 10:01:00'))->toBeFalse()
        ->and(cronMatch('*/15 * * * *', '2026-08-15 10:14:00'))->toBeFalse()
        ->and(cronMatch('*/15 * * * *', '2026-08-15 10:16:00'))->toBeFalse();
});

test('U2-CRON-03: valor exacto de minuto matchea', function (): void {
    expect(cronMatch('30 * * * *', '2026-08-15 10:30:00'))->toBeTrue();
});

test('U2-CRON-04: valor exacto de minuto NO matchea', function (): void {
    expect(cronMatch('30 * * * *', '2026-08-15 10:15:00'))->toBeFalse();
});

test('U2-CRON-05: rango de minuto matchea dentro', function (): void {
    expect(cronMatch('0-15 * * * *', '2026-08-15 10:10:00'))->toBeTrue()
        ->and(cronMatch('0-15 * * * *', '2026-08-15 10:00:00'))->toBeTrue()
        ->and(cronMatch('0-15 * * * *', '2026-08-15 10:15:00'))->toBeTrue();
});

test('U2-CRON-06: rango de minuto NO matchea fuera', function (): void {
    expect(cronMatch('0-15 * * * *', '2026-08-15 10:16:00'))->toBeFalse()
        ->and(cronMatch('0-15 * * * *', '2026-08-15 10:59:00'))->toBeFalse();
});

test('U2-CRON-07: lista matchea uno de los valores', function (): void {
    expect(cronMatch('1,3,5 * * * *', '2026-08-15 10:01:00'))->toBeTrue()
        ->and(cronMatch('1,3,5 * * * *', '2026-08-15 10:03:00'))->toBeTrue()
        ->and(cronMatch('1,3,5 * * * *', '2026-08-15 10:05:00'))->toBeTrue();
});

test('U2-CRON-08: lista NO matchea valor ausente', function (): void {
    expect(cronMatch('1,3,5 * * * *', '2026-08-15 10:02:00'))->toBeFalse()
        ->and(cronMatch('1,3,5 * * * *', '2026-08-15 10:04:00'))->toBeFalse();
});

test('U2-CRON-09: DOM/DOW restringidos — matchea si día de mes coincide', function (): void {
    // 15 de agosto 2026 = viernes (DOW=5). Expresión: día 15 o DOW 3 (miércoles).
    expect(cronMatch('0 0 15 * 3', '2026-08-15 00:00:00'))->toBeTrue();
});

test('U2-CRON-10: DOM/DOW restringidos — matchea si DOW coincide', function (): void {
    // 12 de agosto 2026 = miércoles (DOW=3).
    expect(cronMatch('0 0 15 * 3', '2026-08-12 00:00:00'))->toBeTrue();
});

test('U2-CRON-11: DOM/DOW restringidos — NO matchea si ninguno coincide', function (): void {
    // 10 de agosto 2026 = lunes (DOW=1). Día 10 != 15, DOW 1 != 3.
    expect(cronMatch('0 0 15 * 3', '2026-08-10 00:00:00'))->toBeFalse();
});

test('U2-CRON-12: ? actúa como wildcard', function (): void {
    expect(cronMatch('? * * * *', '2026-08-15 10:30:00'))->toBeTrue()
        ->and(cronMatch('30 ? * * *', '2026-08-15 10:30:00'))->toBeTrue();
});

test('U2-CRON-13: 0 y 7 ambos significan domingo en DOW', function (): void {
    // 16 de agosto 2026 = domingo.
    expect(cronMatch('0 0 * * 0', '2026-08-16 00:00:00'))->toBeTrue()
        ->and(cronMatch('0 0 * * 7', '2026-08-16 00:00:00'))->toBeTrue();
});

test('U2-CRON-14: expresiones inválidas retornan false', function (): void {
    expect(TriggerValidator::matchesCron('', now()))->toBeFalse()
        ->and(TriggerValidator::matchesCron('invalid', now()))->toBeFalse()
        ->and(TriggerValidator::matchesCron('* * *', now()))->toBeFalse();
});

test('U2-CRON-15: paso con rango a-b/n', function (): void {
    expect(cronMatch('10-30/5 * * * *', '2026-08-15 10:10:00'))->toBeTrue()
        ->and(cronMatch('10-30/5 * * * *', '2026-08-15 10:15:00'))->toBeTrue()
        ->and(cronMatch('10-30/5 * * * *', '2026-08-15 10:20:00'))->toBeTrue()
        ->and(cronMatch('10-30/5 * * * *', '2026-08-15 10:25:00'))->toBeTrue()
        ->and(cronMatch('10-30/5 * * * *', '2026-08-15 10:30:00'))->toBeTrue()
        ->and(cronMatch('10-30/5 * * * *', '2026-08-15 10:11:00'))->toBeFalse();
});
