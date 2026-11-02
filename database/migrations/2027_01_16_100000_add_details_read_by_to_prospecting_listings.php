<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mic-work-tab.md 13.16 / 13.16b ("Get full details"): who pressed the button for this advert, and a fingerprint of the card wording (heading + snippet) as it was
 * when the page was read, so a later change of wording can be told from "nothing changed". `details_read_at` and `details_read_price` already exist. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'details_read_by')) {
            Schema::table('prospecting_listings', function (Blueprint $t) {
                $t->unsignedBigInteger('details_read_by')->nullable();
                $t->string('details_read_card_hash', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecting_listings', 'details_read_by')) {
            Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dropColumn(['details_read_by', 'details_read_card_hash']));
        }
    }
};
