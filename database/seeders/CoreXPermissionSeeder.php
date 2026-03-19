<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class CoreXPermissionSeeder extends Seeder
{
    /**
     * Thin wrapper — delegates to the corex:sync-permissions command.
     *
     * Permission definitions live in config/corex-permissions.php.
     * This seeder only exists for `php artisan migrate --seed` on fresh installs.
     *
     * For ongoing deploys, use:  php artisan corex:sync-permissions
     * For fresh installs, use:   php artisan corex:sync-permissions --seed-defaults
     */
    public function run(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
    }
}
