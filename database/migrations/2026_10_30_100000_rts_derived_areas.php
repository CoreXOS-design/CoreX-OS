<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Johan's ruling of 13 Oct 2026 16:03: an agency's working areas are DERIVED from its own stock and its Market
 * Intelligence data, not hand-set. Two additions: the agency setting "minimum properties for a suburb to count as a working
 * area" (default 1) and the list of areas an administrator has hidden from Road to Sold (soft, restorable - never a delete).
 * Spec: .ai/specs/road-to-sold.md section 1 AREAS and 3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rts_settings', 'min_area_properties')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('min_area_properties')->default(1);
            });
        }

        if (! Schema::hasTable('rts_hidden_areas')) {
            Schema::create('rts_hidden_areas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->string('track', 16)->default('sales');
                $table->string('kind', 10);                          // 'suburb' | 'town'
                $table->unsignedBigInteger('p24_suburb_id')->nullable();
                $table->unsignedBigInteger('p24_city_id')->nullable();
                $table->unsignedBigInteger('hidden_by_user_id')->nullable();
                $table->string('reason', 191)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['agency_id', 'track', 'kind'], 'rts_hidden_areas_scope_idx');
                $table->index(['agency_id', 'p24_suburb_id'], 'rts_hidden_areas_suburb_idx');
                $table->index(['agency_id', 'p24_city_id'], 'rts_hidden_areas_city_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_hidden_areas');
        if (Schema::hasColumn('rts_settings', 'min_area_properties')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->dropColumn('min_area_properties');
            });
        }
    }
};
