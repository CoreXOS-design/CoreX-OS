<?php

use App\Services\TitleTypeClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keystone — promote title_type from a category-derived runtime lookup
 * into a stored, per-property fact.
 *
 * Before this migration the subject's title_type was derived ONLY from
 * properties.category → property_setting_items.title_type. That breaks
 * for any agency whose portfolio mixes title types under one category
 * (HFC agency 1: 283 of 292 properties have category='residential',
 * spanning houses, sectional units, and vacant land). Per-property
 * property_type carries the granularity already — this column makes it
 * the source of truth.
 *
 * Column behaviour:
 *   - ENUM matching PropertySettingItem::TITLE_* constants.
 *   - NULL allowed so the saving observer + generation gate can refuse
 *     ungraded rows rather than silently mis-classify.
 *   - Indexed for the comp-filter join.
 *
 * Backfill discipline:
 *   - Uses DB::table('properties')->update() — RAW QUERY BUILDER. This
 *     intentionally bypasses PropertyObserver::saving(), which would
 *     otherwise fire 292 audit-diff writes, MatchPropertyJob dispatches,
 *     and P24 syndication calls during the migration.
 *   - Single transaction so a partial backfill never lands.
 *   - Reads the row, classifies via TitleTypeClassifier::forProperty,
 *     writes the result back. Rows the classifier can't decide are left
 *     NULL — the generation gate will reject them at agent click time
 *     with a user-facing message rather than us guessing here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'title_type')) {
                $table->enum('title_type', [
                    'full_title',
                    'sectional_title',
                    'vacant_land',
                    'other',
                ])->nullable()->after('property_type')
                  ->comment('Keystone — derived from property_type by TitleTypeClassifier on every save. Source of truth for comp-filter and review-screen badge.');
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            try { $table->index('title_type', 'properties_title_type_idx'); }
            catch (\Throwable $e) { /* index exists */ }
        });

        // Backfill via raw query builder. The classifier is a leaf service
        // (no Property model side effects) so calling it here is safe.
        $classifier = app(TitleTypeClassifier::class);

        DB::transaction(function () use ($classifier) {
            DB::table('properties')
                ->select(['id', 'property_type', 'category', 'agency_id'])
                ->whereNull('title_type')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($classifier) {
                    foreach ($rows as $r) {
                        $tt = $classifier->fromPropertyType($r->property_type)
                            ?? $classifier->fromCategory(
                                (int) ($r->agency_id ?? 0),
                                $r->category,
                            );
                        if ($tt === null) continue; // leave NULL, gate rejects later
                        DB::table('properties')
                            ->where('id', $r->id)
                            ->update(['title_type' => $tt]);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            try { $table->dropIndex('properties_title_type_idx'); }
            catch (\Throwable $e) { /* missing — fine */ }
            if (Schema::hasColumn('properties', 'title_type')) {
                $table->dropColumn('title_type');
            }
        });
    }
};
