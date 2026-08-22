<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add communication_links.source_attachment_id (CX-114, 2026-08-22) — nullable,
 * only ever set on link_method=attachment rows.
 *
 * Fixes a real dedup bug found during verification: isDuplicateOnDeal() originally
 * detected duplicates by "did THIS COMMUNICATION already produce a document on this
 * deal, and does it have ANY attachment with this content_hash" — but an email with
 * TWO DIFFERENT attachments (e.g. one PDF + one signature image, or two genuinely
 * different PDFs) would, after the first attachment filed, see EVERY subsequent
 * attachment on that SAME email match that check trivially (a communication that
 * "produced a document" always "has an attachment" matching its own just-filed
 * content_hash — that's just the first attachment matching itself). The second,
 * third, etc. attachment on a multi-attachment email would be silently skipped as a
 * false-positive "duplicate", even though its content had never been filed anywhere.
 *
 * The fix needs to know exactly WHICH attachment produced WHICH document, not just
 * which communication. documents has no content_hash column (checked — would be a
 * larger, shared-table schema change), so the pointer lives on the provenance link
 * instead, which already exists for exactly this purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_links', function (Blueprint $table) {
            $table->foreignId('source_attachment_id')
                ->nullable()
                ->after('linkable_id')
                ->constrained('communication_attachments', 'id', 'comm_link_src_att_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communication_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_attachment_id');
        });
    }
};
