<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, QA1 finding (item 2 follow-up, 2026-09-10): "the property link
 * can currently be changed or cleared at any point — including after
 * approval, after the outcome email has already gone out referencing that
 * property." Reproduced directly: an agent could swap the linked property
 * while an authoriser was actively deciding against it (status
 * under_assessment), or after approval once the applicant already has an
 * email naming the old one.
 *
 * "Any threshold, window or business rule is an agency-configurable
 * setting with a sensible default, never hardcoded" (standing rule).
 * Nullable so "never configured" (use the system default) is
 * distinguishable from "explicitly turned off" — same style as this
 * table's sibling columns (reopen_link_expiry_days).
 *
 * Default is enforced in code (RentalApplicationQualifyingSetting::
 * DEFAULT_LOCK_PROPERTY_AFTER_SUBMISSION = true), not here — this column
 * only ever stores an agency's explicit override away from that default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('lock_property_after_submission')->nullable()->after('reopen_link_expiry_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('lock_property_after_submission');
        });
    }
};
