<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Infrastructure\WhatsApp\MetaWhatsAppProvider;
use App\Policies\TenantPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $this->app->bind(WhatsAppProviderInterface::class, function (): MetaWhatsAppProvider {
            return new MetaWhatsAppProvider(
                graphUrl: (string) config('whatsapp.graph_url'),
                graphVersion: (string) config('whatsapp.graph_version'),
                appSecret: (string) config('whatsapp.app_secret'),
                verifyToken: (string) config('whatsapp.verify_token'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);

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
    }
}
