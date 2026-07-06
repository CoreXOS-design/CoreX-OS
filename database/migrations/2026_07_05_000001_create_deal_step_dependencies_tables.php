<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 WS-V1 — the AND-gate / fan-in dependency model.
 *
 * The DR2 engine already advances downstream steps relative to a predecessor's
 * ACTUAL completion (event-driven — see the 2026-07-05 vision-alignment audit),
 * but a step could depend on exactly ONE predecessor (the single
 * `trigger_step_id` / `trigger_step_instance_id` FK). Real SA conveyancing has
 * FAN-IN gates — most importantly Deeds Office Lodgement, which cannot start
 * until EVERY compliance certificate, clearance, guarantee and the SARS receipt
 * are in. A single FK cannot express "wait on all of A, B and C".
 *
 * These two tables add OPTIONAL additional predecessors ALONGSIDE the existing
 * single trigger FK (which stays the primary/linear fast path — no behaviour
 * change for the common case). A step activates only when its primary trigger
 * AND every additional dependency are complete; its clock then starts from the
 * LATEST of those completions (the last blocker to clear).
 *
 *   deal_pipeline_step_dependencies   — template level (config)
 *   deal_step_instance_dependencies   — runtime level (resolved per deal at createDeal)
 *
 * Pure dependency edges managed by detach/reattach in a transaction (the DR1
 * `deal_user` pivot precedent, A7); no user-recoverable "record" lives here, so
 * no SoftDeletes — consistent with the codebase's pivot pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NOTE: explicit SHORT constraint names — Laravel's auto-generated FK
        // names (table + column + '_foreign') exceed MySQL's 64-char identifier
        // limit for these long table/column names, so every FK is named by hand.
        Schema::create('deal_pipeline_step_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('pipeline_step_id');       // the dependent step
            $table->unsignedBigInteger('depends_on_step_id');     // a predecessor it waits on
            $table->timestamps();

            $table->foreign('agency_id', 'dpsd_agency_fk')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('pipeline_step_id', 'dpsd_step_fk')->references('id')->on('deal_pipeline_steps')->cascadeOnDelete();
            $table->foreign('depends_on_step_id', 'dpsd_dep_fk')->references('id')->on('deal_pipeline_steps')->cascadeOnDelete();
            $table->unique(['pipeline_step_id', 'depends_on_step_id'], 'dpsd_step_dep_unique');
            $table->index(['agency_id'], 'dpsd_agency_idx');
        });

        Schema::create('deal_step_instance_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('deal_step_instance_id');        // the dependent instance
            $table->unsignedBigInteger('depends_on_step_instance_id');  // a predecessor instance it waits on
            $table->timestamps();

            $table->foreign('agency_id', 'dsid_agency_fk')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('deal_step_instance_id', 'dsid_inst_fk')->references('id')->on('deal_step_instances')->cascadeOnDelete();
            $table->foreign('depends_on_step_instance_id', 'dsid_dep_fk')->references('id')->on('deal_step_instances')->cascadeOnDelete();
            $table->unique(['deal_step_instance_id', 'depends_on_step_instance_id'], 'dsid_instance_dep_unique');
            $table->index(['agency_id'], 'dsid_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_instance_dependencies');
        Schema::dropIfExists('deal_pipeline_step_dependencies');
    }
};
