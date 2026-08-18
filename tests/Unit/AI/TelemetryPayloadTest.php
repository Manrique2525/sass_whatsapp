<?php

declare(strict_types=1);

use App\Domain\AI\Enums\AIErrorCode;
use App\Domain\AI\ValueObjects\AIResponse;
use App\Domain\AI\ValueObjects\TelemetryPayload;

/*
|--------------------------------------------------------------------------
| FASE 16 U4 — TELEMETRY PAYLOAD (UNIT TESTS)
|--------------------------------------------------------------------------
|
| Tests AI-U01..U08: TelemetryPayload VO immutability, safe schema,
| PII exclusion, token validation.
|
*/

function safe_telemetry_keys(): array
{
    return [
        'operation', 'provider', 'model', 'input_tokens', 'output_tokens',
        'total_tokens', 'latency_ms', 'success', 'error_code', 'fallback_used',
    ];
}

// ---------------------------------------------------------------------------
// AI-U01: fromResponse creates correct payload
// ---------------------------------------------------------------------------
test('AI-U01: fromResponse creates payload with correct fields', function (): void {
    $response = new AIResponse(
        content: 'Hello',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: 50,
        outputTokens: 100,
        totalTokens: 150,
    );

    $telemetry = TelemetryPayload::fromResponse($response, latencyMs: 342);

    expect($telemetry->operation)->toBe('generate')
        ->and($telemetry->provider)->toBe('openai')
        ->and($telemetry->model)->toBe('gpt-4o-mini')
        ->and($telemetry->inputTokens)->toBe(50)
        ->and($telemetry->outputTokens)->toBe(100)
        ->and($telemetry->totalTokens)->toBe(150)
        ->and($telemetry->latencyMs)->toBe(342)
        ->and($telemetry->success)->toBeTrue()
        ->and($telemetry->errorCode)->toBeNull()
        ->and($telemetry->fallbackUsed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// AI-U02: fromResponse validates token counts >= 0
// ---------------------------------------------------------------------------
test('AI-U02: fromResponse clamps negative token counts to 0', function (): void {
    $response = new AIResponse(
        content: 'OK',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: -5,
        outputTokens: -10,
        totalTokens: -15,
    );

    $telemetry = TelemetryPayload::fromResponse($response, latencyMs: 100);

    expect($telemetry->inputTokens)->toBe(0)
        ->and($telemetry->outputTokens)->toBe(0)
        ->and($telemetry->totalTokens)->toBe(0);
});

// ---------------------------------------------------------------------------
// AI-U03: fromResponse handles zero token counts
// ---------------------------------------------------------------------------
test('AI-U03: fromResponse preserves zero token counts', function (): void {
    $response = new AIResponse(
        content: 'OK',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: 0,
        outputTokens: 0,
        totalTokens: 0,
    );

    $telemetry = TelemetryPayload::fromResponse($response, latencyMs: 50);

    expect($telemetry->inputTokens)->toBe(0)
        ->and($telemetry->outputTokens)->toBe(0)
        ->and($telemetry->totalTokens)->toBe(0);
});

// ---------------------------------------------------------------------------
// AI-U04: fromError with null errorCode
// ---------------------------------------------------------------------------
test('AI-U04: fromError with null errorCode creates payload with null error_code', function (): void {
    $telemetry = TelemetryPayload::fromError(
        errorCode: null,
        latencyMs: 200,
    );

    expect($telemetry->operation)->toBe('generate')
        ->and($telemetry->provider)->toBe('')
        ->and($telemetry->model)->toBe('')
        ->and($telemetry->inputTokens)->toBeNull()
        ->and($telemetry->outputTokens)->toBeNull()
        ->and($telemetry->totalTokens)->toBeNull()
        ->and($telemetry->latencyMs)->toBe(200)
        ->and($telemetry->success)->toBeFalse()
        ->and($telemetry->errorCode)->toBeNull()
        ->and($telemetry->fallbackUsed)->toBeTrue();
});

// ---------------------------------------------------------------------------
// AI-U05: fromError with specific errorCode
// ---------------------------------------------------------------------------
test('AI-U05: fromError with specific errorCode stores enum value', function (): void {
    $telemetry = TelemetryPayload::fromError(
        errorCode: AIErrorCode::RateLimit,
        latencyMs: 150,
        fallbackUsed: false,
    );

    expect($telemetry->errorCode)->toBe('AI_RATE_LIMIT')
        ->and($telemetry->fallbackUsed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// AI-U06: toArray returns safe schema
// ---------------------------------------------------------------------------
test('AI-U06: toArray returns array with exactly the safe schema keys', function (): void {
    $response = new AIResponse(
        content: 'Test',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: 10,
        outputTokens: 20,
        totalTokens: 30,
    );

    $telemetry = TelemetryPayload::fromResponse($response, latencyMs: 100);
    $array = $telemetry->toArray();

    expect(array_keys($array))->toBe(safe_telemetry_keys())
        ->and($array['operation'])->toBe('generate')
        ->and($array['provider'])->toBe('openai')
        ->and($array['model'])->toBe('gpt-4o-mini')
        ->and($array['input_tokens'])->toBe(10)
        ->and($array['output_tokens'])->toBe(20)
        ->and($array['total_tokens'])->toBe(30)
        ->and($array['latency_ms'])->toBe(100)
        ->and($array['success'])->toBeTrue()
        ->and($array['error_code'])->toBeNull()
        ->and($array['fallback_used'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// AI-U07: toArray output never contains PII
// ---------------------------------------------------------------------------
test('AI-U07: toArray output never contains PII fields', function (): void {
    $response = new AIResponse(
        content: 'Secret AI response content with PII',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: 10,
        outputTokens: 20,
        totalTokens: 30,
    );

    $telemetry = TelemetryPayload::fromResponse($response, latencyMs: 100);
    $array = $telemetry->toArray();
    $json = json_encode($array);

    expect($json)->not->toContain('Secret AI response')
        ->and($json)->not->toContain('prompt')
        ->and($json)->not->toContain('system_prompt')
        ->and($json)->not->toContain('content')
        ->and($json)->not->toContain('contact')
        ->and($json)->not->toContain('business')
        ->and($json)->not->toContain('email')
        ->and($json)->not->toContain('phone')
        ->and($json)->not->toContain('secret');

    // Verify safe keys are the ONLY keys
    foreach ($array as $key => $value) {
        expect($key)->toBeIn(safe_telemetry_keys());
    }
});

// ---------------------------------------------------------------------------
// AI-U08: fromError toArray never contains PII
// ---------------------------------------------------------------------------
test('AI-U08: fromError toArray never contains PII fields', function (): void {
    $telemetry = TelemetryPayload::fromError(
        errorCode: AIErrorCode::ProviderError,
        latencyMs: 500,
        fallbackUsed: true,
    );

    $array = $telemetry->toArray();
    $json = json_encode($array);

    expect($json)->not->toContain('prompt')
        ->and($json)->not->toContain('content')
        ->and($json)->not->toContain('contact')
        ->and($json)->not->toContain('business');

    foreach ($array as $key => $value) {
        expect($key)->toBeIn(safe_telemetry_keys());
    }
});
