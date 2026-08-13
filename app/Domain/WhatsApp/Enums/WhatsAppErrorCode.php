<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Códigos de error del módulo WhatsApp (FASE 6).
 *
 * Son los códigos estables expuestos por la API (campo `code` de las respuestas
 * de error) y registrados en `webhook_events.error_code` / `message_send_attempts.error_code`.
 */
enum WhatsAppErrorCode: string
{
    case NotConnected = 'WHATSAPP_NOT_CONNECTED';
    case AuthFailed = 'WHATSAPP_AUTH_FAILED';
    case MessageFailed = 'WHATSAPP_MESSAGE_FAILED';
    case WebhookInvalid = 'WHATSAPP_WEBHOOK_INVALID';
    case SignatureInvalid = 'WHATSAPP_WEBHOOK_SIGNATURE_INVALID';
    case PhoneNotFound = 'WHATSAPP_PHONE_NOT_FOUND';
    case EventDuplicate = 'WHATSAPP_EVENT_DUPLICATE';
}
