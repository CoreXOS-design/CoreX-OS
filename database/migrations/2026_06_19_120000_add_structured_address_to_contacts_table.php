<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-60 — Structured contact address.
 *
 * Contacts previously had a single free-text `address` column. This adds the
 * structured subset that makes sense at contact-capture stage, mirroring the
 * property "Internal Address" modal (the COMPLEX-OR-ESTATE + STREET sections)
 * plus the Property24-backed Province / City / Suburb chain.
 *
 * Deeds-office working fields (erf / stand / zone / district / region) are
 * deliberately NOT mirrored — those are property-working fields, out of scope
 * for contact-stage address capture.
 *
 * The legacy `address` column is intentionally KEPT — and it is a SEPARATE FACT,
 * not a denormalised copy of these columns. `address` is where the person LIVES
 * (their residential address); the structured columns below describe the PROPERTY
 * they own and are being pitched about. A contact may live in Durban and be
 * selling in Uvongo, so the two legitimately differ and nothing syncs one to the
 * other. Compose a display string from the structured columns with
 * Contact::composeStructuredAddress(), which explicitly "does NOT touch the
 * residential `address` field".
 *
 * (AT-266: this docblock previously claimed `address` was "auto-composed from the
 * structured fields on every save (see Contact::syncStructuredAddress +
 * ContactObserver)". No such method has ever existed. The claim caused a live
 * investigation to score 20 contacts as corrupt when they were correct — the
 * comment was the bug. Corrected here rather than left to mislead the next reader.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            // Complex or Estate
            $t->string('unit_number', 50)->nullable()->after('address');
            $t->string('floor_number', 50)->nullable()->after('unit_number');
            $t->string('unit_section_block', 150)->nullable()->after('floor_number');
            $t->string('complex_name', 150)->nullable()->after('unit_section_block');
            // Street
            $t->string('street_number', 50)->nullable()->after('complex_name');
            $t->string('street_name', 200)->nullable()->after('street_number');
            // Denormalised P24 location text (kept in sync by the picker)
            $t->string('suburb', 120)->nullable()->after('street_name');
            $t->string('city', 120)->nullable()->after('suburb');
            $t->string('province', 120)->nullable()->after('city');
            // Property24 location FKs — mirror the property definitions in
            // 2026_05_13_150003_add_p24_location_refs_to_properties.
            $t->foreignId('p24_suburb_id')->nullable()->after('province')
                ->constrained('p24_suburbs')->nullOnDelete();
            $t->foreignId('p24_city_id')->nullable()->after('p24_suburb_id')
                ->constrained('p24_cities')->nullOnDelete();
            $t->foreignId('p24_province_id')->nullable()->after('p24_city_id')
                ->constrained('p24_provinces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropForeign(['p24_suburb_id']);
            $t->dropForeign(['p24_city_id']);
            $t->dropForeign(['p24_province_id']);
            $t->dropColumn([
                'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
                'street_number', 'street_name', 'suburb', 'city', 'province',
                'p24_suburb_id', 'p24_city_id', 'p24_province_id',
            ]);
        });
    }
};
