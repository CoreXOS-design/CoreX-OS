<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CX-113 Phase G (Johan, 2026-08-22) — "cant see all the email addresses it was sent
 * from or sent to? who the recipients are etc etc etc." Root cause: participant_identifiers
 * is a flat, deduplicated To+Cc+From set with no role — the ingestion poller parses To
 * and Cc separately from the real headers but discards the split before persisting. This
 * adds the columns to keep it, going forward; existing rows are left null (unrecoverable —
 * the raw .eml the role could otherwise be re-derived from is not reliably present on
 * disk, confirmed by direct sampling before this fix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->json('to_identifiers')->nullable()->after('participant_identifiers');
            $table->json('cc_identifiers')->nullable()->after('to_identifiers');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropColumn(['to_identifiers', 'cc_identifiers']);
        });
    }
};
