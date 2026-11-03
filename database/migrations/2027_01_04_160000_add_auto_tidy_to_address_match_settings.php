<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-520 step 4.10: one switch for the automatic tidy-up that runs after every import and nightly (advert-vs-file link audit + closing moot questions). On by default. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('address_match_settings', 'auto_tidy')) {
            Schema::table('address_match_settings', function (Blueprint $t) {
                $t->boolean('auto_tidy')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('address_match_settings', 'auto_tidy')) {
            Schema::table('address_match_settings', fn (Blueprint $t) => $t->dropColumn('auto_tidy'));
        }
    }
};
