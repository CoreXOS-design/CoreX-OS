<?php

namespace Database\Seeders;

use App\Services\DealV2\Dr2ConditionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-334 — seed the GLOBAL master pipeline template (Phase 1 of template-as-source).
 *
 * Writes the canonical Dr2ConditionCatalog::definition() into the master-template rows
 * (deal_pipeline_conditions + deal_pipeline_condition_steps, GLOBAL: agency_id NULL,
 * pipeline_template_id = MASTER_TEMPLATE_ID) that Dr2ConditionCatalog now READS from.
 *
 * GLOBAL reference data (owned by CoreX, not a tenant) → it must travel on git-pull
 * deploys, so it is registered in `deploy:sync-reference-data` (seeders do NOT run on a
 * pull deploy — AT-162). Idempotent: replaces the global master's rows on every run, so
 * re-running only ever converges to the definition. Uses DB::table (no tenant hook / no
 * agency scope) and writes agency_id = NULL explicitly.
 */
class Dr2PipelineCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $def = app(Dr2ConditionCatalog::class)->definition();
        $tpl = Dr2ConditionCatalog::MASTER_TEMPLATE_ID;

        DB::transaction(function () use ($def, $tpl) {
            // Replace the existing global master (idempotent singleton).
            $condIds = DB::table('deal_pipeline_conditions')
                ->whereNull('agency_id')->where('pipeline_template_id', $tpl)->pluck('id');
            if ($condIds->isNotEmpty()) {
                DB::table('deal_pipeline_condition_steps')->whereIn('condition_id', $condIds)->delete();
                DB::table('deal_pipeline_conditions')->whereIn('id', $condIds)->delete();
            }

            $now = now();
            foreach ($def['conditions'] as $key => $c) {
                $condId = DB::table('deal_pipeline_conditions')->insertGetId([
                    'pipeline_template_id' => $tpl,
                    'agency_id'            => null,
                    'key'                  => $key,
                    'label'                => $c['label'],
                    'is_default'           => false,
                    'options_schema'       => isset($c['options']) && $c['options'] !== null
                        ? json_encode($c['options']) : null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);

                foreach ($c['steps'] as $t) {
                    DB::table('deal_pipeline_condition_steps')->insert([
                        'condition_id'        => $condId,
                        'pipeline_step_id'    => null,
                        'agency_id'           => null,
                        'position'            => (int) ($t['pos'] ?? 0),
                        'display_priority'    => (int) ($t['display_priority'] ?? $t['pos'] ?? 0),
                        'is_grant_marker'     => ! empty($t['grant_marker']),
                        'step_key'            => $t['key'],
                        'name'                => $t['name'],
                        'follows_key'         => $t['follows'] ?? null,
                        'deps_keys'           => isset($t['deps']) ? json_encode($t['deps']) : null,
                        'days_offset'         => (int) ($t['offset'] ?? 0),
                        'is_milestone'        => ! empty($t['milestone']),
                        'is_suspensive'       => ! empty($t['suspensive']),
                        'is_anchor'           => ! empty($t['anchor']),
                        'completion_type'     => $t['completion'] ?? null,
                        'status_trigger'      => $t['status_trigger'] ?? null,
                        'manual_due_option'   => $t['manual_due_option'] ?? null,
                        'requires_option'     => $t['requires_option'] ?? null,
                        'requires_funds_mode' => $t['requires_funds_mode'] ?? null,
                        'expand'              => $t['expand'] ?? null,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                }
            }
        });
    }
}
