<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Contacts\Services\TagService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `tag`: asigna etiquetas al contacto de la conversación.
 *
 * Delega TODA la mutación de tags a TagService (FASE 20 U1).
 * No accede directamente a Tag::query() ni a $contact->tags().
 */
final class TagNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly TagService $tagService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Tag;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $names = array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            is_array($config['tags'] ?? null) ? $config['tags'] : [],
        ))));

        if ($names === []) {
            return NodeExecutionResult::continue();
        }

        /** @var Contact $contact */
        $contact = $context->contact;

        foreach ($names as $name) {
            $tag = $this->tagService->findOrCreateByName($context->tenant, $name);
            $this->tagService->assignToContact($contact, $tag);
        }

        $this->auditLogger->record(
            action: 'flow.tag_applied',
            data: ['tags' => $names, 'flow_execution_id' => $context->execution->id],
            subjectType: Contact::class,
            subjectId: $contact->id,
            tenantId: $context->tenant->id,
        );

        return NodeExecutionResult::continue();
    }
}
