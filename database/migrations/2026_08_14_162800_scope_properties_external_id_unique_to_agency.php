<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * properties.external_id carried a plain global-unique index. external_id
 * doubles as the P24 listing number for P24-sourced stock, and P24 listing
 * numbers are only unique within P24's own catalogue — two different HFC
 * agencies (e.g. a real agency and Demo Agency Test, agency_id 17) can both
 * be handed the same P24 listing (dual mandate, or overlapping test/sandbox
 * data). A global unique index makes the second agency's import fail on
 * every overlapping row with a 1062 duplicate-entry error — observed live
 * on 2026-08-14 during a P24 import test on agency 17, which collided with
 * agency_id=1's pre-existing stock and confirmed 0 of 4,753 rows.
 *
 * Fix: scope the uniqueness to (agency_id, external_id) — the same pattern
 * already used for communications.external_id and communication_pending's
 * agency-scoped external_id unique keys. Each agency gets its own namespace;
 * organically-created (non-P24) properties still get a fresh UUID per the
 * Property::creating hook, so they remain effectively unique regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropUnique('properties_external_id_unique');
            $table->unique(['agency_id', 'external_id'], 'properties_agency_ext_uq');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropUnique('properties_agency_ext_uq');
            $table->unique('external_id', 'properties_external_id_unique');
        });
    }
};
