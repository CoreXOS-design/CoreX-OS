<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-520 step 4.5b: vacant land under one street address is several stands when their sizes differ by more than this percentage. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('address_match_settings', 'unit_split_land_size_gap_pct')) {
            Schema::table('address_match_settings', function (Blueprint $t) {
                $t->unsignedTinyInteger('unit_split_land_size_gap_pct')->default(10);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('address_match_settings', 'unit_split_land_size_gap_pct')) {
            Schema::table('address_match_settings', fn (Blueprint $t) => $t->dropColumn('unit_split_land_size_gap_pct'));
        }
    }
};
