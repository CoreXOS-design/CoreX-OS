<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, belt step A1 (road-to-sold.md 23.5 / 23.15 / 23.17): a MIC claim IS station 2 of the prospect record.
 *
 *   prospecting_claims  rts_prospect_id (the belt record this claim is), hold_until (the claim hold, in working days), hold_extended_at,
 *                       hold_extensions (how many extensions the agent has taken)
 *   rts_settings        claim_hold_days 30, claim_warn_days 5, claim_extension_days 30, claim_extensions_allowed 1
 *
 * Additive only. Every column is nullable or defaulted; a claim with no hold_until keeps the old rules (the 48-hour rule), so the code is
 * safe to run before and after this migration. Guarded with hasColumn so a re-run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $t) {
            if (! Schema::hasColumn('prospecting_claims', 'rts_prospect_id')) {
                $t->unsignedBigInteger('rts_prospect_id')->nullable()->after('property_id')->index('prospecting_claims_rts_prospect_idx');
            }
            if (! Schema::hasColumn('prospecting_claims', 'hold_until')) {
                $t->date('hold_until')->nullable()->after('claimed_at');
            }
            if (! Schema::hasColumn('prospecting_claims', 'hold_extended_at')) {
                $t->dateTime('hold_extended_at')->nullable()->after('hold_until');
            }
            if (! Schema::hasColumn('prospecting_claims', 'hold_extensions')) {
                $t->unsignedTinyInteger('hold_extensions')->default(0)->after('hold_extended_at');
            }
        });

        Schema::table('rts_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('rts_settings', 'claim_hold_days')) {
                $t->unsignedSmallInteger('claim_hold_days')->default(30);
            }
            if (! Schema::hasColumn('rts_settings', 'claim_warn_days')) {
                $t->unsignedSmallInteger('claim_warn_days')->default(5);
            }
            if (! Schema::hasColumn('rts_settings', 'claim_extension_days')) {
                $t->unsignedSmallInteger('claim_extension_days')->default(30);
            }
            if (! Schema::hasColumn('rts_settings', 'claim_extensions_allowed')) {
                $t->unsignedTinyInteger('claim_extensions_allowed')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $t) {
            if (Schema::hasColumn('prospecting_claims', 'rts_prospect_id')) {
                $t->dropIndex('prospecting_claims_rts_prospect_idx');
                $t->dropColumn('rts_prospect_id');
            }
            foreach (['hold_until', 'hold_extended_at', 'hold_extensions'] as $c) {
                if (Schema::hasColumn('prospecting_claims', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('rts_settings', function (Blueprint $t) {
            foreach (['claim_hold_days', 'claim_warn_days', 'claim_extension_days', 'claim_extensions_allowed'] as $c) {
                if (Schema::hasColumn('rts_settings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
