<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC compose reversibility (Johan 2026-08-14) — R1/R2.
 *
 * `contact_property.source` records HOW a seller link was made ('deed' auto-link vs 'manual'), so
 * replacing/unlinking a deed removes only its own auto-linked sellers and never a manual one.
 *
 * `prospecting_seller_removals` records deed owners the agent explicitly REMOVED from a listing, so
 * the deed auto-link never silently re-adds them on reload (removal is sticky until re-added).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_property', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('is_primary'); // 'deed' | 'manual'
        });

        Schema::create('prospecting_seller_removals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('prospecting_listing_id');
            $table->string('id_number', 20);
            $table->unsignedBigInteger('removed_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['prospecting_listing_id', 'id_number'], 'prosp_seller_removal_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_seller_removals');
        Schema::table('contact_property', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
