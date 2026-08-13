<?php

declare(strict_types=1);

test('el endpoint /health responde 200 con status ok', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'components' => [
                'app' => 'ok',
                'database' => 'ok',
                'redis' => 'ok',
                'queue' => 'ok',
            ],
        ]);
});

test('el endpoint /health expone los componentes de infraestructura', function () {
    $this->get('/health')
        ->assertJsonStructure([
            'status',
            'components' => [
                'app',
                'database',
                'redis',
                'queue',
            ],
        ]);
});
