<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3g B3 — composite (latitude, longitude) index on properties.
 *
 * The Map module runs bounding-box queries (latitude BETWEEN .. AND ..
 * AND longitude BETWEEN .. AND ..) per pan/zoom event. Other source
 * tables (tracked_properties, market_report_comp_rows, market_reports)
 * already have this composite via Phase 3a; properties was missed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->indexExists('properties', 'idx_properties_geo')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->index(['latitude', 'longitude'], 'idx_properties_geo');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('properties', 'idx_properties_geo')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropIndex('idx_properties_geo');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
            return !empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }
};
