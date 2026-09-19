<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md — Johan, 2026-09-20:
 * generation-show.blade.php is the literal "historical record" screen (a
 * specific past submission round, after a reopen/resubmit) and needs its
 * OWN frozen field config, not `rental_applications.field_config_snapshot`
 * — that column is overwritten on every resubmit, so it only ever holds
 * config as of the LATEST round. Without a per-generation copy, a config
 * change between round 1 and round 2 would bleed backward into round 1's
 * display the next time someone opened it.
 *
 * Deliberately NOT part of RentalApplicationGeneration's hash chain
 * (content_hash/prev_hash cover `snapshot_json` only) — the hash exists to
 * make an applicant's ANSWERS tamper-evident; this is agency-side display
 * metadata, not applicant-supplied content, and folding it in would mean
 * a routine settings change breaks chain verification on every past
 * generation, which is not what the chain is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_generations', function (Blueprint $table) {
            $table->json('field_config_snapshot')->nullable()->after('snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_generations', function (Blueprint $table) {
            $table->dropColumn('field_config_snapshot');
        });
    }
};
