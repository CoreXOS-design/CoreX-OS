<?php

namespace Database\Seeders;

use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\User;
use App\Services\DealV2\DealDistributionRuleProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-225 · DR2 §8.1 — seeds the DEFAULT distribution matrix per agency.
 *
 * Idempotent + additive + agency-safe, mirroring {@see DealPipelineTemplateSeeder}.
 * Runs AFTER the pipeline template seeder (the rules reference template steps).
 * Only provisions an agency that HAS a pipeline template (so steps exist) but has
 * NO distribution rules yet — existing/customised agencies are skipped, so a deploy
 * re-run never disturbs an agency's own matrix. Registered in
 * `deploy:sync-reference-data` so it travels on git-pull deploys (AT-162).
 */
class DealDistributionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(DealDistributionRuleProvisioner::class);

        $admin = User::where('is_admin', true)->first() ?? User::first();
        $adminId = $admin?->id;

        $agencyIds = DB::table('agencies')->pluck('id');
        if ($agencyIds->isEmpty() && $admin?->agency_id) {
            $agencyIds = collect([$admin->agency_id]);
        }

        foreach ($agencyIds as $agencyId) {
            $agencyId = (int) $agencyId;

            // Needs a pipeline template (steps to attach rules to); skip if none yet.
            $hasTemplate = DealPipelineTemplate::withoutGlobalScopes()
                ->where('agency_id', $agencyId)->exists();
            if (!$hasTemplate) {
                continue;
            }

            // Additive: only fill defaults for agencies with no rules yet.
            $hasRules = DealStageDocumentRule::withoutGlobalScopes()
                ->where('agency_id', $agencyId)->exists();
            if ($hasRules) {
                continue;
            }

            $provisioner->provisionDefaultsForAgency($agencyId, $adminId);
        }
    }
}
