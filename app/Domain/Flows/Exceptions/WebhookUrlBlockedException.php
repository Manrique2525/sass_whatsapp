<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * El URL de un nodo `webhook` apunta a un host/IP bloqueado por la política
 * anti-SSRF (`WebhookUrlGuard`). Es un fallo de ejecución: el motor marca el
 * execution como `failed` y lo registra en `flow_execution_logs`; nunca se
 * resuelve la IP interna (seguridad FLOW-22).
 */
final class WebhookUrlBlockedException extends DomainException {}
