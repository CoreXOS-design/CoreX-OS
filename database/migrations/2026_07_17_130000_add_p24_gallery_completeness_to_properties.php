<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery-completeness tracking for the P24 listings importer.
 *
 * The first ~4000-property import silently lost ~11% of offered images
 * (2,549 of 23,124): DownloadP24RowImagesJob ran tries=1, swallowed CDN
 * rate-limit failures into a log line, and wrote whatever it managed to
 * store as "done" — so a gallery that got 1 of 25 images reported success
 * and failed_jobs stayed empty. There was no column anywhere that could
 * even express "this gallery is short", so nothing could retry it, alert on
 * it, or prove the import was complete.
 *
 * These columns make gallery completeness a first-class, queryable fact:
 *   expected vs stored, a status, and an INBOUND signature so a re-import of
 *   an unchanged agency refetches nothing (the existing
 *   Property::p24ImageSignature() is OUTBOUND-only — what we last PUSHED to
 *   P24 — and must not be reused for what we last PULLED from it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // How many images P24 offered for this listing (count of image_urls_json).
            $table->unsignedInteger('gallery_expected_count')->default(0)->after('images_json');
            // How many are actually on disk and referenced in the gallery json.
            $table->unsignedInteger('gallery_stored_count')->default(0)->after('gallery_expected_count');
            // pending  — confirmed, images not yet (fully) fetched
            // complete — stored == expected (the only "safe to stop" state)
            // incomplete — retries exhausted while still short; needs attention
            // failed   — the download job errored terminally
            $table->string('gallery_import_status', 20)->default('pending')->after('gallery_stored_count');
            // md5(image_urls_json) at confirm time — the INBOUND mirror of
            // p24_image_signature. Lets a re-import short-circuit when the P24
            // gallery URL set is unchanged AND the local gallery is complete.
            $table->string('p24_source_image_signature', 64)->nullable()->after('gallery_import_status');

            // The reconciliation query — "which imports are still short" — filters
            // on status; index it so the owner dashboard / audit stays cheap.
            $table->index('gallery_import_status', 'properties_gallery_import_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_gallery_import_status_index');
            $table->dropColumn([
                'gallery_expected_count',
                'gallery_stored_count',
                'gallery_import_status',
                'p24_source_image_signature',
            ]);
        });
    }
};
