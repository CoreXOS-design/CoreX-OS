<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-command-centre.md §18 / compliance — the FICA time windows that were literals in code become per-agency settings.
 * Nullable columns: NULL means "the platform default" (14 days / 11 months / 24 months / 60 days — exactly the old literals), read by
 * App\Services\Compliance\FicaWindows. No data is written, so behaviour at the defaults is unchanged.
 */
return new class extends Migration
{
    private const COLUMNS = ['fica_link_expiry_days', 'fica_current_months', 'fica_validity_months', 'fica_expiring_soon_days'];

    public function up(): void
    {
        if (! Schema::hasTable('agencies')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('agencies', $column)) {
                    $table->unsignedSmallInteger($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agencies')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('agencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
