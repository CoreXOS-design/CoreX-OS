<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make performance_settings PER-AGENCY (multi-tenancy — Non-negotiable #7).
 *
 * The table was a flat global key/value store with a UNIQUE on `key`, so a
 * per-agency value was impossible: every agency shared one row. That surfaced as
 * a real defect once the feature registry presented the switchboard toggles
 * (marketing / core-matches / syndication) as per-agency — turning one off for
 * Agency A turned it off for everyone.
 *
 * Fix: add a NULLABLE `agency_id`. A NULL-agency row is the PLATFORM DEFAULT /
 * global fallback; a row with an agency_id is that agency's override. `get()`
 * resolves the agency row first, then falls back to the NULL row (so the ~19
 * genuinely-global keys — vat_rate, per-page counts, company_* — keep their exact
 * behaviour with no code change; they simply never grow a per-agency row).
 *
 * No data migration: existing rows stay agency_id = NULL and remain the shared
 * default, so nothing changes until an agency explicitly overrides a toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('performance_settings', 'agency_id')) {
                $table->foreignId('agency_id')->nullable()->after('id')
                    ->constrained('agencies')->cascadeOnDelete();
            }
        });

        // Swap the global unique('key') for a per-agency composite unique. Wrapped
        // so a re-run (or an environment where the old index was already dropped)
        // does not fail the deploy.
        Schema::table('performance_settings', function (Blueprint $table) {
            try {
                $table->dropUnique('performance_settings_key_unique');
            } catch (\Throwable $e) {
                // index already gone — fine.
            }
        });

        Schema::table('performance_settings', function (Blueprint $table) {
            $table->unique(['agency_id', 'key'], 'performance_settings_agency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('performance_settings', function (Blueprint $table) {
            try {
                $table->dropUnique('performance_settings_agency_key_unique');
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('performance_settings', 'agency_id')) {
                $table->dropConstrainedForeignId('agency_id');
            }
            $table->unique('key', 'performance_settings_key_unique');
        });
    }
};
