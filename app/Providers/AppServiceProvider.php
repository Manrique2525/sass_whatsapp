<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Billing\Guards\CapacityGuard;
use App\Application\Billing\Guards\UsageGuard;
use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Application\Faq\Services\FaqMatcherService;
use App\Application\Flows\Services\ConversationLockContext;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Application\Flows\Services\Executors\ButtonsNodeExecutor;
use App\Application\Flows\Services\Executors\ConditionNodeExecutor;
use App\Application\Flows\Services\Executors\DelayNodeExecutor;
use App\Application\Flows\Services\Executors\EndNodeExecutor;
use App\Application\Flows\Services\Executors\HumanNodeExecutor;
use App\Application\Flows\Services\Executors\MessageNodeExecutor;
use App\Application\Flows\Services\Executors\QuestionNodeExecutor;
use App\Application\Flows\Services\Executors\TagNodeExecutor;
use App\Application\Flows\Services\Executors\WebhookNodeExecutor;
use App\Application\Flows\Services\NodeExecutorRegistry;
use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Application\Notifications\Listeners\CreateNotificationFromInboxChange;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Contacts\Events\TagAssigned;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Events\InboxConversationChanged;
use App\Infrastructure\AI\OpenAIEmbeddingProvider;
use App\Infrastructure\AI\OpenAIProvider;
use App\Infrastructure\Billing\StripeProvider;
use App\Infrastructure\Observability\MetricsRecorder;
use App\Infrastructure\WhatsApp\MetaWhatsAppProvider;
use App\Listeners\DispatchTagTriggerJob;
use App\Policies\TenantPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConversationLockContext::class);

        $this->app->singleton(MetricsRecorder::class);

        $this->app->bind(WhatsAppProviderInterface::class, function (): MetaWhatsAppProvider {
            return new MetaWhatsAppProvider(
                graphUrl: (string) config('whatsapp.graph_url'),
                graphVersion: (string) config('whatsapp.graph_version'),
                appSecret: (string) config('whatsapp.app_secret'),
                verifyToken: (string) config('whatsapp.verify_token'),
                connectTimeout: (int) config('whatsapp.connect_timeout'),
                timeout: (int) config('whatsapp.timeout'),
                metrics: $this->app->make(MetricsRecorder::class),
            );
        });

        $this->app->bind(AIProviderInterface::class, function (): OpenAIProvider {
            return new OpenAIProvider(
                apiKey: (string) config('ai.providers.openai.api_key'),
                model: (string) config('ai.providers.openai.model'),
                baseUrl: (string) config('ai.providers.openai.base_url'),
                timeout: (int) config('ai.providers.openai.timeout'),
                maxRetries: (int) config('ai.providers.openai.max_retries'),
            );
        });

        $this->app->bind(KnowledgeSearchServiceInterface::class, KnowledgeSearchService::class);

        $this->app->bind(FaqMatcherServiceInterface::class, FaqMatcherService::class);

        $this->app->bind(UsageGuardInterface::class, UsageGuard::class);

        $this->app->bind(CapacityGuardInterface::class, CapacityGuard::class);

        $this->app->bind(BillingProviderInterface::class, function (): StripeProvider {
            $secret = (string) config('services.stripe.secret');

            return new StripeProvider(
                secretKey: $secret,
                webhookSecret: (string) config('services.stripe.webhook_secret'),
            );
        });

        $this->app->bind(EmbeddingProviderInterface::class, function (): OpenAIEmbeddingProvider {
            $config = config('ai.embedding.providers.openai');

            return new OpenAIEmbeddingProvider(
                apiKey: (string) ($config['api_key'] ?? ''),
                model: (string) ($config['model'] ?? 'text-embedding-3-small'),
                dimensions: (int) ($config['dimensions'] ?? 1536),
                maxBatchSize: (int) ($config['max_batch_size'] ?? 50),
                timeout: (int) ($config['timeout'] ?? 30),
                maxRetries: (int) ($config['max_retries'] ?? 2),
            );
        });

        $this->app->bind(NodeExecutorRegistry::class, function ($app): NodeExecutorRegistry {
            return new NodeExecutorRegistry([
                $app->make(MessageNodeExecutor::class),
                $app->make(ButtonsNodeExecutor::class),
                $app->make(QuestionNodeExecutor::class),
                $app->make(ConditionNodeExecutor::class),
                $app->make(DelayNodeExecutor::class),
                $app->make(TagNodeExecutor::class),
                $app->make(WebhookNodeExecutor::class),
                $app->make(AiNodeExecutor::class),
                $app->make(HumanNodeExecutor::class),
                $app->make(EndNodeExecutor::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);

        Event::listen(TagAssigned::class, DispatchTagTriggerJob::class);

        Event::listen(InboxConversationChanged::class, CreateNotificationFromInboxChange::class);

        Password::defaults(fn () => Password::min(8));

        RateLimiter::for('auth-login', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->input('email') ?: $request->ip());
        });

        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('auth-password', function (Request $request): Limit {
            return Limit::perMinute(3)->by($request->input('email') ?: $request->ip());
        });

        RateLimiter::for('flow-webhook', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('webhook.whatsapp', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('invitation', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
