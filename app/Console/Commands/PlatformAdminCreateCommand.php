<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Platform\Services\ProvisionPlatformAdmin;
use App\Domain\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

final class PlatformAdminCreateCommand extends Command
{
    protected $signature = 'platform-admin:create';

    protected $description = 'Create or promote a Platform Super Admin';

    public function handle(ProvisionPlatformAdmin $provisioner): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires an interactive terminal.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->ask('Email')));

        $emailValidation = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
        if ($emailValidation->fails()) {
            foreach ($emailValidation->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->line('User exists: YES');
            $this->line('Verified: '.($existing->hasVerifiedEmail() ? 'YES' : 'NO'));
            $this->line('Already super_admin: '.($existing->isSuperAdmin() ? 'YES' : 'NO'));
            $this->line('Tenant memberships: '.$existing->tenantUsers()->count());

            if ($existing->isSuperAdmin()) {
                $this->info('Platform Super Admin already exists; no changes made.');

                return self::SUCCESS;
            }

            if (! $this->confirm('Promote this existing user to Platform Super Admin?', false)) {
                $this->warn('No changes made.');

                return self::SUCCESS;
            }

            if (! $this->confirmProductionProvisioning()) {
                return self::FAILURE;
            }

            try {
                $user = $provisioner->promote($existing);
            } catch (Throwable $exception) {
                $this->error('Platform admin promotion failed; no changes were committed.');
                report($exception);

                return self::FAILURE;
            }

            $this->sendVerificationIfNeeded($user);
            $this->displaySuccess($user, false);

            return self::SUCCESS;
        }

        $name = trim((string) $this->ask('Name'));
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Password confirmation');

        $validation = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ],
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->confirmProductionProvisioning()) {
            return self::FAILURE;
        }

        try {
            $user = $provisioner->create($name, $email, $password);
        } catch (Throwable $exception) {
            $this->error('Platform admin creation failed; no changes were committed.');
            report($exception);

            return self::FAILURE;
        }

        $this->sendVerificationIfNeeded($user);
        $this->displaySuccess($user, true);

        return self::SUCCESS;
    }

    private function confirmProductionProvisioning(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $this->warn('PRODUCTION PLATFORM ADMIN PROVISIONING');

        return $this->ask('Type CREATE PLATFORM ADMIN to continue') === 'CREATE PLATFORM ADMIN';
    }

    private function sendVerificationIfNeeded(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        try {
            $user->sendEmailVerificationNotification();
            $this->info('Email verification notification sent.');
        } catch (Throwable $exception) {
            $this->warn('Account created, but the email verification notification could not be sent.');
            report($exception);
        }
    }

    private function displaySuccess(User $user, bool $created): void
    {
        $this->info($created ? 'Platform Admin created.' : 'Platform Admin promoted.');
        $this->line('Name: '.$user->name);
        $this->line('Email: '.$user->email);
        $this->line('Email verification required: '.($user->hasVerifiedEmail() ? 'NO' : 'YES'));
        $this->line('MFA enrollment required: YES');

        $appUrl = (string) config('app.url');
        if (filter_var($appUrl, FILTER_VALIDATE_URL) !== false) {
            $this->line('Login URL: '.rtrim($appUrl, '/').'/login');
        }
    }
}
