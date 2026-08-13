<?php

declare(strict_types=1);

/**
 * Healthcheck ligero para Docker (sin arrancar todo el framework).
 *
 * Verificaciones reales:
 *  - database: conexión PDO pgsql + SELECT 1
 *  - redis:    conexión y PING
 *  - queue:    si QUEUE_CONNECTION=redis, depende de Redis (verificado arriba);
 *              si es sync/database, se considera ok
 *
 * Salida JSON a stdout. Exit code 0 = todo ok, 1 = degradado.
 * El healthcheck completo del framework (app, artisan health:check) sigue
 * disponible para humanos y balancers; este script evita el coste de boot
 * de Laravel en cada sondeo de Docker.
 */
$db = $redis = $queue = 'ok';

try {
    $pdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: 'postgres',
            getenv('DB_PORT') ?: '5432',
            getenv('DB_DATABASE') ?: 'whatsapp_saas',
        ),
        getenv('DB_USERNAME') ?: 'saas',
        getenv('DB_PASSWORD') ?: 'saas_secret',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
    );
    $pdo->query('SELECT 1');
} catch (Throwable) {
    $db = 'fail';
}

try {
    $client = new Redis;
    $client->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 3);
    if ($client->ping() !== true) {
        throw new RuntimeException('PING failed');
    }
} catch (Throwable) {
    $redis = 'fail';
}

$queueDriver = getenv('QUEUE_CONNECTION') ?: 'redis';
if (! in_array($queueDriver, ['sync', 'database'], true) && $redis === 'fail') {
    $queue = 'fail';
}

$statuses = ['app' => 'ok', 'database' => $db, 'redis' => $redis, 'queue' => $queue];
$allOk = ! in_array('fail', $statuses, true);

fwrite(STDOUT, json_encode(['status' => $allOk ? 'ok' : 'degraded', 'components' => $statuses], JSON_UNESCAPED_SLASHES).PHP_EOL);

exit($allOk ? 0 : 1);
