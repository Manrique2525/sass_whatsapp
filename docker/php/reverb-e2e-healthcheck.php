<?php

declare(strict_types=1);

$appId = getenv('REVERB_APP_ID');

if ($appId !== 'whatsapp-saas-e2e') {
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => '{}',
        'ignore_errors' => true,
        'timeout' => 2,
    ],
]);

@file_get_contents(
    'http://127.0.0.1:8080/apps/'.rawurlencode($appId).'/events',
    false,
    $context,
);

$statusLine = $http_response_header[0] ?? '';

// A registered app rejects this unsigned probe with 401; an unknown app returns 404.
exit(str_contains($statusLine, ' 401 ') ? 0 : 1);
