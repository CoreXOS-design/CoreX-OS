<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-520 4.13: the merge guard - how far apart two adverts on one property may be (price band %, size band %, bedroom gap). Additive. */
return new class extends Migration
{
    private const COLUMNS = ['merge_price_band_pct' => 40, 'merge_size_band_pct' => 40, 'merge_beds_gap' => 1];

    public function up(): void
    {
        foreach (self::COLUMNS as $col => $default) {
            if (! Schema::hasColumn('address_match_settings', $col)) {
                Schema::table('address_match_settings', fn (Blueprint $t) => $t->unsignedSmallInteger($col)->default($default));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::COLUMNS) as $col) {
            if (Schema::hasColumn('address_match_settings', $col)) {
                Schema::table('address_match_settings', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
