<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fix 2026-10-18 (B-M1). 2026_10_18_000100_add_fica_request_settings_to_agencies added
 * agencies.fica_link_days / agencies.fica_expiring_after_months (Quick Links Wave 1) and ran on the live server.
 * The release replaced them with agencies.fica_link_expiry_days / agencies.fica_current_months
 * (2026_10_18_000700, read by App\Services\Compliance\FicaWindows). Nothing in code reads the old columns any more,
 * so an agency that had changed its FICA link validity or "expiring after" months would silently drop back to
 * 14 days / 11 months.
 *
 * This copies the old value across, once, only where it is a real customisation: the old value differs from the
 * code default (14 / 11), is inside the range FicaWindows accepts, and the new column is still NULL. Never
 * overwrites a value already set on the new column. Plain DB::table(), no models. The old columns are left in place.
 */
return new class extends Migration
{
    /** old column => [new column, code default, min, max] — defaults/ranges mirror FicaWindows::WINDOWS. */
    private const COPIES = [
        'fica_link_days' => ['fica_link_expiry_days', 14, 1, 90],
        'fica_expiring_after_months' => ['fica_current_months', 11, 1, 60],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('agencies')) {
            return;
        }

        foreach (self::COPIES as $old => [$new, $default, $min, $max]) {
            if (! Schema::hasColumn('agencies', $old) || ! Schema::hasColumn('agencies', $new)) {
                continue;
            }

            DB::table('agencies')
                ->whereNull($new)
                ->whereNotNull($old)
                ->where($old, '!=', $default)
                ->whereBetween($old, [$min, $max])
                ->update([$new => DB::raw($old)]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the copied values are real agency settings and cannot be told apart from ones
        // saved on the new columns afterwards.
    }
};
