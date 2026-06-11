<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-22 item 7 — thumbnail rehydration support.
 *
 * Adds prospecting_listings.thumbnail_source_url: the original portal image
 * URL the thumbnail was (or should be) downloaded from. Persisting it means
 * the `prospecting:rehydrate-thumbnails` backfill can re-fetch a missing
 * thumbnail WITHOUT requiring a fresh portal capture.
 *
 * Why this is needed: Laravel 11 moved the `local` disk root to
 * storage/app/private, orphaning ~4032 rows that have thumbnail_path set but
 * zero files on disk. The download job also only ran on the create branch,
 * so a listing first seen without a thumbnail_url never retried. Storing the
 * source URL closes both gaps — the rehydrate command re-dispatches the
 * download from this column.
 *
 * Nullable string — historic rows have no source URL until the next capture
 * (or a rehydrate that derives one) populates it; the rehydrate command skips
 * rows where it is null, so the column is purely additive and safe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->string('thumbnail_source_url')->nullable()->after('thumbnail_path')
                ->comment('Original portal image URL the thumbnail was downloaded from — enables prospecting:rehydrate-thumbnails to re-fetch without a re-capture (AT-22 item 7).');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropColumn('thumbnail_source_url');
        });
    }
};
