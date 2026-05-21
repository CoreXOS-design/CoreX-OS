<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure test admin user exists
        User::updateOrCreate(
            ['email' => 'Test@hfcoastal.co.za'],
            [
                'name' => 'Test User',
                'password' => Hash::make('Test@1024'),
                'email_verified_at' => now(),
                'role' => 'admin',
                'is_admin' => true,
            ]
        );

        // Sync permissions from config/corex-permissions.php (with defaults for fresh install)
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);

        // Call all other seeders
        $this->call([
            MultiDemoSeeder::class,
            DemoSeeder::class,
            RichDemoSeeder::class,
            DepositTrustInterestSeeder::class,
            DealPipelineTemplateSeeder::class,
            AgencyDocumentTypeConfigSeeder::class,
            PayrollSeeder::class,
            PublicHolidaySeeder::class,
            LeaveTypeSeeder::class,
            ProspectingSetupSeeder::class,
            SellerOutreachTemplatesSeeder::class,
            SuggestedActionThresholdsSeeder::class,
            // MIC Phase A2 — supported report types for the upload UI.
            // Idempotent (updateOrInsert by key); 11 V1 types per spec §3.2.3.
            MarketReportTypesSeeder::class,
        ]);
    }
}
