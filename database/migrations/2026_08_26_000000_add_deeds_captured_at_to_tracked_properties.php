<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEEDS BUG 1 fix (2026-08-19) — a deeds capture of a property that ALREADY
 * existed (previously imported as a prospecting/P24 lead, or already
 * deeds-captured) enriched the TrackedProperty but never surfaced on the
 * Deeds Capture screen: capture_kind='deeds_capture' is only ever stamped
 * when the capture CREATES the TrackedProperty (App\Http\Controllers\Api\
 * DeedsCaptureController::ingestOne(), `if ($created && empty($tp->
 * capture_kind))`) — deliberately, so a genuine deeds enrichment of an
 * existing MIC/prospecting lead does NOT rip it out of the Opportunities
 * pipeline. But that also means the capture itself — the EVENT of a deed
 * being scraped and ingested — leaves no record when it lands on a property
 * that isn't classified as a deeds capture. The agent sees "captured" in the
 * extension, then nothing on /corex/deeds-capture; the capture didn't fail,
 * it's just invisible.
 *
 * deeds_captured_at is a separate marker from capture_kind: capture_kind is
 * the PIPELINE CLASSIFICATION (deeds vs prospecting-lead) and controls where
 * a record lives long-term; deeds_captured_at is the EVENT record ("a deeds
 * capture landed on this TrackedProperty at this time") and is stamped on
 * EVERY deeds capture — created or existing — regardless of classification.
 * The Deeds Capture screen surfaces on deeds_captured_at IS NOT NULL instead
 * of (now: in addition to) capture_kind='deeds_capture', so a re-capture of
 * an existing prospecting lead still shows — flagged as already-
 * captured/linked rather than silently enriched-and-hidden — while the
 * lead's own capture_kind, and therefore its place in the MIC Opportunities
 * pipeline, is untouched.
 *
 * Additive only — nullable, no backfill, no data rewritten. A pre-existing
 * genuine deeds_capture row (capture_kind already set) will simply gain this
 * timestamp the next time it's re-captured; nothing about its current
 * display changes retroactively for rows never re-captured after this ships.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->timestamp('deeds_captured_at')->nullable()->index()->after('capture_kind');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropIndex(['deeds_captured_at']);
            $table->dropColumn('deeds_captured_at');
        });
    }
};
