<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Models\User;
use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * Emits the real signed email-verification URL for a user in the isolated E2E
 * environment. It is intentionally unavailable outside APP_ENV=e2e.
 */
final class GetE2EVerificationUrl extends Command
{
    protected $signature = 'e2e:verification-url {email}';

    protected $description = 'Obtiene el enlace firmado de verificación para E2E';

    public function handle(): int
    {
        E2EEnvironmentGuard::assertSafe();

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Usuario E2E no encontrado.');

            return self::FAILURE;
        }

        $this->line(URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        ));

        return self::SUCCESS;
    }
}
