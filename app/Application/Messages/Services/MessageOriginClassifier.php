<?php

declare(strict_types=1);

namespace App\Application\Messages\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Models\Message;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Models\TenantUser;

/**
 * Clasifica outbound persistido sin confiar solo en metadata potencialmente
 * legacy o corrupta.
 */
final class MessageOriginClassifier
{
    public function isAutomation(Message $message): bool
    {
        $origin = MessageOrigin::tryFrom((string) ($message->metadata['origin'] ?? ''));

        if ($origin === MessageOrigin::Human) {
            return $message->sent_by_user_id === null
                || ! TenantUser::query()
                    ->where('tenant_id', $message->tenant_id)
                    ->where('user_id', $message->sent_by_user_id)
                    ->where('status', TenantMembershipStatus::Active->value)
                    ->exists();
        }

        if ($origin !== MessageOrigin::Handoff || $message->sent_by_user_id !== null) {
            return true;
        }

        $executionId = $message->metadata['flow_execution_id'] ?? null;

        if (! is_string($executionId) || $executionId === '') {
            return true;
        }

        return ! AuditLog::query()
            ->where('tenant_id', $message->tenant_id)
            ->where('action', 'flow.handoff')
            ->where('subject_type', Conversation::class)
            ->where('subject_id', $message->conversation_id)
            ->where('data->flow_execution_id', $executionId)
            ->exists();
    }
}
