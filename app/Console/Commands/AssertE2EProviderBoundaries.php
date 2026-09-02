<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Infrastructure\Billing\E2EBillingProvider;
use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fakes\FakeAIProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeWhatsAppProvider;

/** Verifica que E2E no resuelve providers cloud ni conserva DSNs reales. */
final class AssertE2EProviderBoundaries extends Command
{
    protected $signature = 'e2e:assert-provider-boundaries';

    protected $description = 'Verifica providers fake, DSNs vacios y HTTP fail-closed en E2E';

    public function handle(): int
    {
        E2EEnvironmentGuard::assertSafe();

        $expected = [
            AIProviderInterface::class => FakeAIProvider::class,
            EmbeddingProviderInterface::class => FakeEmbeddingProvider::class,
            BillingProviderInterface::class => E2EBillingProvider::class,
            WhatsAppProviderInterface::class => FakeWhatsAppProvider::class,
        ];

        foreach ($expected as $contract => $implementation) {
            $resolved = $this->resolveProvider($contract);

            if (get_class($resolved) !== $implementation) {
                throw new RuntimeException(sprintf(
                    'E2E provider boundary: %s no esta enlazado a %s.',
                    $contract,
                    $implementation,
                ));
            }
        }

        foreach ([
            'sentry.dsn',
            'ai.providers.openai.api_key',
            'services.stripe.secret',
            'whatsapp.app_secret',
            'filesystems.disks.s3.key',
            'filesystems.disks.s3.secret',
        ] as $key) {
            if ((string) config($key, '') !== '') {
                throw new RuntimeException(sprintf('E2E provider boundary: %s debe estar vacio.', $key));
            }
        }

        if (! Http::preventingStrayRequests()) {
            throw new RuntimeException('E2E provider boundary: HTTP stray requests no estan bloqueadas.');
        }

        $this->info('E2E provider boundaries OK: Meta/OpenAI/Stripe fake, Sentry disabled, HTTP fail-closed.');

        return self::SUCCESS;
    }

    private function resolveProvider(string $contract): object
    {
        return app()->make($contract);
    }
}
