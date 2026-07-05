<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 WS-V2 — suspensive conditions + the auto-move stage gate.
 *
 * Johan's vision pt4 + Ruling (a): step completions drive stage movement with
 * AND-gating. When the LAST suspensive-condition step completes (bond granted on
 * a bond-only deal; bond AND deposit on a two-condition deal), the deal moves
 * itself to Granted — the relevant parties are notified, an audit entry records
 * the trigger, and a ONE-CLICK UNDO is available. Default = AUTO; the
 * agency-configurable alternative is PROMPT ("all conditions met — move to
 * Granted?"). A declined suspensive outcome routes to the DECLINED path
 * (remaining steps voided with audit, never hard-deleted).
 *
 *   deals_v2.status                     += 'declined' (distinct terminal state;
 *                                          DR1 'D' ↔ DR2 'declined')
 *   deal_pipeline_steps.is_suspensive   — template: this step is a suspensive condition
 *   deal_step_instances.is_suspensive   — runtime copy
 *   agencies.deal_v2_stage_gate_mode    — 'auto' (default) | 'prompt'
 *   deal_stage_moves                    — every stage advance (auto / pending-prompt /
 *                                          confirmed / undone), the undo + audit spine
 */
return new class extends Migration
{
    public function up(): void
    {
        // Distinct 'declined' terminal state (bond/suspensive condition failed),
        // separate from an operational 'cancelled'/withdrawal.
        DB::statement(
            "ALTER TABLE `deals_v2` MODIFY `status` " .
            "enum('active','granted','completed','cancelled','on_hold','declined') " .
            "COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'"
        );

        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->boolean('is_suspensive')->default(false)->after('is_milestone');
        });
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->boolean('is_suspensive')->default(false)->after('is_milestone');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->enum('deal_v2_stage_gate_mode', ['auto', 'prompt'])
                ->default('auto')
                ->after('deal_v2_bm_approval_enabled');
        });

        Schema::create('deal_stage_moves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('deal_id');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            // why the move was proposed/applied
            $table->enum('reason', ['suspensive_conditions_met', 'registration', 'declined', 'manual'])
                ->default('suspensive_conditions_met');
            $table->unsignedBigInteger('trigger_step_instance_id')->nullable();
            $table->enum('mode', ['auto', 'prompt'])->default('auto');
            // applied = status changed; pending = prompt awaiting confirm; confirmed =
            // a prompt was confirmed (also applied); undone = reverted; dismissed = prompt declined
            $table->enum('state', ['applied', 'pending', 'confirmed', 'undone', 'dismissed'])->default('applied');
            $table->unsignedBigInteger('moved_by_id')->nullable(); // null = system/auto
            $table->timestamp('moved_at')->nullable();
            $table->unsignedBigInteger('undone_by_id')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id', 'dsm_agency_fk')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('deal_id', 'dsm_deal_fk')->references('id')->on('deals_v2')->cascadeOnDelete();
            $table->foreign('trigger_step_instance_id', 'dsm_step_fk')->references('id')->on('deal_step_instances')->nullOnDelete();
            $table->index(['deal_id', 'state'], 'dsm_deal_state_idx');
            $table->index(['agency_id'], 'dsm_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_moves');
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('deal_v2_stage_gate_mode');
        });
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->dropColumn('is_suspensive');
        });
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->dropColumn('is_suspensive');
        });
        // Revert any 'declined' rows to 'cancelled' before shrinking the enum.
        DB::statement("UPDATE `deals_v2` SET `status`='cancelled' WHERE `status`='declined'");
        DB::statement(
            "ALTER TABLE `deals_v2` MODIFY `status` " .
            "enum('active','granted','completed','cancelled','on_hold') " .
            "COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'"
        );
    }
};
