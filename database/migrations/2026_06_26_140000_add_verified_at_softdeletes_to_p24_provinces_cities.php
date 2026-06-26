<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the AT-105 `p24_verified_at` "seen in the last P24 sync" stamp up the
 * tree to provinces and cities, and gives both soft-deletes — so the daily
 * stamp-and-sweep sync (`p24:sync-locations`) can prune provinces/cities P24 no
 * longer returns, the same way it already prunes suburbs. No hard deletes (#1).
 *
 * Backfill: existing rows are stamped with `updated_at` (fallback now()) so the
 * first sweep only removes rows the next full P24 walk does NOT re-stamp.
 */
return new class extends Migration {
    private array $tables = ['p24_provinces', 'p24_cities'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'p24_verified_at')) {
                    $t->timestamp('p24_verified_at')->nullable()->after('name')->index();
                }
                if (!Schema::hasColumn($table, 'deleted_at')) {
                    $t->softDeletes();
                }
            });

            DB::table($table)
                ->whereNull('p24_verified_at')
                ->whereNull('deleted_at')
                ->update(['p24_verified_at' => DB::raw('COALESCE(updated_at, NOW())')]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'p24_verified_at')) {
                    $t->dropColumn('p24_verified_at');
                }
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $t->dropSoftDeletes();
                }
            });
        }
    }
};
