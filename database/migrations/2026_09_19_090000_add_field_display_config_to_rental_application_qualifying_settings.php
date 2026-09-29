<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md — Johan: "we need to ensure
 * that we can customize the rental process for each agency... not change
 * the whole corex." This extends the SAME mechanism `required_field_keys`
 * already proved out (see 2026_09_13_140000, this table) rather than
 * building a parallel one — conductor's explicit build ruling, 2026-09-19,
 * overriding this spec's own earlier draft recommendation of a separate
 * table.
 *
 * All four columns follow the identical Rule-17-safe contract
 * `required_field_keys` already established: nullable, never written on
 * read, NULL means "agency never touched this," a saved (possibly empty)
 * value is a genuine, honoured choice.
 *
 * `hidden_field_keys` — nullable JSON array of registry keys
 * (RentalApplication::submissionFieldRegistry()) the agency has hidden
 * entirely from the applicant form. NULL/absent = nothing hidden (every
 * shipped field shows, exactly today's behaviour).
 *
 * `field_label_overrides` — nullable JSON object, registry key => label
 * string. A key absent from the map falls back to the registry's own
 * shipped label — an agency overrides only what it wants to change, never
 * has to restate the whole set.
 *
 * `field_help_text_overrides` — nullable JSON object, registry key => help
 * text string. Same fallback contract as labels. A field with no shipped
 * hint and no override simply has none — this does not force every field
 * to carry help text.
 *
 * `field_order` — nullable JSON array of registry keys, in the agency's
 * preferred order WITHIN each field's own existing form section. A key
 * absent from the array falls back to its shipped position (see
 * RentalApplication::resolvedFieldConfigFor()'s own docblock for exactly
 * how partial orderings resolve). Deliberately NOT a cross-section
 * reorder — moving a field between sections changes which Alpine
 * conditional group governs it (spouse/landlord/employer), a materially
 * bigger and riskier change than this build attempts; flagged in the spec,
 * not silently assumed away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->json('hidden_field_keys')->nullable()->after('marital_status_options');
            $table->json('field_label_overrides')->nullable()->after('hidden_field_keys');
            $table->json('field_help_text_overrides')->nullable()->after('field_label_overrides');
            $table->json('field_order')->nullable()->after('field_help_text_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['hidden_field_keys', 'field_label_overrides', 'field_help_text_overrides', 'field_order']);
        });
    }
};
