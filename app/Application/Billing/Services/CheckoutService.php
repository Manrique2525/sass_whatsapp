<?php

declare(strict_types=1);

namespace App\Application\Billing\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Enums\PlanInterval;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;

/**
 * Checkout and portal session management (FASE 24 U2, ADR-093).
 *
 * Handles:
 * - Paid plan checkout: validate plan → resolve price ID → ensure customer → create Checkout Session
 * - Customer Portal: resolve customer → create Portal Session
 * - Free plan bypass: no Stripe interaction
 *
 * Does NOT activate subscriptions — that's deferred to U3 webhooks.
 */
final class CheckoutService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BillingProviderInterface $provider,
        private readonly BillingCustomerService $billingCustomerService,
    ) {}

    /**
     * Create a Checkout Session for a paid plan.
     *
     * Returns CheckoutSessionData with URL for frontend redirect.
     * Free plans return null (caller should use SubscriptionService directly).
     *
     *
     * @throws PlanNotFoundException
     * @throws BillingProviderException
     */
    public function createCheckoutSession(
        User $user,
        Tenant $tenant,
        string $planId,
        string $interval,
    ): CheckoutSessionData {
        $this->authorization->authorize($user, TenantPermission::ManageBilling, $tenant);

        $plan = Plan::query()->whereKey($planId)->first();

        if ($plan === null) {
            throw new PlanNotFoundException;
        }

        if (! $plan->is_active) {
            throw new PlanNotFoundException('Plan inactivo.');
        }

        $parsedInterval = PlanInterval::tryFrom($interval);

        if ($parsedInterval === null) {
            throw new BillingProviderException("Intervalo inválido: {$interval}");
        }

        // Free plan bypass: price_monthly == 0 — no Stripe interaction
        if ($this->isFreePlan($plan)) {
            throw new BillingProviderException('Este plan es gratuito. Use la asignación directa.');
        }

        $priceId = match ($parsedInterval) {
            PlanInterval::Monthly => $plan->stripe_price_id_monthly,
            PlanInterval::Yearly => $plan->stripe_price_id_yearly,
        };

        if ($priceId === null || $priceId === '') {
            throw new BillingProviderException(
                "El plan [{$plan->slug}] no tiene precio configurado para el intervalo {$parsedInterval->value}.",
            );
        }

        $billingCustomer = $this->billingCustomerService->ensureCustomer($tenant);

        $appUrl = (string) config('app.url', 'http://localhost');

        $checkoutData = $this->provider->createCheckoutSession([
            'customer' => $billingCustomer->provider_customer_id,
            'price' => $priceId,
            'quantity' => 1,
            'success_url' => "{$appUrl}/settings/billing?checkout=success",
            'cancel_url' => "{$appUrl}/settings/billing?checkout=cancelled",
            'idempotency_key' => "checkout:{$tenant->id}:{$plan->id}:{$parsedInterval->value}",
            'metadata' => [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'interval' => $parsedInterval->value,
            ],
        ]);

        $this->auditLogger->record(
            action: 'billing.checkout.started',
            data: [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'interval' => $parsedInterval->value,
                'provider' => $this->provider->providerName(),
                'provider_customer_id' => $billingCustomer->provider_customer_id,
            ],
            subjectType: Plan::class,
            subjectId: $plan->id,
        );

        return $checkoutData;
    }

    /**
     * Create a Customer Portal session for the tenant.
     *
     * @throws BillingProviderException
     */
    public function createPortalSession(User $user, Tenant $tenant): PortalSessionData
    {
        $this->authorization->authorize($user, TenantPermission::ManageBilling, $tenant);

        $billingCustomer = $this->billingCustomerService->findByTenant($tenant);

        if ($billingCustomer === null) {
            throw new BillingProviderException('No hay cliente de facturación configurado para este tenant.');
        }

        $appUrl = (string) config('app.url', 'http://localhost');

        $portalData = $this->provider->createPortalSession([
            'customer' => $billingCustomer->provider_customer_id,
            'return_url' => "{$appUrl}/settings/billing",
            'idempotency_key' => "portal:{$tenant->id}:".time(),
        ]);

        $this->auditLogger->record(
            action: 'billing.portal.opened',
            data: [
                'tenant_id' => $tenant->id,
                'provider' => $this->provider->providerName(),
                'provider_customer_id' => $billingCustomer->provider_customer_id,
            ],
            subjectType: null,
            subjectId: null,
        );

        return $portalData;
    }

    private function isFreePlan(Plan $plan): bool
    {
        $monthlyPrice = (float) ($plan->price_monthly ?? 0);
        $yearlyPrice = (float) ($plan->price_yearly ?? 0);

        return $monthlyPrice === 0.0 && $yearlyPrice === 0.0;
    }
}
