<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-398 follow-up (split-pricing entry) — `deal_properties.allocated_price`
 * was created as unsignedBigInteger, but `deals.property_value` (the field
 * it must sum to/mirror) is decimal(12,2). An integer column would round or
 * reject a price with cents. Corrected before any real data was ever written
 * to the column (verified: all 16 existing rows have allocated_price NULL) —
 * raw SQL, not Schema::table()->change(), since this box has no
 * doctrine/dbal installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE deal_properties MODIFY allocated_price DECIMAL(12,2) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deal_properties MODIFY allocated_price BIGINT UNSIGNED NULL');
    }
};
