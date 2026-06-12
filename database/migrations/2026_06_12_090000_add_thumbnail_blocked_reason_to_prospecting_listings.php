<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-22 item 2 (competitor-branding leak) — content-detection block flag.
 *
 * Adds prospecting_listings.thumbnail_blocked_reason: a persisted, auditable
 * marker recording that a stored thumbnail is NOT a genuine property photo and
 * must never render on a seller-facing surface. Set by ListingImageValidator's
 * content inspection (OCR brand-text match or flat-graphic signal) at download
 * time (DownloadListingThumbnail) and by the prospecting:rescan-thumbnail-brands
 * backfill for historic rows.
 *
 * Why a persisted column (not a render-time recompute): the leak root cause was
 * that the render gate only saw the stored file PATH and the source URL — a
 * RE/MAX card downloaded under a neutral filename (pp_PP-T5391969.jpg) with a
 * null source_url passed every substring check because the brand lives in the
 * IMAGE PIXELS, not the path. Content inspection (OCR + colour entropy) is too
 * expensive to run per-card on every presentation render, so it runs once at
 * ingress and the verdict is cached here. The render gate then reads one column.
 *
 * Values are human-readable provenance strings, e.g. 'brand:remax', 'graphic'.
 * Nullable — null means "not blocked / never inspected"; the column is purely
 * additive and the render gate treats null as the conservative "show if the
 * other gates pass" default.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->string('thumbnail_blocked_reason')->nullable()->after('thumbnail_source_url')
                ->comment('Why this thumbnail is blocked from seller surfaces (e.g. brand:remax, graphic). Set by ListingImageValidator content inspection. Null = not blocked (AT-22 item 2).');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropColumn('thumbnail_blocked_reason');
        });
    }
};
