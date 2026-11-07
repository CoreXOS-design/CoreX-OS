<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2 (.ai/specs/auctions.md §12 step 0).
 *
 * The AT-432 auction module is retired. Its tables are RENAMED, never dropped:
 * every row survives under a `legacy_` name until Johan orders the backup and the
 * drop. Renaming also frees the original names for the v2 tables created next.
 * RENAME TABLE keeps foreign keys pointing at the renamed tables, so nothing breaks.
 */
return new class extends Migration
{
    /** Order matters for readability only; one RENAME statement does them atomically. */
    private const TABLES = [
        'auction_bids', 'auction_bidders', 'auction_lot_viewings', 'auction_lot_status_history',
        'auction_lots', 'auctions', 'agency_auction_settings',
    ];

    public function up(): void
    {
        $pairs = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasTable('legacy_' . $table)) {
                $pairs[] = "`{$table}` TO `legacy_{$table}`";
            }
        }
        if ($pairs) {
            DB::statement('RENAME TABLE ' . implode(', ', $pairs));
        }
    }

    public function down(): void
    {
        $pairs = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable('legacy_' . $table) && ! Schema::hasTable($table)) {
                $pairs[] = "`legacy_{$table}` TO `{$table}`";
            }
        }
        if ($pairs) {
            DB::statement('RENAME TABLE ' . implode(', ', $pairs));
        }
    }
};
