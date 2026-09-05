<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('F34-U3-QUEUE-01: failed job summary renders grouped rows without payload', function (): void {
    Schema::dropIfExists('failed_jobs');
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
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\ProcessKnowledgeDocument',
            'command' => ['secret' => 'must-not-be-rendered'],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: controlled disposable failure',
        'failed_at' => now(),
    ]);

    $this->artisan('queue:failed-summary')
        ->expectsOutputToContain('knowledge')
        ->expectsOutputToContain('Total failed_jobs=1')
        ->doesntExpectOutputToContain('must-not-be-rendered')
        ->assertExitCode(0);
});

it('F34-U5-QUEUE-02: JSON summary includes job class but no payload data', function (): void {
    Schema::dropIfExists('failed_jobs');
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
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendWhatsAppMessage',
            'phone' => '+15550000000',
            'message' => 'private message body',
        ], JSON_THROW_ON_ERROR),
        'exception' => 'controlled disposable failure',
        'failed_at' => now(),
    ]);

    $this->artisan('queue:failed-summary', ['--json' => true])
        ->expectsOutputToContain('SendWhatsAppMessage')
        ->doesntExpectOutputToContain('+15550000000')
        ->doesntExpectOutputToContain('private message body')
        ->assertExitCode(0);
});
