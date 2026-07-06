<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 WS-V6 — free-form deal remarks (DR1 parity + vision pt1 "agents supply
 * feedback as the deal progresses").
 *
 * DR1 stores remarks as `deal_logs` rows (event_type='remark_added', SoftDeletes)
 * plus a denormalised `deals.remarks` (latest). Investigation confirmed the
 * denormalised column feeds NO report/TV/rollup and the DR1↔DR2 mirror is
 * deal-field-level only (no remark crossing) — so DR2 keeps a native,
 * soft-deletable remark table and interleaves it with the immutable
 * `deal_activity_log` in the deal view (one chronological timeline). No mirror,
 * no denormalised column, no notification (DR1 addRemark notifies no-one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_v2_remarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('user_id');       // author
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id', 'dvr_agency_fk')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('deal_id', 'dvr_deal_fk')->references('id')->on('deals_v2')->cascadeOnDelete();
            $table->foreign('user_id', 'dvr_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['deal_id', 'created_at'], 'dvr_deal_created_idx');
            $table->index(['agency_id'], 'dvr_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_v2_remarks');
    }
};
