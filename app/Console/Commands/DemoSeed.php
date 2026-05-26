<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoSeed extends Command
{
    protected $signature = 'demo:seed';
    protected $description = 'Seed demo data (APP_ENV=local or demo only)';

    public function handle(): int
    {
        if (!in_array(app()->environment(), ['local', 'demo'], true)) {
            $this->error('Refusing to seed demo data — APP_ENV must be local or demo (current: ' . app()->environment() . ')');
            return self::FAILURE;
        }

        $this->info('Seeding demo data...');
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\DemoDataSeeder']);
        return self::SUCCESS;
    }
}
