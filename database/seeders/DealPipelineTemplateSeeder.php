<?php

namespace Database\Seeders;

use App\Models\DealV2\DealPipelineTemplate;
use App\Models\User;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the three shipped default pipeline templates (Standard Bond Sale,
 * Cash Sale, Sale of Second Property).
 *
 * AT-158 WS-R1 — DE-LANDMINED. The previous implementation ran an
 * agency-blind hard delete:
 *     DealPipelineStep::query()->forceDelete();
 *     DealPipelineTemplate::query()->forceDelete();
 * which — wired into scripts/deploy.sh — would wipe EVERY agency's pipeline
 * templates and steps (orphaning live deal step-instances) on the next
 * deploy, contradicting Non-Negotiable #1 (no hard deletes) and the
 * "all seeders idempotent" assertion in DatabaseSeeder / deploy.sh.
 *
 * Now the seeding runs through DealPipelineTemplateProvisioner, which is
 * idempotent, additive, and agency-safe (match-or-create per agency; steps
 * seeded only for fresh templates; nothing ever deleted). Safe to run on
 * every deploy, every environment, any number of times.
 *
 * Behaviour: provisions the defaults for every agency that currently has no
 * active pipeline templates. Agencies that already have templates (default or
 * customised) are left untouched. The in-app "Load standard templates"
 * affordance on Pipeline Setup lets an admin (re-)provision their own agency
 * on demand via the SAME provisioner.
 */
class DealPipelineTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(DealPipelineTemplateProvisioner::class);

        $admin = User::where('is_admin', true)->first() ?? User::first();
        $adminId = $admin?->id;

        // Every agency that has NO pipeline templates yet gets the defaults.
        // (New/fresh tenants provisioned; existing/customised ones skipped.)
        $agencyIds = DB::table('agencies')->pluck('id');

        foreach ($agencyIds as $agencyId) {
            $hasAny = DealPipelineTemplate::where('agency_id', $agencyId)->exists();
            if ($hasAny) {
                continue;
            }

            $provisioner->provisionDefaultsForAgency((int) $agencyId, $adminId);
        }

        // Guarantee the primary/admin agency is provisioned even if the
        // agencies table is empty in some minimal environments.
        if ($agencyIds->isEmpty() && $admin?->agency_id) {
            $provisioner->provisionDefaultsForAgency((int) $admin->agency_id, $adminId);
        }
    }
}
