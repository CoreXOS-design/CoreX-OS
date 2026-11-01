<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-528 (13.4, Johan 1 Nov): when did we first see an advert as sole mandate (`prospecting_listings.sole_mandate_first_seen_at`), and which files carry a mandate that
 * may be ending (`mic_file_stats.sole_mandate_ending`, read by the Stuck tile). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'sole_mandate_first_seen_at')) {
            Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dateTime('sole_mandate_first_seen_at')->nullable());
        }
        if (Schema::hasTable('mic_file_stats') && ! Schema::hasColumn('mic_file_stats', 'sole_mandate_ending')) {
            Schema::table('mic_file_stats', fn (Blueprint $t) => $t->unsignedTinyInteger('sole_mandate_ending')->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecting_listings', 'sole_mandate_first_seen_at')) {
            Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dropColumn('sole_mandate_first_seen_at'));
        }
        if (Schema::hasTable('mic_file_stats') && Schema::hasColumn('mic_file_stats', 'sole_mandate_ending')) {
            Schema::table('mic_file_stats', fn (Blueprint $t) => $t->dropColumn('sole_mandate_ending'));
        }
    }
};
