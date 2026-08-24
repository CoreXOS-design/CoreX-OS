<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad Manager — "how many ads have been created for this property, and when
 * was the last one?" A running counter + timestamp, stamped whenever the
 * bulk Ad Manager (or its Printable Brochure path) actually generates an
 * asset for a property — displayed as a badge on that property's card in
 * the Ad Manager's own selection grid. Additive, nullable/defaulted, no
 * backfill needed (every existing property simply starts at 0/null, which
 * is honest — CoreX has no historical record of past Ad Manager runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('properties', 'ad_generated_count')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedInteger('ad_generated_count')->default(0)->after('p24_stats_synced_at');
            $table->timestamp('ad_last_generated_at')->nullable()->after('ad_generated_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('properties', 'ad_generated_count')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['ad_generated_count', 'ad_last_generated_at']);
        });
    }
};
