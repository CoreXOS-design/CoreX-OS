<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suburb report (Johan, 2026-08-24) needs to join an uploaded CMA
 * report's own "SHELLY BEACH / RAY NKONYENI" text to our own p24_suburb_id
 * records. Nothing in the schema does this today — checked directly:
 * region_aliases (municipality names only, no suburb column),
 * geocoding_cache.municipality_name (confirmed wrong — returns "Margate"
 * for Shelly Beach, not "Ray Nkonyeni"; explicitly ruled out for this use),
 * town_suburbs (agency-defined town grouping, not the legal municipality).
 *
 * GLOBAL, not agency-scoped — a suburb's municipality is a geographic fact,
 * independent of which agency is asking. A new agency in a different region
 * gets its own suburbs auto-added here (row created with municipality=null,
 * confidence='needs_review') by the same seeder on its next deploy run — no
 * code change needed to onboard it. Only CONFIRMING an unknown municipality
 * needs a human (or a future admin UI, not built here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suburb_municipalities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('p24_suburb_id')->unique();
            // Denormalised suburb name, kept in sync with p24_suburbs.name by
            // the seeder — lets the suburb-report builder LIKE-match an
            // uploaded report's own suburb text without a join on every
            // lookup, and survives if a p24_suburbs row is ever renamed
            // (this column records what it was called when last seeded).
            $table->string('suburb_name');
            // The true legal municipality — "Ray Nkonyeni", "eThekwini",
            // "Umdoni", "KwaDukuza", etc. NULL means genuinely unconfirmed —
            // never a guessed default. A null here must exclude the suburb
            // from municipality-comparison figures in the suburb report,
            // not silently attribute it to whichever municipality happens
            // to be nearby.
            $table->string('municipality')->nullable();
            // 'confirmed' — high-confidence, defensible source (documented
            //   SA municipal demarcation, or later an agent's own edit).
            // 'needs_review' — suburb is real and in use, but its
            //   municipality is not yet established; must not be treated as
            //   confirmed by any downstream consumer.
            $table->enum('confidence', ['confirmed', 'needs_review'])->default('needs_review');
            // Where the municipality value (when set) came from — audit
            // trail, so a future correction knows what it's overriding.
            $table->string('source', 100)->nullable();
            $table->timestamps();

            $table->foreign('p24_suburb_id')->references('id')->on('p24_suburbs')->cascadeOnDelete();
            $table->index('municipality');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suburb_municipalities');
    }
};
