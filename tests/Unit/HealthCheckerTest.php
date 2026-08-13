<?php

declare(strict_types=1);

use App\Infrastructure\Health\HealthChecker;

test('HealthChecker reporta todos los componentes como ok', function () {
    $checker = new HealthChecker;

    $statuses = $checker->checkAll();

    expect($statuses)
        ->toMatchArray([
            'app' => 'ok',
            'database' => 'ok',
            'redis' => 'ok',
            'queue' => 'ok',
        ])
        ->and($checker->allOk($statuses))->toBeTrue();
});

test('HealthChecker detecta un componente fallido', function () {
    $checker = new HealthChecker;

    expect($checker->allOk(['app' => 'ok', 'database' => 'ok', 'redis' => 'ok', 'queue' => 'fail']))
        ->toBeFalse();
});
