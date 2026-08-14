<?php

declare(strict_types=1);

namespace App\Domain\Flows\ValueObjects;

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;

/**
 * Contexto inmutable que recibe cada ejecutor de nodo (FASE 11, ADR-036).
 *
 * Se construye en el motor y se reutiliza en cada paso del ciclo; los
 * ejecutores no deben mutarlo.
 */
final readonly class NodeExecutionContext
{
    /**
     * @param  array<string, mixed>  $custom  variables del namespace `custom.*`
     *                                        (viven en `execution.variables['custom']`)
     */
    public function __construct(
        public Tenant $tenant,
        public FlowNode $node,
        public FlowExecution $execution,
        public Conversation $conversation,
        public Contact $contact,
        public BusinessProfile $business,
        public array $custom = [],
        public ?Message $inboundMessage = null,
    ) {}
}
