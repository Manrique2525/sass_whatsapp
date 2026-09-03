<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    |
    | Solo valores GLOBALES de la app (compartidos por todos los tenants):
    | versión de Graph API, App Secret (firma de webhooks) y verify token del
    | webhook. El access token de cada WABA y el phone id viven en la base de
    | datos (cifrados), NO aquí. Ver docs/whatsapp.md.
    |
    | La versión de Graph API debe estar fijada (nunca "latest"); el provider
    | valida el formato y solo se actualiza manualmente tras revisar contratos.
    |
    */

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),

    'connect_timeout' => (int) env('WHATSAPP_CONNECT_TIMEOUT', 3),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),

    'webhook_max_payload_bytes' => (int) env('WHATSAPP_WEBHOOK_MAX_PAYLOAD_BYTES', 5242880),

    'webhook_retention_days' => (int) env('WHATSAPP_WEBHOOK_RETENTION_DAYS', 7),

    'webhook_failed_retention_days' => (int) env('WHATSAPP_WEBHOOK_FAILED_RETENTION_DAYS', 30),

    'webhook_prune_batch' => (int) env('WHATSAPP_WEBHOOK_PRUNE_BATCH', 100),

    'app_secret' => env('WHATSAPP_APP_SECRET', ''),

    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', ''),

    /*
    | Número máximo de intentos de envío por mensaje (registrados en
    | `message_send_attempts`). Los reintentos reales (cola con backoff) se
    | implementan en la fase de mensajería (FASE 9).
    */
    'max_attempts' => (int) env('WHATSAPP_MAX_ATTEMPTS', 3),

    'customer_care_window_hours' => (int) env('WHATSAPP_CUSTOMER_CARE_WINDOW_HOURS', 24),

    /*
    | Límites de media (FASE 31 U5, ADR-121). Valores GLOBALES de app.
    | `allowed_mime_types` es la lista blanca de tipos que el SaaS descarga y
    | almacena internamente; `max_bytes` es el tope transportable de cualquier
    | media (seguridad/SSRF y coste). El límite por tipo concreto lo aplica la
    | policy de descarga (Meta: imagen 5MB, audio 16MB, vídeo 16MB, doc 100MB).
    |
    */
    'media_endpoint_auth' => env('WHATSAPP_MEDIA_ENDPOINT_AUTH', true),

    'media_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),

    'media_max_bytes' => (int) env('WHATSAPP_MEDIA_MAX_BYTES', 10485760),

    'media_max_redirects' => (int) env('WHATSAPP_MEDIA_MAX_REDIRECTS', 3),

    'media_allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'audio/ogg',
        'audio/mpeg',
        'audio/amr',
        'audio/mp4',
        'video/mp4',
        'video/3gpp',
        'application/pdf',
    ],

    'media_document_max_bytes' => (int) env('WHATSAPP_MEDIA_DOCUMENT_MAX_BYTES', 104857600),
];
