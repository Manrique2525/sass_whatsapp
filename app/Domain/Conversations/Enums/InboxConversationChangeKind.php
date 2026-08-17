<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Enums;

/**
 * Tipos de cambio que alteran la lista del Inbox (canal privado tenant-wide).
 *
 * Cada valor corresponde a una operación de negocio que afecta el listado de
 * conversaciones y justifica una notificación realtime a todos los miembros
 * del tenant con `conversations.view`.
 *
 * El conjunto es cerrado: el frontend ignora kinds desconocidos.
 */
enum InboxConversationChangeKind: string
{
    case HandoffRequested = 'handoff_requested';
    case Assigned = 'assigned';
    case Claimed = 'claimed';
    case Transferred = 'transferred';
    case BotResumed = 'bot_resumed';
    case ConversationUpdated = 'conversation_updated';
}
