<?php

declare(strict_types=1);

use App\Infrastructure\Configuration\ProductionEnvironmentValidator;

function validProductionConfiguration(): void
{
    config([
        'app.debug' => false,
        'app.key' => 'base64:production-test-key',
        'app.url' => 'https://app.example.test',
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'host' => 'postgres.internal',
            'database' => 'whatsapp_saas',
            'username' => 'saas',
            'password' => 'configured',
        ],
        'database.redis.default' => [
            'url' => '',
            'password' => 'configured',
        ],
        'cache.default' => 'redis',
        'queue.default' => 'redis',
        'session.driver' => 'redis',
        'session.encrypt' => true,
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'trustedproxy.proxies' => '10.0.0.1,10.0.0.2',
        'reverb.apps.apps.0' => [
            'key' => 'reverb-key',
            'secret' => 'reverb-secret',
            'app_id' => 'reverb-id',
            'allowed_origins' => ['https://app.example.test'],
            'options' => ['host' => 'reverb.example.test', 'scheme' => 'https'],
        ],
        'filesystems.default' => 's3',
        'filesystems.disks.s3' => [
            'key' => 'storage-key',
            'secret' => 'storage-secret',
            'region' => 'us-east-1',
            'bucket' => 'whatsapp-saas',
        ],
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.from.address' => 'no-reply@example.test',
        'mail.from.name' => 'WhatsApp SaaS',
    ]);
}

test('production configuration passes with explicit secure values', function (): void {
    validProductionConfiguration();

    expect(fn () => (new ProductionEnvironmentValidator)->validate())
        ->not->toThrow(InvalidArgumentException::class);
});

test('production configuration rejects debug and wildcard proxy/origin defaults', function (): void {
    validProductionConfiguration();
    config([
        'app.debug' => true,
        'trustedproxy.proxies' => '*',
        'reverb.apps.apps.0.allowed_origins' => ['*'],
    ]);

    expect(fn () => (new ProductionEnvironmentValidator)->validate())
        ->toThrow(InvalidArgumentException::class);
});
