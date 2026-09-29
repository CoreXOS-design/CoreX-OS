<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md §3a (Johan, consent gate) — append-only
 * evidence that the importing agent confirmed they have the source agency's
 * permission to use its portal advert. One row per import AND per
 * re-import (never updated, never deleted — see OtherAgencyStockConsent's
 * overridden save()/delete()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_agency_stock_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('consented_at');
            $table->text('consent_wording');
            $table->unsignedInteger('consent_wording_version')->default(1);

            $table->string('portal', 8);
            $table->string('listing_ref', 64);
            $table->string('listing_url', 2048)->nullable();
            $table->string('source_agency_name')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_agency_stock_consents');
    }
};
