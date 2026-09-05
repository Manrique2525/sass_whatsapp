<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('F34-U3-QUEUE-01: failed job summary renders grouped rows without payload', function (): void {
    Schema::create('failed_jobs', function ($table): void {
        $table->uuid('uuid')->primary();
        $table->string('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'knowledge',
        'payload' => json_encode(['secret' => 'must-not-be-rendered'], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: controlled disposable failure',
        'failed_at' => now(),
    ]);

    $this->artisan('queue:failed-summary')
        ->expectsOutputToContain('knowledge')
        ->expectsOutputToContain('Total failed_jobs=1')
        ->doesntExpectOutputToContain('must-not-be-rendered')
        ->assertExitCode(0);
});
