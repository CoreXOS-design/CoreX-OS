<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Adverts view, capture (.ai/specs/mic-work-tab.md 13.6 and 11.2.4): the advert the way the portal shows it.
 *
 *  - card_heading         the results card's heading as shown ("3 Bedroom House")
 *  - description_snippet  the results card's description text as shown, at most 200 characters
 *  - description          the full description from the listing page
 *  - photo_urls / photo_count  every gallery photo URL on the listing page and how many there are
 *  - features             the listing page's feature rows as a key => value map (not a fixed list)
 *  - parking              the "Parking" row of the listing page (garages keeps the card's Parking Spaces icon)
 *
 * Per-agency table only; none of this goes to the shared pool (advert text and photos are not pool facts).
 * Every column is nullable (NULL = not read yet). Nothing existing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $t) {
            if (! Schema::hasColumn('prospecting_listings', 'card_heading')) {
                $t->string('card_heading', 160)->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'description_snippet')) {
                $t->string('description_snippet', 200)->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'description')) {
                $t->text('description')->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'photo_urls')) {
                $t->json('photo_urls')->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'photo_count')) {
                $t->unsignedSmallInteger('photo_count')->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'features')) {
                $t->json('features')->nullable();
            }
            if (! Schema::hasColumn('prospecting_listings', 'parking')) {
                $t->unsignedTinyInteger('parking')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $t) {
            foreach (['card_heading', 'description_snippet', 'description', 'photo_urls', 'photo_count', 'features', 'parking'] as $c) {
                if (Schema::hasColumn('prospecting_listings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
