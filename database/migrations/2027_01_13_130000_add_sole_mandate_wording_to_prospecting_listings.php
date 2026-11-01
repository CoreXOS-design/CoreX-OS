<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-528 (mic-work-tab.md 13.4 / 13.9): the advert's own words contain one of the agency's mandate phrases. Set when a snippet / description arrives (observer) and by mic:match-sole-mandate. NULL = no text read yet. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'sole_mandate_wording')) {
            Schema::table('prospecting_listings', function (Blueprint $t) {
                $t->boolean('sole_mandate_wording')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecting_listings', 'sole_mandate_wording')) {
            Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dropColumn('sole_mandate_wording'));
        }
    }
};
