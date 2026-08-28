<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use Illuminate\Support\ServiceProvider;
use Tests\Fakes\FakeAIProvider;
use Tests\Fakes\FakeCapacityGuard;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeFaqMatcherService;
use Tests\Fakes\FakeKnowledgeSearchService;
use Tests\Fakes\FakeUsageGuard;
use Tests\Fakes\FakeWhatsAppProvider;

/**
 * ServiceProvider del ENTORNO E2E (Playwright, FASE 30 / ADR-110).
 *
 * Solo se registra cuando `APP_ENV === 'e2e'`. Re-enlaza los contratos de
 * proveedores externos (IA, embeddings, billing, knowledge, faq y el proveedor
 * de WhatsApp) a los fakes deterministas de `tests/Fakes` para que las pruebas
 * NUNCA hagan llamadas reales (Meta/OpenAI/Stripe/S3). No afecta a
 * local/production/testing.
 *
 * Se registra DESPUÉS de AppServiceProvider para sobreescribir sus bindings.
 *
 * WhatsApp: en U2 se re-enlaza a `FakeWhatsAppProvider` (fail-closed: nunca
 * alcanza la Graph API de Meta). Fase más tardía podrá reintroducir pruebas del
 * proveedor Meta real en un entorno acotado con credenciales de staging.
 *
 * Billing (Stripe) se deja con su implementación real pero latente (config
 * vacía en el entorno E2E): las pruebas de esta fase no la invocan.
 */
final class E2EOnlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (app()->environment() !== 'e2e') {
            return;
        }

        $this->app->singleton(AIProviderInterface::class, FakeAIProvider::class);
        $this->app->singleton(EmbeddingProviderInterface::class, FakeEmbeddingProvider::class);
        $this->app->singleton(CapacityGuardInterface::class, FakeCapacityGuard::class);
        $this->app->singleton(UsageGuardInterface::class, FakeUsageGuard::class);
        $this->app->singleton(FaqMatcherServiceInterface::class, FakeFaqMatcherService::class);
        $this->app->singleton(KnowledgeSearchServiceInterface::class, FakeKnowledgeSearchService::class);
        $this->app->singleton(WhatsAppProviderInterface::class, FakeWhatsAppProvider::class);
    }
}
