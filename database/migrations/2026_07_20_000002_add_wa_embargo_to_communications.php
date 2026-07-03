<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-168 Part B — consent embargo (store, don't discard).
 *
 * Messages captured while agent capture-consent is PENDING used to discard the
 * body at ingestion (body_text null + raw redacted), so a later opt-in could
 * never backfill and the blank was permanent. The fix stores the body EMBARGOED
 * (body_status='embargoed', full body in the encrypted-at-rest raw, never
 * displayed) so it can be released instantly on approval — or genuinely purged
 * after a retention window if consent is refused or never granted.
 *
 * Two schema needs:
 *  - `agencies.wa_embargo_retention_days` — the agency-configurable window after
 *    which a still-embargoed (un-consented) body is purged (POPIA — the body
 *    content is genuinely removed; the FICA envelope stays). Default 30 days.
 *  - an index on `communications.body_status` so the daily purge sweep and the
 *    release/recovery scans stay cheap on a growing table.
 *
 * body_status itself needs no migration — 'embargoed' / 'embargo_purged' are new
 * values of the existing nullable string column (AT-135).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('wa_embargo_retention_days')->default(30)->after('wa_history_backfill');
        });

        Schema::table('communications', function (Blueprint $table) {
            $table->index('body_status', 'comm_body_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('wa_embargo_retention_days');
        });
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex('comm_body_status_idx');
        });
    }
};
