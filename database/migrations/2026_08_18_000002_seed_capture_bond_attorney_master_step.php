<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Feature 1 — insert the "Capture Bond Attorney" step into the SHARED master pipeline catalog
 * (deal_pipeline_condition_steps) under every `bond` condition, so NEWLY-built bond pipelines
 * include it. Reads via Dr2ConditionCatalog::loadFromDb(). ADDITIVE: existing deals keep their
 * already-instantiated steps and are unaffected — only new pipeline assembly gains the step.
 *
 * The step follows the grant marker (__grant__ → resolved to 'granted' at compose), is NOT
 * suspensive (does not gate Granted), and is manual_tick. Enforcement (deal can't reach Registered
 * until captured) lives in Dr1PipelineService, not here.
 *
 * Idempotent — skips any bond condition that already carries the step. Mirrors the PHP fallback
 * in Dr2ConditionCatalog::definition(). GLOBAL reference row — carry to Staging via the migration
 * (raw insert), NOT a lane-only seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $bondConditions = DB::table('deal_pipeline_conditions')->where('key', 'bond')->get();

        foreach ($bondConditions as $cond) {
            $already = DB::table('deal_pipeline_condition_steps')
                ->where('condition_id', $cond->id)
                ->where('step_key', 'bond_attorney')
                ->exists();
            if ($already) {
                continue;
            }

            // Copy the exact "empty deps" representation from an existing bond step (schema-accurate,
            // whether the column stores NULL or '[]').
            $emptyDeps = optional(
                DB::table('deal_pipeline_condition_steps')->where('condition_id', $cond->id)->first()
            )->deps_keys;

            DB::table('deal_pipeline_condition_steps')->insert([
                'condition_id'        => $cond->id,
                'step_key'            => 'bond_attorney',
                'name'                => 'Capture Bond Attorney',
                'follows_key'         => '__grant__',
                'deps_keys'           => $emptyDeps,
                'days_offset'         => 0,
                'is_milestone'        => 0,
                'is_suspensive'       => 0,
                'is_anchor'           => 0,
                'completion_type'     => 'manual_tick',
                'status_trigger'      => null,
                'manual_due_option'   => null,
                'requires_option'     => null,
                'requires_funds_mode' => null,
                'expand'              => null,
                'pipeline_step_id'    => null,
                'agency_id'           => $cond->agency_id,
                'position'            => 45,
                'display_priority'    => 45,
                'is_grant_marker'     => 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('deal_pipeline_condition_steps')->where('step_key', 'bond_attorney')->delete();
    }
};
