<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 Phase 1 — which template steps each condition contributes to the pipeline,
 * and which one carries the Granted marker. ADDITIVE new table. Short explicit index
 * names (long table name → avoid the 64-char identifier limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_pipeline_condition_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('condition_id');
            $table->unsignedBigInteger('pipeline_step_id');
            $table->unsignedBigInteger('agency_id');
            $table->integer('position')->default(0);
            $table->boolean('is_grant_marker')->default(false);
            $table->timestamps();

            $table->index('condition_id', 'dpcs_condition_idx');
            $table->index('pipeline_step_id', 'dpcs_step_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_pipeline_condition_steps');
    }
};
