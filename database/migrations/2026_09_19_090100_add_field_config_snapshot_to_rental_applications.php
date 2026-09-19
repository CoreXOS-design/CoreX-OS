<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md §3 — historical integrity.
 * CoreX is a no-delete system, FICA requires five years' retention after
 * the relationship ends, and a configuration change must never rewrite,
 * hide, or orphan an application already captured.
 *
 * `field_config_snapshot` is written ONCE, at submission (never at
 * creation, never on every autosave) — the fully-resolved field list at
 * that moment: every field's key, label, help text, shown/required state,
 * and order. Not a pointer to "config version N" — the literal resolved
 * values, so the record survives even if the settings row is later
 * restructured. NULL for a draft (still in progress, correctly tracks
 * whatever the agency currently has configured); set forever once
 * submitted. See RentalApplication::snapshotFieldConfig()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->json('field_config_snapshot')->nullable()->after('approved_subject_to_fica_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('field_config_snapshot');
        });
    }
};
