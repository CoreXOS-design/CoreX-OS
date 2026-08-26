<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 Phase 1 — per-deal active suspensive conditions (the deal's SET of peer
 * conditions, each pending → met/failed/waived, with its options + waive audit).
 * ADDITIVE new table; no writes to existing deals here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('agency_id');
            $table->string('key', 40);              // cash | bond | sale_of_another | deposit
            $table->enum('status', ['active', 'met', 'failed', 'waived'])->default('active');
            $table->json('options')->nullable();    // e.g. {"payments":2,"deposit":true}
            $table->text('waived_reason')->nullable();
            $table->string('addendum_ref')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deal_id', 'agency_id'], 'dc_deal_agency_idx');
            $table->index('key', 'dc_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_conditions');
    }
};
