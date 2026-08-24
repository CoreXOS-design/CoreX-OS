<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remove background" — AI segmentation API architecture (ad-manager.md §15.2).
 *
 * Tracks the server-side cutout on the `profile_photo` user_documents row
 * (the same row AgentProfilePhotoService::set() already keeps in lockstep
 * with the normalised file and the legacy agent_photo_path column) — one
 * more field on the SAME record, not a new table, so a photo's state still
 * lives in exactly one place.
 *
 * - bg_removal_status: null (never attempted) | processing | done | failed.
 *   null is the default for the 23 existing rows and for any upload with the
 *   agency toggle off — both cases must fall back to the plain original
 *   photo, never a blank avatar.
 * - bg_removal_cutout_path: the public-disk relative path to the transparent
 *   PNG, stored ALONGSIDE the original (agents/{id}/photo-cutout.png) — the
 *   original file_path column is never overwritten or deleted by this
 *   feature.
 * - bg_removal_driver: which provider actually produced the CURRENT cutout
 *   ('photoroom'|'remove_bg') — an audit trail across a future driver
 *   switch.
 * - bg_removal_processed_at: when the current cutout (or the last failure)
 *   was recorded.
 * - bg_removal_error: the last failure message, cleared on the next
 *   success — surfaced to an admin without reading the queue log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_documents', 'bg_removal_status')) {
            return;
        }

        Schema::table('user_documents', function (Blueprint $table) {
            $table->string('bg_removal_status', 20)->nullable()->after('file_path');
            $table->string('bg_removal_cutout_path', 500)->nullable()->after('bg_removal_status');
            $table->string('bg_removal_driver', 30)->nullable()->after('bg_removal_cutout_path');
            $table->timestamp('bg_removal_processed_at')->nullable()->after('bg_removal_driver');
            $table->text('bg_removal_error')->nullable()->after('bg_removal_processed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_documents', 'bg_removal_status')) {
            return;
        }

        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropColumn([
                'bg_removal_status',
                'bg_removal_cutout_path',
                'bg_removal_driver',
                'bg_removal_processed_at',
                'bg_removal_error',
            ]);
        });
    }
};
