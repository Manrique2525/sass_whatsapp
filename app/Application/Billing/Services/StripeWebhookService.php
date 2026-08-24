<?php

declare(strict_types=1);

namespace App\Application\Billing\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\BillingWebhookEvent;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook ingestion and subscription sync (FASE 24 U3, ADR-094).
 *
 * Responsibilities:
 * - Receive verified event DTO (signature already verified by controller/provider)
 * - Idempotency ledger (UNIQUE provider + provider_event_id)
 * - Tenant resolution from Stripe customer ID (NOT from metadata)
 * - Event routing to type-specific handlers
 * - Event ordering (provider_created_at comparison)
 * - Subscription sync (status, period, cancel_at, plan mapping)
 * - Audit logging
 *
 * Webhook flow:
 * 1. Controller verifies signature via BillingProviderInterface->constructWebhookEvent()
 * 2. Service receives ProviderWebhookEvent DTO
 * 3. Insert pending event in ledger (idempotent via UNIQUE constraint)
 * 4. Resolve tenant from billing customer
 * 5. Route to handler
 * 6. Mark processed/failed
 */
final class StripeWebhookService
{
    /**
     * Event types we handle.
     *
     * @var array<string, string>
     */
    private const HANDLED_EVENTS = [
        'checkout.session.completed' => 'handleCheckoutCompleted',
        'customer.subscription.created' => 'handleSubscriptionCreated',
        'customer.subscription.updated' => 'handleSubscriptionUpdated',
        'customer.subscription.deleted' => 'handleSubscriptionDeleted',
        'invoice.paid' => 'handleInvoicePaid',
        'invoice.payment_failed' => 'handlePaymentFailed',
    ];

    private const PROVIDER = 'stripe';

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Process a verified webhook event.
     *
     * Returns true if the event was processed or is a duplicate/no-op.
     * Throws only for transient failures that should trigger Stripe retry.
     *
     * @throws \Throwable transient failure — Stripe should retry
     */
    public function handle(ProviderWebhookEvent $event): bool
    {
        $webhookEvent = $this->recordEvent($event);

        if ($webhookEvent === null) {
            // Duplicate already processed
            return true;
        }

        $handler = self::HANDLED_EVENTS[$event->type] ?? null;

        if ($handler === null) {
            // Unknown event type — mark processed, no-op
            $this->markProcessed($webhookEvent);

            Log::info('stripe.webhook.event_ignored', [
                'provider_event_id' => $event->eventId,
                'type' => $event->type,
            ]);

            return true;
        }

        $tenant = $this->resolveTenant($event);

        if ($tenant === null) {
            $this->markFailed($webhookEvent, 'TENANT_NOT_FOUND');

            Log::warning('stripe.webhook.tenant_not_found', [
                'provider_event_id' => $event->eventId,
                'customer_id' => $event->customerId,
            ]);

            return true;
        }

        // Update webhook event with resolved tenant
        $webhookEvent->update(['tenant_id' => $tenant->id]);

        $previousTenantId = TenantContext::id();

        try {
            TenantContext::setId($tenant->id);
            $this->{$handler}($event, $tenant, $webhookEvent);
        } catch (\Throwable $e) {
            $this->markFailed($webhookEvent, 'PROCESSING_ERROR');

            Log::error('stripe.webhook.processing_error', [
                'provider_event_id' => $event->eventId,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return true;
        } finally {
            if ($previousTenantId !== null) {
                TenantContext::setId($previousTenantId);
            } else {
                TenantContext::clear();
            }
        }

        $this->markProcessed($webhookEvent);

        return true;
    }

    /**
     * Insert or retrieve existing webhook event (idempotency).
     */
    private function recordEvent(ProviderWebhookEvent $event): ?BillingWebhookEvent
    {
        try {
            return DB::transaction(function () use ($event): BillingWebhookEvent {
                return BillingWebhookEvent::create([
                    'provider' => self::PROVIDER,
                    'provider_event_id' => $event->eventId,
                    'type' => $event->type,
                    'status' => WebhookEventStatus::Pending,
                    'provider_created_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
                    'billing_customer_id' => null,
                ]);
            });
        } catch (QueryException) {
            // UNIQUE constraint violation — duplicate event
            $existing = BillingWebhookEvent::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_event_id', $event->eventId)
                ->first();

            if ($existing !== null && $existing->status === WebhookEventStatus::Processed) {
                return null; // Already processed
            }

            // If failed/pending, allow reprocessing — return the existing row
            return $existing;
        }
    }

    /**
     * Resolve tenant from Stripe customer ID via billing_customers mapping.
     * NOT from metadata (P0: metadata is untrusted hint).
     */
    private function resolveTenant(ProviderWebhookEvent $event): ?Tenant
    {
        if ($event->customerId === null || $event->customerId === '') {
            return null;
        }

        $billingCustomer = BillingCustomer::query()
            ->withoutTenantScope()
            ->where('provider', self::PROVIDER)
            ->where('provider_customer_id', $event->customerId)
            ->first();

        if ($billingCustomer === null) {
            return null;
        }

        $tenant = Tenant::query()->find($billingCustomer->tenant_id);

        if ($tenant === null) {
            return null;
        }

        // Update billing customer ID on webhook event
        BillingWebhookEvent::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_event_id', $event->eventId)
            ->update(['billing_customer_id' => $billingCustomer->id]);

        return $tenant;
    }

    /**
     * checkout.session.completed — confirm mapping, register IDs.
     * Do NOT activate subscription from this event alone; wait for invoice.paid.
     */
    private function handleCheckoutCompleted(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $session = $event->data;

        $stripeSubscriptionId = $session['subscription'] ?? null;
        $metadata = $session['metadata'] ?? [];

        $planId = $metadata['plan_id'] ?? null;

        if ($stripeSubscriptionId !== null && $planId !== null) {
            // Find or create a pending subscription for this checkout
            $existing = Subscription::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('stripe_subscription_id', $stripeSubscriptionId)
                ->first();

            if ($existing === null) {
                // Create pending subscription to be promoted by invoice.paid
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'status' => SubscriptionStatus::Pending,
                    'quantity' => 1,
                ]);
            }
        }

        $this->auditLogger->record(
            action: 'billing.checkout.completed',
            data: [
                'provider_event_id' => $event->eventId,
                'provider' => self::PROVIDER,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ],
            subjectType: Subscription::class,
            subjectId: $stripeSubscriptionId,
            tenantId: $tenant->id,
        );
    }

    /**
     * customer.subscription.created — bind provider subscription ID, sync status/period.
     */
    private function handleSubscriptionCreated(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $this->syncSubscription($event, $tenant);
    }

    /**
     * customer.subscription.updated — sync status, period, cancel_at, plan mapping.
     */
    private function handleSubscriptionUpdated(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $this->syncSubscription($event, $tenant);
    }

    /**
     * customer.subscription.deleted — set status = Cancelled.
     */
    private function handleSubscriptionDeleted(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $sub = $this->findSubscriptionByStripeId($event->objectId, $tenant);

        if ($sub === null) {
            Log::warning('stripe.webhook.subscription_not_found', [
                'provider_event_id' => $event->eventId,
                'stripe_subscription_id' => $event->objectId,
            ]);

            return;
        }

        if (! $this->isNewerEvent($sub, $event)) {
            return; // Stale event
        }

        DB::transaction(function () use ($sub, $tenant, $event): void {
            $sub->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancel_at_period_end' => false,
                'provider_updated_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
            ]);

            // Sync denormalized cache
            $tenant->update(['plan_id' => null]);

            $this->auditLogger->record(
                action: 'billing.subscription.cancelled',
                data: [
                    'provider_event_id' => $event->eventId,
                    'provider' => self::PROVIDER,
                    'plan_slug' => $sub->plan?->slug,
                ],
                subjectType: Subscription::class,
                subjectId: $sub->id,
                tenantId: $tenant->id,
            );
        });
    }

    /**
     * invoice.paid — confirm payment, activate subscription.
     *
     * This is the authoritative activation signal (P0).
     */
    private function handleInvoicePaid(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $invoice = $event->data;
        $stripeSubscriptionId = $invoice['subscription'] ?? null;

        if ($stripeSubscriptionId === null || $stripeSubscriptionId === '') {
            return; // No subscription on this invoice (e.g. one-time)
        }

        $sub = $this->findSubscriptionByStripeId($stripeSubscriptionId, $tenant);

        if ($sub === null) {
            // Try to find plan from price mapping and create subscription
            $planId = $this->resolvePlanFromInvoice($invoice);

            if ($planId === null) {
                Log::warning('stripe.webhook.invoice_no_plan', [
                    'provider_event_id' => $event->eventId,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

            $sub = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'status' => SubscriptionStatus::Active,
                'quantity' => 1,
            ]);

            $tenant->update(['plan_id' => $planId]);
        } else {
            if (! $this->isNewerEvent($sub, $event)) {
                return; // Stale event
            }

            $periodStart = $invoice['period_start'] ?? null;
            $periodEnd = $invoice['period_end'] ?? null;

            $sub->update([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $periodStart ? date('Y-m-d H:i:s', $periodStart) : null,
                'current_period_end' => $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null,
                'provider_updated_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
            ]);

            // Sync denormalized cache
            $tenant->update(['plan_id' => $sub->plan_id]);
        }

        $this->auditLogger->record(
            action: 'billing.subscription.activated',
            data: [
                'provider_event_id' => $event->eventId,
                'provider' => self::PROVIDER,
                'plan_slug' => $sub->plan?->slug,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ],
            subjectType: Subscription::class,
            subjectId: $sub->id,
            tenantId: $tenant->id,
        );
    }

    /**
     * invoice.payment_failed — sync PastDue status.
     * No access blocking, no UsageGuard, no account suspension.
     */
    private function handlePaymentFailed(ProviderWebhookEvent $event, Tenant $tenant, BillingWebhookEvent $webhookEvent): void
    {
        $invoice = $event->data;
        $stripeSubscriptionId = $invoice['subscription'] ?? null;

        if ($stripeSubscriptionId === null || $stripeSubscriptionId === '') {
            return;
        }

        $sub = $this->findSubscriptionByStripeId($stripeSubscriptionId, $tenant);

        if ($sub === null) {
            return;
        }

        if (! $this->isNewerEvent($sub, $event)) {
            return; // Stale event
        }

        DB::transaction(function () use ($sub, $event): void {
            $sub->update([
                'status' => SubscriptionStatus::PastDue,
                'provider_updated_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
            ]);
        });

        $this->auditLogger->record(
            action: 'billing.payment.failed',
            data: [
                'provider_event_id' => $event->eventId,
                'provider' => self::PROVIDER,
                'plan_slug' => $sub->plan?->slug,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ],
            subjectType: Subscription::class,
            subjectId: $sub->id,
            tenantId: $tenant->id,
        );
    }

    /**
     * Generic subscription sync from customer.subscription.created/updated.
     *
     * Maps Stripe status → local SubscriptionStatus.
     * Resolves plan from price ID via plans.stripe_price_id_*.
     * Applies event ordering via provider_updated_at.
     */
    private function syncSubscription(ProviderWebhookEvent $event, Tenant $tenant): void
    {
        $stripeSub = $event->data;
        $stripeSubscriptionId = $stripeSub['id'] ?? $event->objectId;

        $sub = $this->findSubscriptionByStripeId($stripeSubscriptionId, $tenant);

        if ($sub === null) {
            // New subscription — resolve plan from price
            $planId = $this->resolvePlanFromStripeSub($stripeSub);

            if ($planId === null) {
                Log::warning('stripe.webhook.subscription_no_plan', [
                    'provider_event_id' => $event->eventId,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);

                return;
            }

            $status = $this->mapStripeStatus($stripeSub['status'] ?? '');

            $periodStart = $stripeSub['current_period_start'] ?? null;
            $periodEnd = $stripeSub['current_period_end'] ?? null;

            DB::transaction(function () use ($tenant, $planId, $stripeSubscriptionId, $status, $periodStart, $periodEnd, $event, $stripeSub): void {
                $sub = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'status' => $status,
                    'cancel_at_period_end' => (bool) ($stripeSub['cancel_at_period_end'] ?? false),
                    'quantity' => (int) ($stripeSub['quantity'] ?? 1),
                    'current_period_start' => $periodStart ? date('Y-m-d H:i:s', $periodStart) : null,
                    'current_period_end' => $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null,
                    'provider_updated_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
                ]);

                if ($status === SubscriptionStatus::Active) {
                    $tenant->update(['plan_id' => $planId]);
                }
            });

            return;
        }

        // Existing subscription — ordering check
        if (! $this->isNewerEvent($sub, $event)) {
            return;
        }

        $planId = $this->resolvePlanFromStripeSub($stripeSub);
        $status = $this->mapStripeStatus($stripeSub['status'] ?? '');
        $periodStart = $stripeSub['current_period_start'] ?? null;
        $periodEnd = $stripeSub['current_period_end'] ?? null;

        DB::transaction(function () use ($sub, $tenant, $planId, $status, $periodStart, $periodEnd, $event, $stripeSub): void {
            $updateData = [
                'status' => $status,
                'cancel_at_period_end' => (bool) ($stripeSub['cancel_at_period_end'] ?? false),
                'quantity' => (int) ($stripeSub['quantity'] ?? 1),
                'current_period_start' => $periodStart ? date('Y-m-d H:i:s', $periodStart) : null,
                'current_period_end' => $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null,
                'provider_updated_at' => date('Y-m-d H:i:s', (int) $event->createdAt),
            ];

            if ($planId !== null) {
                $updateData['plan_id'] = $planId;
            }

            $sub->update($updateData);

            // Sync denormalized cache if subscription is active
            if ($status === SubscriptionStatus::Active) {
                $tenant->update(['plan_id' => $sub->fresh()->plan_id]);
            } elseif ($status === SubscriptionStatus::Cancelled) {
                $tenant->update(['plan_id' => null]);
            }
        });

        $this->auditLogger->record(
            action: 'billing.subscription.provider_synced',
            data: [
                'provider_event_id' => $event->eventId,
                'provider' => self::PROVIDER,
                'status' => $status->value,
                'plan_slug' => $sub->plan?->slug,
            ],
            subjectType: Subscription::class,
            subjectId: $sub->id,
            tenantId: $tenant->id,
        );
    }

    /**
     * Find subscription by Stripe subscription ID.
     */
    private function findSubscriptionByStripeId(string $stripeSubscriptionId, Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->with('plan')
            ->first();
    }

    /**
     * Event ordering: only apply if incoming event is newer than local record.
     */
    private function isNewerEvent(Subscription $sub, ProviderWebhookEvent $event): bool
    {
        if ($sub->provider_updated_at === null) {
            return true;
        }

        $incomingTs = (int) $event->createdAt;
        $localTs = $sub->provider_updated_at->timestamp;

        if ($incomingTs > $localTs) {
            return true;
        }

        if ($incomingTs === $localTs) {
            // Tie: allow (idempotency will handle same event)
            return true;
        }

        return false;
    }

    /**
     * Map Stripe subscription status to local SubscriptionStatus.
     */
    private function mapStripeStatus(string $stripeStatus): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'active', 'trialing' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'canceled', 'unpaid', 'incomplete_expired' => SubscriptionStatus::Cancelled,
            'incomplete' => SubscriptionStatus::Pending,
            default => SubscriptionStatus::Pending,
        };
    }

    /**
     * Resolve local plan ID from Stripe subscription price ID.
     * Matches against plans.stripe_price_id_monthly or stripe_price_id_yearly.
     */
    private function resolvePlanFromStripeSub(array $stripeSub): ?string
    {
        $items = $stripeSub['items']['data'] ?? [];

        if ($items === []) {
            return null;
        }

        $priceId = $items[0]['price']['id'] ?? null;

        if ($priceId === null) {
            return null;
        }

        $plan = Plan::query()
            ->where('stripe_price_id_monthly', $priceId)
            ->orWhere('stripe_price_id_yearly', $priceId)
            ->first();

        return $plan?->id;
    }

    /**
     * Resolve plan ID from invoice line items.
     */
    private function resolvePlanFromInvoice(array $invoice): ?string
    {
        $lines = $invoice['lines']['data'] ?? [];

        if ($lines === []) {
            return null;
        }

        $priceId = $lines[0]['price']['id'] ?? null;

        if ($priceId === null) {
            return null;
        }

        $plan = Plan::query()
            ->where('stripe_price_id_monthly', $priceId)
            ->orWhere('stripe_price_id_yearly', $priceId)
            ->first();

        return $plan?->id;
    }

    private function markProcessed(BillingWebhookEvent $webhookEvent): void
    {
        $webhookEvent->update([
            'status' => WebhookEventStatus::Processed,
        ]);
    }

    private function markFailed(BillingWebhookEvent $webhookEvent, string $errorCode): void
    {
        $webhookEvent->update([
            'status' => WebhookEventStatus::Failed,
            'error_code' => $errorCode,
        ]);
    }
}
