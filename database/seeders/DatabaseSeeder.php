<?php

namespace Database\Seeders;

use App\Models\DevSetting;
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

        // Re-enable demo mode after every reseed — the wipe empties dev_settings,
        // and DemoLoginController::isEnabled() defaults to false without this row.
        DevSetting::set('demo_mode_enabled', '1');

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
            // CAL-1 — global calendar event class settings (44 classes
            // including the 6 manual-creatable types the create-event
            // picker reads at agency_id IS NULL). Previously only invoked
            // by DemoDataSeeder, so staging deploys running `db:seed`
            // left the picker empty until someone ran the demo seed.
            // CalendarController::sharedViewData() L387 reads these
            // globals; without them the appointment-type dropdown renders
            // zero options. Seeder is idempotent (updateOrCreate keyed
            // by [agency_id=null, event_class]) — safe to re-run on
            // every deploy.
            //
            // Sibling reference seeders the demo invoked but production
            // db:seed never reached: BuyerMatchTiersSeeder (Core Matches
            // tier thresholds) + AgencyFeedbackOptionsSeeder (calendar
            // feedback dropdown options keyed by category=outcome|concern,
            // agency_id NULL ∪ agency_id=$id). Same fix-the-class pattern;
            // both are idempotent and wired here so every fresh deploy
            // bootstraps a working system.
            CalendarEventClassSeeder::class,
            BuyerMatchTiersSeeder::class,
            AgencyFeedbackOptionsSeeder::class,
        ]);
    }
}
