<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-24: "frozen at generation... same rule as the e-sign burn."
 * A generated suburb report is an immutable record from the moment it's
 * created — a seller opening it in six months sees exactly what they were
 * shown, never a live-recomputed figure. Generating again creates a NEW row
 * (a new version), never updates an existing one — mirrors how
 * presentation_snapshots/presentation_versions already work in this app,
 * just for a per-suburb report rather than a per-property presentation
 * (wrong FK shape to reuse directly — presentation_snapshots is keyed to
 * presentation_id).
 *
 * Three separate vintage stamps (rule 2) — never averaged into one
 * "generated_at" that hides which source is actually stale. current_year is
 * itself snapshotted so "is this year partial" stays correct even if the
 * report is viewed in a later calendar year than it was generated in
 * (rule 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suburb_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('p24_suburb_id');
            // Denormalised, frozen at generation — a stable historical
            // record even if the agency later renames itself or the
            // suburb_municipalities row is later corrected.
            $table->string('suburb_name');
            $table->string('municipality')->nullable();
            $table->boolean('municipality_confirmed');
            $table->string('agency_name');

            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            // The one true freeze moment — everything below is "as it stood
            // right now", never recomputed after this row is written.
            $table->timestamp('generated_at');
            // The calendar year at generation time — frozen, so a report
            // viewed in a later year doesn't silently start calling last
            // year's now-complete data "partial".
            $table->unsignedSmallInteger('current_year_at_generation');

            // Layer A — parsed CMA suburb stats. Own vintage: the most
            // recent source market_reports.report_date feeding this layer,
            // NULL when no CMA report was on file at generation time.
            $table->json('layer_a_json');
            $table->date('layer_a_source_vintage')->nullable();

            // Layer B — CoreX's own stock/market data. Own vintage: the
            // moment this layer was actually queried (rule 2 — never
            // blended with Layer A's or C's own freshness).
            $table->json('layer_b_json');
            $table->timestamp('layer_b_as_at');

            // Layer C — live buyer demand. Own vintage — real-time by
            // construction, but still stamped explicitly, not implied.
            $table->json('layer_c_json');
            $table->timestamp('layer_c_as_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'p24_suburb_id']);
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suburb_reports');
    }
};
