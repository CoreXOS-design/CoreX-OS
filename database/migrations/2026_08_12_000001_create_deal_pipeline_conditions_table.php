<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 Phase 1 — template layer: the suspensive-condition packs a pipeline
 * template offers (cash / bond / sale_of_another / deposit). ADDITIVE new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_pipeline_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_template_id');
            $table->unsignedBigInteger('agency_id');
            $table->string('key', 40);              // cash | bond | sale_of_another | deposit
            $table->string('label');
            $table->boolean('is_default')->default(false);
            $table->json('options_schema')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'pipeline_template_id'], 'dpc_agency_template_idx');
            $table->index('pipeline_template_id', 'dpc_template_idx');
            $table->index('key', 'dpc_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_pipeline_conditions');
    }
};
