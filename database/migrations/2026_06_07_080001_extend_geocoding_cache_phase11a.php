<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11a B — extend the existing Phase 3f geocoding_cache table with TTL +
 * hit tracking, and add geocode_needs_review on tracked_properties.
 *
 * The brief asks for a fresh `geocode_cache` table; the codebase already
 * has `geocoding_cache` from Phase 3f, populated with 54 rows. We extend in
 * place rather than duplicate — see pre-flight report.
 *
 * New columns on geocoding_cache:
 *   hit_count       — incremented every cache HIT (read)
 *   last_hit_at     — timestamp of most recent HIT
 *   expires_at      — TTL boundary; purgeExpired() targets rows past this
 *   google_location_type — raw ROOFTOP / RANGE_INTERPOLATED / GEOMETRIC_CENTER / APPROXIMATE
 *                          (parallel to existing `confidence` enum which uses
 *                          our normalised exact/street/suburb/failed values)
 *
 * New column on tracked_properties:
 *   geocode_needs_review — true when geocoded with confidence below street
 *                          level (GEOMETRIC_CENTER / APPROXIMATE / suburb)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('geocoding_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('geocoding_cache', 'hit_count')) {
                $table->unsignedInteger('hit_count')->default(0)->after('failure_reason');
            }
            if (!Schema::hasColumn('geocoding_cache', 'last_hit_at')) {
                $table->timestamp('last_hit_at')->nullable()->after('hit_count');
            }
            if (!Schema::hasColumn('geocoding_cache', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('last_hit_at');
            }
            if (!Schema::hasColumn('geocoding_cache', 'google_location_type')) {
                $table->string('google_location_type', 30)->nullable()->after('confidence');
            }
        });

        Schema::table('geocoding_cache', function (Blueprint $table) {
            $existing = collect(Schema::getIndexes('geocoding_cache'))->pluck('name')->all();
            if (!in_array('geocoding_cache_expires_idx', $existing, true)) {
                $table->index('expires_at', 'geocoding_cache_expires_idx');
            }
        });

        Schema::table('tracked_properties', function (Blueprint $table) {
            if (!Schema::hasColumn('tracked_properties', 'geocode_needs_review')) {
                $table->boolean('geocode_needs_review')->default(false)->after('geo_confidence');
            }
        });

        // Backfill expires_at on existing rows: 90 days from created_at for
        // success rows, 7 days for failures. This lets purgeExpired() target
        // legacy entries the same way as new entries.
        $successTtl = (int) (config('geo.geocoding.cache_success_ttl_days') ?: 90);
        $failureTtl = (int) (config('geo.geocoding.cache_failure_ttl_days') ?: 7);
        \DB::statement("
            UPDATE geocoding_cache
            SET expires_at = DATE_ADD(created_at, INTERVAL CASE
                WHEN confidence = 'failed' OR latitude IS NULL THEN {$failureTtl}
                ELSE {$successTtl}
            END DAY)
            WHERE expires_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            if (Schema::hasColumn('tracked_properties', 'geocode_needs_review')) {
                $table->dropColumn('geocode_needs_review');
            }
        });

        Schema::table('geocoding_cache', function (Blueprint $table) {
            $existing = collect(Schema::getIndexes('geocoding_cache'))->pluck('name')->all();
            if (in_array('geocoding_cache_expires_idx', $existing, true)) {
                $table->dropIndex('geocoding_cache_expires_idx');
            }
            foreach (['google_location_type', 'expires_at', 'last_hit_at', 'hit_count'] as $col) {
                if (Schema::hasColumn('geocoding_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
