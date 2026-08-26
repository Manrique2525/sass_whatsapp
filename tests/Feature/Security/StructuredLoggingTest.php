<?php

declare(strict_types=1);

use App\Infrastructure\Logging\SafeLogContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

uses()->group('logging');

beforeEach(function (): void {
    $this->tempFile = tempnam(sys_get_temp_dir(), 'log_test_');
});

afterEach(function (): void {
    if (file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

function createJsonLogger(string $path): Logger
{
    $handler = new StreamHandler($path, Logger::DEBUG);
    $handler->setFormatter(new JsonFormatter);

    $logger = new Logger('test');
    $logger->pushHandler($handler);

    return $logger;
}

test('F28-U1-LOG-01: log line is valid JSON', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('test.event', ['key' => 'value']);

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $lastLine = end($lines);

    expect(json_decode($lastLine))->toBeInstanceOf(stdClass::class);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});

test('F28-U1-LOG-02: timestamp field present', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('test.event');

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);

    expect($decoded)->toHaveKey('datetime');
    expect($decoded['datetime'])->toBeString();
});

test('F28-U1-LOG-03: level field present', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('test.event');

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);

    expect($decoded)->toHaveKey('level');
    expect($decoded['level'])->toBe(300); // Monolog WARNING level (300 in Monolog 3.x)
});

test('F28-U1-LOG-04: message event key present', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('whatsapp.meta_api_error', ['status' => 400]);

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);

    expect($decoded)->toHaveKey('message');
    expect($decoded['message'])->toBe('whatsapp.meta_api_error');
});

test('F28-U1-LOG-05: channel and extra fields present', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('test.event', ['extra_field' => 'test_value']);

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);

    expect($decoded)->toHaveKey('channel');
    expect($decoded)->toHaveKey('extra');
});

test('F28-U1-LOG-06: context fields preserved', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('ai.openai_api_error', [
        'status' => 429,
        'provider_code' => 'rate_limit',
    ]);

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);

    expect($decoded['context']['status'])->toBe(429);
    expect($decoded['context']['provider_code'])->toBe('rate_limit');
});

test('F28-U1-LOG-07: multiple log lines are separate JSON objects', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->info('first.event');
    $logger->warning('second.event');
    $logger->error('third.event');

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));

    expect($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect(json_decode($line))->toBeInstanceOf(stdClass::class);
    }
});

test('F28-U1-LOG-08: newline-safe JSON (no raw newlines in message)', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning("test.event\nINJECTED_LINE", ['key' => "value\ninjected"]);

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));

    // Each line should be valid JSON — injected newlines are escaped by JsonFormatter
    foreach ($lines as $line) {
        expect(json_decode($line))->toBeInstanceOf(stdClass::class);
    }
});

test('F28-U1-LOG-09: no PII fields injected automatically', function (): void {
    $logger = createJsonLogger($this->tempFile);
    $logger->warning('test.event');

    $lines = array_filter(explode("\n", file_get_contents($this->tempFile)));
    $decoded = json_decode(end($lines), true);
    $flat = json_encode($decoded);

    expect($flat)->not->toContain('email');
    expect($flat)->not->toContain('phone');
    expect($flat)->not->toContain('password');
    expect($flat)->not->toContain('api_key');
    expect($flat)->not->toContain('secret');
});

test('F28-U1-LOG-10: SafeLogContext sanitizes provider messages', function (): void {
    $raw = 'Invalid API key: sk-proj123456789012345678901234567890';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('sk-proj');
    expect($sanitized)->toContain('[REDACTED]');
});

test('F28-U1-LOG-11: SafeLogContext sanitizes phone numbers', function (): void {
    $raw = 'Error for phone +5215555123456: not found';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('+5215555123456');
    expect($sanitized)->toContain('[PHONE]');
});

test('F28-U1-LOG-12: SafeLogContext truncates long messages', function (): void {
    $raw = str_repeat('a', 500);
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect(strlen($sanitized))->toBeLessThanOrEqual(210); // 200 + '...'
});

test('F28-U1-LOG-13: SafeLogContext handles null/empty', function (): void {
    expect(SafeLogContext::sanitizeProviderMessage(null))->toBe('N/A');
    expect(SafeLogContext::sanitizeProviderMessage(''))->toBe('N/A');
});
