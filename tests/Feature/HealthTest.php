<?php

declare(strict_types=1);

test('el endpoint /health responde 200 con status ok (liveness only)', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'app' => 'ok',
            ],
            'mode' => 'liveness',
        ]);
});

test('el endpoint /health solo verifica app (no database redis queue)', function () {
    $this->get('/health')
        ->assertJsonStructure([
            'status',
            'checks' => [
                'app',
            ],
            'mode',
        ])
        ->assertJsonMissing([
            'checks' => [
                'database' => 'ok',
                'redis' => 'ok',
                'queue' => 'ok',
            ],
        ]);
});
