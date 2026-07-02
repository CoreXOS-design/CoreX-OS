<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-148 — WhatsApp voice-note (and any WAHA server-session) media capture.
 *
 * The Chrome-extension transport delivered media inline as base64, so an
 * attachment always had bytes → a storage_path, on the spot. The WAHA
 * server-session transport (AT-138/143) delivers media as a URL to fetch from
 * WAHA. That fetch can fail (WAHA media-download off, transient network) — and
 * the robustness rule is that a bodyless media message MUST still archive, with
 * the media marked pending for retry, never dropped.
 *
 * So attachments gain a lifecycle:
 *   media_status = 'stored'  → bytes are on the volume at storage_path
 *                  'pending' → download not yet done / failed; remote_ref holds
 *                              the WAHA media.url to (re)fetch; storage_path NULL
 *                  'failed'  → reserved for a permanent give-up state
 * and storage_path becomes nullable (a pending row has no file yet).
 * remote_ref keeps the WAHA URL; duration_seconds carries voice-note length.
 * Additive + nullable — no data rewrite, existing rows read as 'stored'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table) {
            // A pending (not-yet-downloaded) attachment has no stored file yet.
            $table->string('storage_path', 1024)->nullable()->change();

            $table->string('media_status', 16)->default('stored')->after('storage_path');
            $table->string('remote_ref', 1024)->nullable()->after('media_status');
            $table->unsignedInteger('duration_seconds')->nullable()->after('remote_ref');

            $table->index('media_status', 'comm_att_media_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table) {
            $table->dropIndex('comm_att_media_status_idx');
            $table->dropColumn(['media_status', 'remote_ref', 'duration_seconds']);
            // Restore NOT NULL. Backfill any lingering NULLs first so the change
            // cannot fail on legacy pending rows.
            $table->string('storage_path', 1024)->nullable(false)->default('')->change();
        });
    }
};
