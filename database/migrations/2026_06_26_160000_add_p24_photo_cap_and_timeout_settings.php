<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-101 — make the P24 photo cap and HTTP read timeout agency-configurable.
 *
 * Photo cap default is 150 (raised from the prior hardcoded 30 after the
 * AT-101 probe proved 150 photos safe — 21.7s round trip, 5.5× under the 120s
 * read timeout); read timeout stays 120s → 180s job. The canonical runtime
 * defaults live on
 * App\Models\Agency::P24_DEFAULT_MAX_PHOTOS / P24_DEFAULT_HTTP_READ_TIMEOUT;
 * the column defaults below are kept in lockstep with them.
 *
 * Also adds round_trip_ms to p24_syndication_logs to capture real submit
 * latency (the data behind the photo-count probe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('p24_max_photos')->nullable()->default(150)->after('p24_enabled');
            $table->unsignedSmallInteger('p24_http_read_timeout')->nullable()->default(120)->after('p24_max_photos');
        });

        Schema::table('p24_syndication_logs', function (Blueprint $table) {
            $table->unsignedInteger('round_trip_ms')->nullable()->after('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['p24_max_photos', 'p24_http_read_timeout']);
        });

        Schema::table('p24_syndication_logs', function (Blueprint $table) {
            $table->dropColumn('round_trip_ms');
        });
    }
};
