<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured address matching, step 2 (.ai/specs/structured-address-matching.md §3, §9).
 *
 *  - suburb_aliases: GLOBAL reference data (no agency_id) — "Leisure Bay" = "Three Hills" style
 *    names for the same physical area. Moves the alias list out of a KZN-only config file so a
 *    Cape Town agency's suburbs are data, not code. Seeded here from config/property-suburb-aliases.php
 *    (evidence-seeded groups only) and kept in step by SuburbAliasSeeder (a SyncableReferenceSeeder, so it
 *    travels with `deploy:sync-reference-data`).
 *  - address_match_settings: per-agency strictness thresholds for the address scorer. NO row = the
 *    defaults in App\Models\AddressMatchSetting. Admin-only; deliberately not in the Setup Wizard.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('suburb_aliases')) {
            Schema::create('suburb_aliases', function (Blueprint $t) {
                $t->id();
                $t->string('alias_normalised', 150)->unique();
                $t->string('canonical_normalised', 150)->index();
                $t->unsignedBigInteger('p24_suburb_id')->nullable()->index();
                $t->string('note', 255)->nullable();
                $t->string('source', 40)->default('config');
                $t->timestamps();
            });

            // Fixed first-time backfill from the evidence-seeded config groups (the first entry of a
            // group is the canonical form). SuburbAliasSeeder keeps this in step on every deploy.
            $now = now();
            foreach ((array) config('property-suburb-aliases.groups', []) as $group) {
                $group = array_values((array) $group);
                if (count($group) < 2) {
                    continue;
                }
                $canonical = (string) $group[0];
                foreach ($group as $alias) {
                    DB::table('suburb_aliases')->updateOrInsert(
                        ['alias_normalised' => (string) $alias],
                        ['canonical_normalised' => $canonical, 'source' => 'config', 'created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        }

        if (! Schema::hasTable('address_match_settings')) {
            Schema::create('address_match_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->unique();
                $t->boolean('rule_erf_exact')->default(true);
                $t->boolean('rule_scheme_exact')->default(true);
                $t->boolean('rule_street_exact')->default(true);
                $t->unsignedTinyInteger('possible_min_agreeing_columns')->default(2);
                $t->string('neighbour_suburb_credit', 20)->default('possible');
                $t->unsignedSmallInteger('gps_radius_m')->default(25);
                $t->string('unit_missing_on_one_side', 20)->default('possible');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Additive-only; a rollback never drops reference data or an agency's saved thresholds.
    }
};
