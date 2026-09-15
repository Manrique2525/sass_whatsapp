<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Platform\Services\ProvisionPlatformAdmin;
use Illuminate\Console\Command;

final class LocalPlatformAdminCommand extends Command
{
    protected $signature = 'local:platform-admin';

    protected $description = 'LOCAL DEVELOPMENT ONLY: create or refresh the synthetic Platform Super Admin';

    public function handle(ProvisionPlatformAdmin $provisioner): int
    {
        if (! app()->environment('local')) {
            $this->error('This command may only run in the local environment.');

            return self::FAILURE;
        }

        $password = trim((string) config('platform.local_admin_password', ''));
        if ($password === '') {
            $password = 'local-platform-admin-password';
        }
        $result = $provisioner->provisionLocal(
            name: 'Local Platform Admin',
            email: 'platform-admin@local.test',
            password: $password,
        );

        $this->warn('LOCAL DEVELOPMENT CREDENTIALS ONLY - DO NOT USE IN PRODUCTION');
        $this->info($result['created'] ? 'CREATED' : 'UPDATED/ALREADY EXISTS');
        $this->line('URL: '.rtrim((string) config('app.url'), '/').'/login');
        $this->line('Email: platform-admin@local.test');
        $this->line('Password: '.$password);

        return self::SUCCESS;
    }
}
