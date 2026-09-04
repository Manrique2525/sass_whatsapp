<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use InvalidArgumentException;

/**
 * Validates the minimum runtime contract before a production application boots.
 *
 * Provider credentials remain optional until the corresponding provider is
 * intentionally configured; core transport and security defaults do not.
 */
final class ProductionEnvironmentValidator
{
    public function validate(): void
    {
        $errors = [];

        if ((bool) config('app.debug')) {
            $errors[] = 'APP_DEBUG must be false';
        }

        $this->requireValue($errors, 'APP_KEY', config('app.key'));

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL must use HTTPS';
        }

        if (config('database.default') !== 'pgsql') {
            $errors[] = 'DB_CONNECTION must be pgsql';
        }

        $database = (array) config('database.connections.pgsql', []);
        foreach ([
            'host' => 'DB_HOST',
            'database' => 'DB_DATABASE',
            'username' => 'DB_USERNAME',
            'password' => 'DB_PASSWORD',
        ] as $key => $envKey) {
            $this->requireValue($errors, $envKey, $database[$key] ?? null);
        }

        $redis = (array) config('database.redis.default', []);
        $redisUrl = (string) ($redis['url'] ?? '');
        $redisPassword = (string) ($redis['password'] ?? '');
        $urlCredentials = $redisUrl !== '' ? parse_url($redisUrl) : false;
        $hasUrlCredentials = is_array($urlCredentials)
            && (($urlCredentials['user'] ?? '') !== '' || ($urlCredentials['pass'] ?? '') !== '');
        if ($redisPassword === '' && ! $hasUrlCredentials) {
            $errors[] = 'REDIS_PASSWORD or REDIS_URL with credentials is required';
        }

        if (config('cache.default') !== 'redis' || config('queue.default') !== 'redis') {
            $errors[] = 'CACHE_STORE and QUEUE_CONNECTION must use redis';
        }

        if (config('session.driver') !== 'redis') {
            $errors[] = 'SESSION_DRIVER must be redis';
        }

        foreach (['encrypt', 'secure', 'http_only'] as $key) {
            if (config("session.{$key}") !== true) {
                $errors[] = "SESSION_{$key} must be enabled";
            }
        }

        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $errors[] = 'SESSION_SAME_SITE must be lax or strict';
        }

        $trustedProxies = trim((string) config('trustedproxy.proxies', ''));
        if ($trustedProxies === '' || $trustedProxies === '*') {
            $errors[] = 'TRUSTED_PROXIES must list explicit proxy addresses';
        }

        $reverb = (array) config('reverb.apps.apps.0', []);
        $origins = (array) ($reverb['allowed_origins'] ?? []);
        if ($origins === [] || in_array('*', $origins, true)) {
            $errors[] = 'REVERB_ALLOWED_ORIGINS must not be wildcard';
        }

        $this->requireValue($errors, 'REVERB_HOST', $reverb['options']['host'] ?? null);

        foreach ([
            'key' => 'REVERB_APP_KEY',
            'secret' => 'REVERB_APP_SECRET',
            'app_id' => 'REVERB_APP_ID',
        ] as $key => $envKey) {
            $this->requireValue($errors, $envKey, $reverb[$key] ?? null);
        }

        if (config('reverb.apps.apps.0.options.scheme') !== 'https') {
            $errors[] = 'REVERB_SCHEME must be https';
        }

        $filesystem = (string) config('filesystems.default');
        if ($filesystem !== 's3') {
            $errors[] = 'FILESYSTEM_DISK must be s3';
        }

        $s3 = (array) config('filesystems.disks.s3', []);
        foreach ([
            'key' => 'AWS_ACCESS_KEY_ID',
            'secret' => 'AWS_SECRET_ACCESS_KEY',
            'region' => 'AWS_DEFAULT_REGION',
            'bucket' => 'AWS_BUCKET',
        ] as $key => $envKey) {
            $this->requireValue($errors, $envKey, $s3[$key] ?? null);
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $errors[] = 'MAIL_MAILER must be a delivery provider';
        }

        if ($mailer === 'smtp' && strtolower((string) config('mail.mailers.smtp.host')) === 'mailpit') {
            $errors[] = 'MAIL_HOST must not be Mailpit';
        }

        if ($mailer === 'smtp') {
            $this->requireValue($errors, 'MAIL_HOST', config('mail.mailers.smtp.host'));
        }

        $this->requireValue($errors, 'MAIL_FROM_ADDRESS', config('mail.from.address'));
        $this->requireValue($errors, 'MAIL_FROM_NAME', config('mail.from.name'));

        if ($errors !== []) {
            throw new InvalidArgumentException(
                'Production environment contract is invalid: '.implode('; ', $errors),
            );
        }
    }

    /** @param list<string> $errors */
    private function requireValue(array &$errors, string $key, mixed $value): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $errors[] = "{$key} is required";
        }
    }
}
