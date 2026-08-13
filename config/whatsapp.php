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
    | La versión de Graph API debe estar fijada (nunca "latest"); se actualiza
    | manualmente siguiendo el changelog de Meta.
    |
    */

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),

    'app_secret' => env('WHATSAPP_APP_SECRET', ''),

    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', ''),

    /*
    | Número máximo de intentos de envío por mensaje (registrados en
    | `message_send_attempts`). Los reintentos reales (cola con backoff) se
    | implementan en la fase de mensajería (FASE 9).
    */
    'max_attempts' => (int) env('WHATSAPP_MAX_ATTEMPTS', 3),
];
