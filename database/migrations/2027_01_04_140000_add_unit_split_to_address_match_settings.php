<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-520 step 4.5 (property-identity.md 17.5): when a property file looks like several units - the agency's thresholds. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('address_match_settings', 'unit_split_min_adverts')) {
                $t->unsignedTinyInteger('unit_split_min_adverts')->default(5);
            }
            if (! Schema::hasColumn('address_match_settings', 'unit_split_price_spread')) {
                $t->decimal('unit_split_price_spread', 3, 1)->default(2.0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            foreach (['unit_split_min_adverts', 'unit_split_price_spread'] as $c) {
                if (Schema::hasColumn('address_match_settings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
