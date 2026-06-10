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

        // Sync permissions from config/corex-permissions.php (with defaults for fresh install).
        // Permission scaffolding is pure reference data — safe everywhere.
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);

        // ── REFERENCE SEEDERS ───────────────────────────────────────────
        // Idempotent default-data seeders. Safe to run on every
        // environment — local, demo, staging, production. Each is keyed
        // by updateOrCreate / updateOrInsert on a stable natural key so
        // re-runs never duplicate rows. New reference seeders go HERE.
        // ────────────────────────────────────────────────────────────────
        $this->call([
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
            // M6.2-FIX — HFC activity-calendar mappings. Was a one-time
            // migration (2026_06_20_120000) until staging copied from live
            // showed the migration marked "ran" + the rows absent, so the
            // auto-points engine silently never fired. Same fix-the-class
            // pattern as the three above: reference data lives in
            // idempotent seeders, not one-time migrations.
            ActivityCalendarMappingSeeder::class,
            // SPINE-1 — system-default catalogue of instant-action slugs.
            // Same table as M6.2's calendar mappings, discriminated by
            // trigger_kind='instant'. Per-agency rows; agencies inherit
            // the catalogue + can override value_per_event / is_active.
            ActivityInstantActionsSeeder::class,
            // Presentations — Executive Summary AI variants (direct / warm /
            // confident). Without these seeded, the variant dropdown on the
            // presentation page is empty and Generate fails with
            // "Generation failed: unknown" because the controller's
            // findOrFail() blows up on an absent variant id. Idempotent via
            // updateOrCreate keyed on 'key'.
            PresentationAiVariantsSeeder::class,
        ]);

        // ════════════════════════════════════════════════════════════════
        // DEMO ONLY — creates/modifies sample tenant data and flips
        // demo_mode. MUST NOT run on staging/production. Gated by
        // environment. See incident 2026-06-02.
        //
        // INCIDENT: `php artisan db:seed` on staging ran the demo
        // seeders, which created demo agencies/branches/users/deals,
        // reassigned a real user's branch_id AND role to demo values,
        // and flipped the demo_mode_enabled DevSetting on — taking down
        // the staging login and hiding real data.
        //
        // Any future seeder that creates sample agencies/branches/users/
        // deals OR sets demo_mode_enabled MUST be added INSIDE this
        // gate, never to the reference list above. The environment
        // check is the only structural barrier between this codepath
        // and a production wipe.
        // ════════════════════════════════════════════════════════════════
        if (app()->environment('local', 'demo')) {
            // Re-enable demo mode after every reseed — the wipe empties
            // dev_settings, and DemoLoginController::isEnabled() defaults
            // to false without this row. NEVER flip on staging/production
            // — that would route real users through the demo login flow,
            // which is exactly what the 2026-06-02 incident did. This
            // DevSetting::set was the trigger; gating it here is the
            // root-cause fix.
            DevSetting::set('demo_mode_enabled', '1');

            $this->call([
                MultiDemoSeeder::class,
                DemoSeeder::class,
                RichDemoSeeder::class,
            ]);
        }
    }
}
