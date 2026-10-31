<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab (AT-519, conductor walk 31 Oct): the price move shown on a row must come from ONE advert.
 * mic_file_stats now names the file's LEAD advert (the live advert with the lowest asking price, ties: seen first) and stores that
 * advert's own first price, move and cut figures (cuts_count / cut_amount / last_cut_at / cuts_30d / cuts_90d are now lead-advert-only);
 * `cuts_any` keeps the "some live advert on this file was cut" count that tile 1 filters on. `owner_advert` (tile 2) and `claimed`
 * (an active claim on any live advert; "unclaimed first" ordering) are stored so the list never recounts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mic_file_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_listing_id')->nullable()->after('price');
            $table->unsignedInteger('price_first')->nullable()->after('lead_listing_id');
            $table->integer('price_move')->nullable()->after('price_first');      // lead price - its first price; negative = down
            $table->unsignedSmallInteger('cuts_any')->default(0)->after('cuts_90d');
            $table->boolean('owner_advert')->default(false)->after('own_stock');
            $table->boolean('claimed')->default(false)->after('owner_advert');
            $table->index(['agency_id', 'owner_advert', 'claimed'], 'mfs_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mic_file_stats', function (Blueprint $table) {
            $table->dropIndex('mfs_owner_idx');
            $table->dropColumn(['lead_listing_id', 'price_first', 'price_move', 'cuts_any', 'owner_advert', 'claimed']);
        });
    }
};
