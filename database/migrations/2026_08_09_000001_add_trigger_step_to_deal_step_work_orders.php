<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-229 §17 — right-panel up-front COC config. Each configured work order remembers
 * WHICH pipeline step, when completed, fires its send (default: Bond Granted). The
 * trigger hook in Dr1PipelineService::completeStep sends every pending row whose
 * trigger_step_instance_id equals the just-completed step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('trigger_step_instance_id')->nullable()->after('deal_step_instance_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_work_orders', function (Blueprint $table) {
            $table->dropColumn('trigger_step_instance_id');
        });
    }
};
