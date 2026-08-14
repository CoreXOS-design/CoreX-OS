<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-229 (multi-supplier, on-site 2026-07-20) — a pipeline step can trigger SEVERAL work
 * orders at once (a "Certificates of Compliance" step → Electrical + Gas + Beetle + Plumbing,
 * each its own supplier/service). Move the single per-step config
 * (sends_work_order / work_order_service_type / work_order_trigger_point) to a COLLECTION:
 * N entries per step, each with its own service type + trigger timing.
 *
 * The old single columns are LEFT in place (legacy, no hard delete) and any step that had one
 * is preserved by seeding a one-entry collection from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_pipeline_step_work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_step_id')->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('service_type', 40)->nullable();
            $table->string('trigger_point', 20)->default('activated');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pipeline_step_id')->references('id')->on('deal_pipeline_steps')->cascadeOnDelete();
        });

        // Preserve every existing single-config as a one-entry collection.
        if (Schema::hasColumn('deal_pipeline_steps', 'sends_work_order')) {
            $now = now();
            DB::table('deal_pipeline_steps')
                ->where('sends_work_order', 1)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->each(function ($step) use ($now) {
                    DB::table('deal_pipeline_step_work_orders')->insert([
                        'pipeline_step_id' => $step->id,
                        'agency_id'        => $step->agency_id,
                        'service_type'     => $step->work_order_service_type,
                        'trigger_point'    => $step->work_order_trigger_point ?: 'activated',
                        'sort_order'       => 0,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_pipeline_step_work_orders');
        // The legacy single columns were intentionally never dropped — nothing to restore.
    }
};
