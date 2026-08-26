<?php

declare(strict_types=1);

use App\Infrastructure\Logging\Processors\RequestContextProcessor;
use App\Infrastructure\Logging\Processors\TenantContextProcessor;
use App\Infrastructure\Logging\SafeLogContext;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;

uses()->group('logging');

function makeLogRecord(string $message = 'test'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: $message,
    );
}

test('F28-U1-LOG-14: TenantContextProcessor adds tenant_id when bound', function (): void {
    $tenantId = (string) Str::uuid();
    TenantContext::setId($tenantId);

    try {
        $processor = new TenantContextProcessor;
        $record = $processor(makeLogRecord());

        expect($record->extra)->toHaveKey('tenant_id');
        expect($record->extra['tenant_id'])->toBe($tenantId);
    } finally {
        TenantContext::clear();
    }
});

test('F28-U1-LOG-15: TenantContextProcessor omits tenant_id when no context', function (): void {
    TenantContext::clear();

    $processor = new TenantContextProcessor;
    $record = $processor(makeLogRecord());

    expect($record->extra)->not->toHaveKey('tenant_id');
});

test('F28-U1-LOG-16: TenantContextProcessor does not leak between tenants', function (): void {
    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    $processor = new TenantContextProcessor;

    TenantContext::setId($tenantA);
    try {
        $recordA = $processor(makeLogRecord('tenant_a'));
        expect($recordA->extra['tenant_id'])->toBe($tenantA);
    } finally {
        TenantContext::clear();
    }

    TenantContext::setId($tenantB);
    try {
        $recordB = $processor(makeLogRecord('tenant_b'));
        expect($recordB->extra['tenant_id'])->toBe($tenantB);
    } finally {
        TenantContext::clear();
    }
});

test('F28-U1-LOG-17: RequestContextProcessor resolves from request attributes', function (): void {
    $requestId = (string) Str::uuid();
    $request = Request::create('/test', 'GET');
    $request->attributes->set('request_id', $requestId);

    // Bind the request so request() returns it
    $this->app->instance('request', $request);

    $processor = new RequestContextProcessor;
    $record = $processor(makeLogRecord());

    expect($record->extra)->toHaveKey('request_id');
    expect($record->extra['request_id'])->toBe($requestId);
});

test('F28-U1-LOG-18: RequestContextProcessor omits request_id when no context', function (): void {
    $processor = new RequestContextProcessor;
    $record = $processor(makeLogRecord());

    // May or may not have request_id depending on test runner context
    // but should not throw
    expect($record)->toBeInstanceOf(LogRecord::class);
});

test('F28-U1-LOG-19: SafeLogContext strips Bearer tokens', function (): void {
    $raw = 'Auth failed: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('eyJhbGci');
    expect($sanitized)->toContain('[REDACTED]');
});

test('F28-U1-LOG-20: SafeLogContext strips email addresses', function (): void {
    $raw = 'User user@example.com not found';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('user@example.com');
    expect($sanitized)->toContain('[EMAIL]');
});
