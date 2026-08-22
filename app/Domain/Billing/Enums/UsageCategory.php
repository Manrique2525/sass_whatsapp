<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Usage categories for quota tracking (FASE 23 U1, ADR-009, ADR-088).
 *
 * Closed enum — matches ADR-009 contract: messages, AI calls, contacts,
 * flows, users, KB documents.
 *
 * Values align with docs/database.md `usage_records.feature` definition
 * (extended with categories documented in security.md chokepoints).
 */
enum UsageCategory: string
{
    case Messages = 'messages';
    case AiTokens = 'ai_tokens';
    case Contacts = 'contacts';
    case FlowExecutions = 'flow_executions';
    case Users = 'users';
    case KnowledgeDocuments = 'knowledge_documents';

    public function label(): string
    {
        return match ($this) {
            self::Messages => 'Messages',
            self::AiTokens => 'AI Tokens',
            self::Contacts => 'Contacts',
            self::FlowExecutions => 'Flow Executions',
            self::Users => 'Users',
            self::KnowledgeDocuments => 'Knowledge Documents',
        };
    }
}
