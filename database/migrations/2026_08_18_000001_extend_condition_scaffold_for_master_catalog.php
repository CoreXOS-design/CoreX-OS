<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 — Master pipeline template as the SOURCE of the composable Deal Structure
 * (Phase 1 of the template-as-source foundation). The two scaffold tables
 * (deal_pipeline_conditions / deal_pipeline_condition_steps) were created inert; this
 * migration makes deal_pipeline_condition_steps a self-contained STEP-DEFINITION store
 * so the whole Dr2ConditionCatalog can be seeded into the DB and read back identically.
 *
 * Behaviour-preserving: additive columns only + one nullable relaxation. No data written
 * here (the global catalog is populated by Dr2PipelineCatalogSeeder, which the reader
 * falls back away from if absent — so an un-seeded environment is never broken).
 */
return new class extends Migration
{
    public function up(): void
    {
        // pipeline_step_id was the original "link to a deal_pipeline_steps row" idea; the
        // composable catalog stores its step definition inline instead, so relax it to
        // nullable (no FK exists; MySQL MODIFY keeps it a plain nullable BIGINT UNSIGNED).
        DB::statement('ALTER TABLE deal_pipeline_condition_steps MODIFY pipeline_step_id BIGINT UNSIGNED NULL');

        // The master template is GLOBAL reference data (agency_id NULL = "owned by CoreX",
        // the sanctioned shared-row convention — see BelongsToAgency). The scaffold shipped
        // agency_id NOT NULL, so relax both tables to allow the global rows. Phase 2's
        // per-agency overrides will carry a real agency_id.
        DB::statement('ALTER TABLE deal_pipeline_conditions MODIFY agency_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE deal_pipeline_condition_steps MODIFY agency_id BIGINT UNSIGNED NULL');

        // Guarded adds — the scaffold tables have shipped through several lanes; only add
        // what is missing so a partially-migrated environment never errors on re-run.
        Schema::table('deal_pipeline_condition_steps', function (Blueprint $table) {
            // The step's stable symbolic identity + display + wiring — a faithful
            // serialisation of one Dr2ConditionCatalog step def.
            $add = function (string $col, callable $make) use ($table) {
                if (! Schema::hasColumn('deal_pipeline_condition_steps', $col)) {
                    $make($table);
                }
            };
            $add('step_key',            fn ($t) => $t->string('step_key', 60)->nullable()->after('condition_id'));
            $add('name',                fn ($t) => $t->string('name')->nullable()->after('step_key'));
            $add('follows_key',         fn ($t) => $t->string('follows_key', 60)->nullable()->after('name'));      // symbolic predecessor key (or __grant__)
            $add('deps_keys',           fn ($t) => $t->json('deps_keys')->nullable()->after('follows_key'));        // AND-gate fan-in (symbolic keys)
            $add('days_offset',         fn ($t) => $t->integer('days_offset')->default(0)->after('deps_keys'));
            $add('is_milestone',        fn ($t) => $t->boolean('is_milestone')->default(false)->after('days_offset'));
            $add('is_suspensive',       fn ($t) => $t->boolean('is_suspensive')->default(false)->after('is_milestone'));
            $add('is_anchor',           fn ($t) => $t->boolean('is_anchor')->default(false)->after('is_suspensive'));
            $add('completion_type',     fn ($t) => $t->string('completion_type', 40)->nullable()->after('is_anchor'));
            $add('status_trigger',      fn ($t) => $t->string('status_trigger', 40)->nullable()->after('completion_type'));
            // Which captured option date seeds this step's manual Due (bond_due, deposit_due,
            // proof_due, payment_dues, property_sold_due). Null → no manual due.
            $add('manual_due_option',   fn ($t) => $t->string('manual_due_option', 40)->nullable()->after('status_trigger'));
            // Option-driven inclusion/expansion markers (kept procedural in the reader):
            //  requires_option      — step present only when this bool option is on (deposit)
            //  requires_funds_mode  — step present only in this cash funds_mode (proof_later)
            //  expand               — 'payments' → one row expanded to N per the payments count
            $add('requires_option',     fn ($t) => $t->string('requires_option', 40)->nullable()->after('manual_due_option'));
            $add('requires_funds_mode', fn ($t) => $t->string('requires_funds_mode', 40)->nullable()->after('requires_option'));
            $add('expand',              fn ($t) => $t->string('expand', 20)->nullable()->after('requires_funds_mode'));
        });

        if (! $this->indexExists('deal_pipeline_condition_steps', 'dpcs_step_key_idx')) {
            Schema::table('deal_pipeline_condition_steps', function (Blueprint $table) {
                $table->index('step_key', 'dpcs_step_key_idx');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }

    public function down(): void
    {
        Schema::table('deal_pipeline_condition_steps', function (Blueprint $table) {
            $table->dropIndex('dpcs_step_key_idx');
            $table->dropColumn([
                'step_key', 'name', 'follows_key', 'deps_keys', 'days_offset',
                'is_milestone', 'is_suspensive', 'is_anchor', 'completion_type',
                'status_trigger', 'manual_due_option', 'requires_option',
                'requires_funds_mode', 'expand',
            ]);
        });

        DB::statement('ALTER TABLE deal_pipeline_condition_steps MODIFY pipeline_step_id BIGINT UNSIGNED NOT NULL');
    }
};
