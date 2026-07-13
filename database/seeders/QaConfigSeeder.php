<?php

namespace Database\Seeders;

use App\Models\AgencyDealSyncSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * QaConfigSeeder — the single "post-load reseed" entry point for the qa1 sync.
 *
 * A live→qa1 refresh overwrites qa1 with production data, which has NONE of the
 * not-yet-live QA config (DR2 pipeline templates, the distribution matrix, deal-
 * property-sync toggles). This seeder re-lays that DETERMINISTIC config after the
 * restore, before the site/worker resume, so every sync leaves qa1 whole.
 *
 * THE CLASS RULE (see scripts/qa1/README.md): any feature not yet live MUST
 * register its QA seed HERE (or be added to the sync's PRESERVE set for
 * user-maintained data), or the next sync eats it.
 *
 * Idempotent. Refuses to run on production. User-maintained QA data (attorney
 * directory, sessions) is PRESERVED by the sync script's snapshot/restore, not
 * seeded here.
 */
class QaConfigSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('QaConfigSeeder refuses to run on production.');
            return;
        }

        // 1. DR2 pipeline templates + steps (provisions agencies with none).
        $this->call(DealPipelineTemplateSeeder::class);

        // 2. DR2 distribution matrix (stage × doc-type × party defaults).
        $this->call(DealStageDocumentRuleSeeder::class);

        // AT-227 — type-level document distribution matrix (null-stage rules).
        if (class_exists(\Database\Seeders\DocumentDistributionMatrixSeeder::class)) {
            $this->call(\Database\Seeders\DocumentDistributionMatrixSeeder::class);
        }

        // 3. Deal-property-sync settings — pre-warm the firstOrCreate default per agency
        //    so the settings surface never has to create-on-first-view mid-walkthrough.
        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            AgencyDealSyncSettings::forAgency((int) $agencyId);
        }

        $this->command?->info('QaConfigSeeder — DR2 QA config reseeded (templates + distribution rules + sync settings).');
    }
}
