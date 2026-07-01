<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the Private Property (PP) photo cap agency-configurable — mirrors the
 * P24 photo-cap treatment (AT-101).
 *
 * PP was hardcoded to 20 images (commit d6621111, a quick "fix" against PP
 * transaction timeouts) — the same arbitrary-cap-off-allImages() bug class P24
 * carried before AT-101. This lifts the cap to 150 by default (matching P24)
 * while keeping it per-agency tunable, since PP downloads each image URL inside
 * its SOAP transaction and an over-large gallery can still time PP out.
 *
 * The canonical runtime default lives on App\Models\Agency::PP_DEFAULT_MAX_PHOTOS;
 * the column default below is kept in lockstep with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'pp_max_photos')) {
                $table->unsignedSmallInteger('pp_max_photos')->nullable()->default(150)->after('pp_webhook_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'pp_max_photos')) {
                $table->dropColumn('pp_max_photos');
            }
        });
    }
};
