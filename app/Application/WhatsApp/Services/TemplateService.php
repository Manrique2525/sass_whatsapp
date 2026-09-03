<?php

declare(strict_types=1);

namespace App\Application\WhatsApp\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Flows\Services\ConversationLockContext;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationReplyForbiddenException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\WhatsAppTemplateStatus;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateNotApprovedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateNotFoundException;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppTemplate;
use App\Domain\WhatsApp\Services\TemplateVariableValidator;
use App\Domain\WhatsApp\ValueObjects\TemplateInfo;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo y envío de templates de WhatsApp (FASE 31 U5, ADR-121).
 *
 * - `sync`: lee el catálogo de Meta y lo materializa en `whatsapp_templates`
 *   (upsert por identify natural account+name+language y provider id). NUNCA
 *   crea/propone los templates en Meta.
 * - `send`: valida que el template pertenezca al tenant y esté `approved`,
 *   valida las variables contra el schema de componentes ANTES de llamar al
 *   provider (0 llamadas a Meta si falla), y encola el envío por el pipeline
 *   de U4 (`SendWhatsAppMessage`) con su reserva de uso.
 *
 * Ventana de 24h: a diferencia del texto libre (que se rechaza fuera de la
 * ventana), un template `approved` SE PUEDE enviar fuera de la ventana (excepción
 * explícita por tipo de mensaje); la política de ventana NO se desactiva
 * globalmente ni se automatiza la selección de template.
 */
final class TemplateService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly FlowExecutionService $flowExecutions,
        private readonly ConversationLockContext $lockContext,
        private readonly TemplateVariableValidator $validator,
        private readonly UsageGuardInterface $usageGuard,
        private readonly AuditLogger $auditLogger,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Sincroniza el catálogo de templates de una cuenta con Meta.
     */
    public function syncForUser(User $user, Tenant $tenant, string $accountId): int
    {
        $this->authorization->authorize($user, TenantPermission::ManageWhatsApp, $tenant);

        $account = $this->findAccountForTenant($tenant, $accountId);

        if (! $account->isConnected()) {
            throw new WhatsAppNotConnectedException;
        }

        $accessToken = $account->access_token;
        $wabaId = $account->whatsapp_business_account_id;

        if ($accessToken === null || $accessToken === '' || $wabaId === null || $wabaId === '') {
            throw new WhatsAppNotConnectedException;
        }

        $catalog = app(WhatsAppProviderInterface::class)
            ->listTemplates($accessToken, $wabaId);

        $synced = TenantContext::withId($tenant->id, function () use ($account, $catalog): int {
            $count = 0;

            foreach ($catalog as $entry) {
                $count += $this->upsertTemplate($account, $entry) ? 1 : 0;
            }

            return $count;
        });

        $this->auditLogger->record(
            action: 'whatsapp.templates_synced',
            data: [
                'tenant_id' => $tenant->id,
                'whatsapp_account_id' => $account->id,
                'count' => $synced,
            ],
            subjectType: WhatsAppTemplate::class,
            subjectId: $account->id,
            tenantId: $tenant->id,
        );

        return $synced;
    }

    /**
     * @param  array{per_page?: int}  $filters
     * @return LengthAwarePaginator<int, WhatsAppTemplate>
     */
    public function indexForUser(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewWhatsApp, $tenant);

        return WhatsAppTemplate::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('updated_at')
            ->paginate($filters['per_page'] ?? 30);
    }

    /**
     * Envía un template aprobado a una conversación desde el inbox.
     *
     * @param  list<mixed>  $variables
     */
    public function send(
        User $user,
        Tenant $tenant,
        string $conversationId,
        string $templateId,
        array $variables,
    ): Message {
        $this->authorization->authorize($user, TenantPermission::SendMessages, $tenant);

        $lock = $this->flowExecutions->conversationLock($tenant, $conversationId);
        $acquired = false;

        try {
            $lock->block(seconds: 10);
            $acquired = true;
            $this->lockContext->enter($tenant->id, $conversationId, $lock);

            return DB::transaction(function () use ($user, $tenant, $conversationId, $templateId, $variables): Message {
                $conversation = $this->findConversationForTenantForUpdate($tenant, $conversationId);

                $this->authorization->authorize($user, TenantPermission::SendMessages, $tenant);

                if (! in_array($conversation->status, [ConversationStatus::Open, ConversationStatus::Pending], true)) {
                    throw new ConversationInvalidStateException(
                        'Solo se puede responder una conversación abierta o pendiente.',
                    );
                }

                if (! $this->authorization->can($user, TenantPermission::ManageConversations, $tenant)
                    && (int) $conversation->agent_id !== $user->id) {
                    throw ConversationReplyForbiddenException::notAssignedToActor();
                }

                $template = $this->findTemplateForTenant($tenant, $templateId);

                if ($template->status !== WhatsAppTemplateStatus::Approved) {
                    throw new WhatsAppTemplateNotApprovedException;
                }

                $parameters = $this->validator->validate($template->components, $variables);

                $message = $this->createTemplateOutbound($tenant, $conversation, $template, $parameters, $user);

                return $message;
            });
        } catch (LockTimeoutException) {
            throw ConversationReplyForbiddenException::busy();
        } finally {
            if ($acquired) {
                $this->lockContext->leave($tenant->id, $conversationId);
                $lock->release();
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $parameters
     */
    private function createTemplateOutbound(
        Tenant $tenant,
        Conversation $conversation,
        WhatsAppTemplate $template,
        array $parameters,
        User $actor,
    ): Message {
        $messageId = (string) Str::uuid();
        $idempotencyKey = "message:{$messageId}";

        TenantContext::withId($tenant->id, fn () => $this->usageGuard->reserve(
            tenant: $tenant,
            category: UsageCategory::Messages,
            quantity: 1,
            idempotencyKey: $idempotencyKey,
            ttlSeconds: 900,
        ));

        $message = TenantContext::withId($tenant->id, function () use (
            $tenant,
            $conversation,
            $template,
            $parameters,
            $actor,
            $messageId,
        ): Message {
            $message = new Message([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Template,
                'status' => MessageStatus::Pending,
                'metadata' => [
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                    'template_language' => $template->language,
                    'template_parameters' => $parameters,
                    'origin' => MessageOrigin::Human->value,
                    'attempt_tracking' => 'message_id_v1',
                ],
            ]);
            $message->forceFill([
                'id' => $messageId,
                'sent_by_user_id' => $actor->id,
                'tenant_id' => $tenant->id,
            ]);
            $message->save();

            return $message;
        });

        $this->bumpConversationTimestamps($tenant, $conversation->id);

        dispatch(
            (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))
                ->forTenant($tenant->id),
        );

        $this->events->dispatch(new MessageCreated($message));

        return $message;
    }

    private function upsertTemplate(WhatsAppAccount $account, TemplateInfo $entry): bool
    {
        $values = [
            'provider_template_id' => $entry->providerTemplateId,
            'name' => $entry->name,
            'language' => $entry->language,
            'category' => $entry->category,
            'status' => WhatsAppTemplateStatus::fromProvider($entry->status)->value,
            'components' => $entry->components,
            'last_synced_at' => now(),
        ];

        $template = WhatsAppTemplate::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('name', $entry->name)
            ->where('language', $entry->language)
            ->first();

        if ($template === null) {
            $template = new WhatsAppTemplate($values);
            $template->forceFill([
                'tenant_id' => $account->tenant_id,
                'whatsapp_account_id' => $account->id,
            ]);
            $template->save();

            return true;
        }

        $template->forceFill($values)->save();

        return true;
    }

    private function bumpConversationTimestamps(Tenant $tenant, string $conversationId): void
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            return;
        }

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_interaction_at' => now(),
        ])->save();

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));
    }

    private function findAccountForTenant(Tenant $tenant, string $accountId): WhatsAppAccount
    {
        $account = WhatsAppAccount::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw new WhatsAppNotConnectedException;
        }

        return $account;
    }

    private function findTemplateForTenant(Tenant $tenant, string $templateId): WhatsAppTemplate
    {
        $template = WhatsAppTemplate::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($templateId)
            ->first();

        if ($template === null) {
            throw new WhatsAppTemplateNotFoundException;
        }

        return $template;
    }

    private function findConversationForTenantForUpdate(Tenant $tenant, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->lockForUpdate()
            ->first();

        if ($conversation === null) {
            throw new ConversationNotFoundException;
        }

        return $conversation;
    }
}
