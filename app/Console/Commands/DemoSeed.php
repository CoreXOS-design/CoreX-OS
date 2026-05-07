<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoSeed extends Command
{
    protected $signature = 'demo:seed';
    protected $description = 'Seed demo data for local testing (APP_ENV=local only)';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Refusing to seed demo data — APP_ENV is not local (current: ' . app()->environment() . ')');
            return self::FAILURE;
        }

        $this->info('Seeding demo data...');
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\DemoDataSeeder']);
        return self::SUCCESS;
    }
}
