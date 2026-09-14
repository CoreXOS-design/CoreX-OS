<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-13 (round 5) — Johan: an applicant could submit a
 * completely blank rental application; the only real floor was two
 * non-empty signature strings. His ruling, twice confirmed: every field on
 * the applicant form gets its own agency compulsory tickbox, no locked set
 * — "we provide the system, they set it up the way they want to use it."
 *
 * `required_field_keys` — nullable JSON array of registry keys
 * (RentalApplication::submissionFieldRegistry()) the agency has marked
 * compulsory. NULL means "never configured this section," in which case
 * RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS applies —
 * same isConfigured-null-vs-set honesty as the document checklist elsewhere
 * on this model. An explicitly saved EMPTY array is a real, deliberate
 * agency choice ("nothing is compulsory") and must not be confused with
 * "never touched this screen."
 *
 * `marital_status_options` — nullable JSON array of
 * ['label' => string, 'implies_spouse' => bool]. Ruling 1, same round:
 * marital_status converts from free text (no fixed vocabulary, so the
 * spouse-fields condition could never reliably fire) to a real select, with
 * the option list itself agency-configurable — same pattern as everything
 * else on this model, sensible default shipped. `implies_spouse` is what
 * drives whether the spouse fields' conditional-required group fires for a
 * given selection; only "Married" defaults true — "Living together / life
 * partner" is not a legal marriage, so the spouse-ID/name fields (asking
 * for a legally recognised spouse) don't auto-apply. Flagged here rather
 * than silently assumed: this specific line is a judgement call, correctable
 * by changing one row's flag if Johan disagrees, not a structural decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->json('required_field_keys')->nullable()->after('return_gate_attempt_window_minutes');
            $table->json('marital_status_options')->nullable()->after('required_field_keys');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['required_field_keys', 'marital_status_options']);
        });
    }
};
