<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMA map — Phase 1 schema.
 *
 * 1. prospecting_listings: latitude + longitude (decimal 10,7 — same
 *    precision as properties.latitude / market_report_comp_rows.latitude
 *    so resolver output round-trips identically across the three
 *    tables). Indexed for the future "competition within radius" query.
 *
 *    Why a real column, not on-the-fly: every Active Competition match
 *    needs GPS to plot on both the review map and the PDF static-map
 *    image. Resolving 200+ rows per render via AddressResolverService
 *    works but hammers the Google quota; persisting once + reusing is
 *    the deliberate choice. CompetitorStockMatchService will resolve
 *    on first encounter (eager hook) and the GeocodingBackfillCommand
 *    --type=competition will clear historic rows.
 *
 * 2. agencies.presentations_map_provider: per-agency PDF map renderer
 *    selection. 'svg_radial' (default, self-contained polar diagram)
 *    or 'static_image' (Google Static Maps PNG embedded base64). The
 *    static_image path requires GOOGLE_GEOCODING_API_KEY (reused as
 *    the static maps key — same Google Cloud project). When no key is
 *    configured the renderer falls back to svg_radial regardless of
 *    the setting, so a misconfigured agency never gets a broken PDF.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->decimal('latitude',  10, 7)->nullable()->after('suburb')
                ->comment('Resolved by AddressResolverService — building-level when street parts present, suburb_centroid as last resort. Indexed for radius queries.');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->index(['latitude', 'longitude'], 'idx_prospecting_listings_geo');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->string('presentations_map_provider', 32)
                ->default('svg_radial')
                ->after('competitor_stock_default_display_count')
                ->comment('Presentation PDF map renderer: svg_radial (polar diagram, self-contained) or static_image (Google Static Maps PNG, requires API key).');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('idx_prospecting_listings_geo');
            $table->dropColumn(['latitude', 'longitude']);
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('presentations_map_provider');
        });
    }
};
