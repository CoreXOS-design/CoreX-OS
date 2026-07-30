<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-350 — mark a sold record as somebody else's sale.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §4.4
 *
 * A 3rd-party sale IS a valid CMA comp — the price is real market data no matter
 * who earned the commission — so it belongs in property_sold_records. What it
 * must never be is an HFC sale. This flag is the exclusion boundary for anything
 * that reports on our own performance.
 *
 * Why a boolean and not a new `source` enum value: `source` records HOW the
 * record reached CoreX (manual / tva_api / p24_capture / …). WHO sold it is an
 * orthogonal fact — a 3rd-party sale can arrive manually today and via a deeds
 * office feed tomorrow. Folding the two into one enum would make
 * "all 3rd-party sales" un-queryable the moment a second ingress path exists.
 *
 * Defaults to 0, so every existing row keeps its present meaning: ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_sold_records', function (Blueprint $table) {
            $table->boolean('sold_by_third_party')->default(false)->after('source_reference');
            $table->string('sold_by_agency', 200)->nullable()->after('sold_by_third_party');

            $table->index(['agency_id', 'sold_by_third_party'], 'psr_agency_third_party_idx');
        });
    }

    public function down(): void
    {
        Schema::table('property_sold_records', function (Blueprint $table) {
            $table->dropIndex('psr_agency_third_party_idx');
            $table->dropColumn(['sold_by_third_party', 'sold_by_agency']);
        });
    }
};
