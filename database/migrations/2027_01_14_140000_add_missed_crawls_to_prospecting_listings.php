<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 (1 Nov, "Not seen since" wrong on 76 Colin Street): one complete crawl can miss a live advert (P24 re-orders pages while it is read). An advert is only set
 * gone after the agency's `gone_confirm_crawls` (default 2) consecutive complete crawls of its own suburb all missed it; these two columns count that.
 * Cleared on every sighting. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'missed_crawls')) {
            Schema::table('prospecting_listings', function (Blueprint $t) {
                $t->unsignedTinyInteger('missed_crawls')->default(0);
                $t->unsignedBigInteger('missed_crawl_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecting_listings', 'missed_crawls')) {
            Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dropColumn(['missed_crawls', 'missed_crawl_id']));
        }
    }
};
