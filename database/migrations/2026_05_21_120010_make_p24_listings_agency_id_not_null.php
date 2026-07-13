<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Phase A1 — make `p24_listings.agency_id` NOT NULL.
 *
 * Runs after the backfill (#9). Guards against rows still NULL so the ALTER
 * doesn't fail mid-deploy: if any row is still NULL, throw with a useful
 * diagnostic so the operator can investigate rather than letting MySQL
 * dump a generic "Cannot add NOT NULL" error.
 */
return new class extends Migration {
    public function up(): void
    {
        $stillNull = DB::table('p24_listings')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException(
                "p24_listings still has {$stillNull} row(s) with NULL agency_id. "
                . 'Re-run migration 2026_05_21_120009_backfill_agency_id_on_p24_listings or investigate before re-attempting this migration.'
            );
        }

        // MySQL refuses NOT NULL on a column whose FK action is SET NULL —
        // SET NULL can't write into a NOT NULL column. So any existing FK on
        // agency_id must go before the column can change, and is re-added below
        // with RESTRICT, matching the spec's "never wipe market data on agency
        // delete".
        //
        // DETECT, do not assume. #8 (…120008) as it stands today creates
        // agency_id with an INDEX and NO foreign key — an earlier revision of it
        // created a nullOnDelete FK and was later edited. So environments that
        // migrated before that edit carry the constraint while a fresh replay has
        // none, and an unconditional dropForeign() dies with "Can't DROP
        // 'p24_listings_agency_id_foreign'". That is exactly what broke
        // `migrate:fresh --database=demo`: the demo connection has no schema
        // snapshot, so it replays all migrations from zero and hit this.
        foreach ($this->foreignKeysOn('p24_listings', 'agency_id') as $constraint) {
            DB::statement("ALTER TABLE `p24_listings` DROP FOREIGN KEY `{$constraint}`");
        }

        Schema::table('p24_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        Schema::table('p24_listings', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->restrictOnDelete();
        });
    }

    /**
     * Actual foreign-key constraint names on a column, straight from
     * information_schema — the only way to know whether a drop is safe.
     *
     * @return string[]
     */
    private function foreignKeysOn(string $table, string $column): array
    {
        return array_column(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        ), 'CONSTRAINT_NAME');
    }

    public function down(): void
    {
        // Reverse: drop the RESTRICT FK, relax to nullable, re-add the
        // nullOnDelete FK from #8.
        foreach ($this->foreignKeysOn('p24_listings', 'agency_id') as $constraint) {
            DB::statement("ALTER TABLE `p24_listings` DROP FOREIGN KEY `{$constraint}`");
        }

        Schema::table('p24_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->change();
        });

        Schema::table('p24_listings', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->nullOnDelete();
        });
    }
};
