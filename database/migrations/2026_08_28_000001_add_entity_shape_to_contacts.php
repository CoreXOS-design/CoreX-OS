<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-24 — the wording library (representative_wording_templates)
 * needs to know WHICH entity shape a Contact is (company / close corporation /
 * trust / deceased estate) to auto-select the right template. contact_kind
 * only distinguishes natural_person/entity; this is the finer classification
 * deliberately left out of the original contact-entity-type spec ("CoreX does
 * not need a finer legal taxonomy at the Contact level") — that call stands
 * for FICA/dedup/property-linking, but the wording library specifically needs
 * it. Additive, nullable, no backfill: existing entity contacts render exactly
 * as they did before (falls through to the existing EsignRecipientPreset path,
 * unaffected by this column being empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('entity_shape', 30)->nullable()->after('entity_reg_no');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('entity_shape');
        });
    }
};
