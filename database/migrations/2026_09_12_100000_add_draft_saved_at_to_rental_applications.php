<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-12 — applicant-side autosave. Johan: "a member of the
 * public part-way through a rental application... losing everything they
 * have typed is a defect on a public form." No new data model — every field
 * the applicant types is already a column on THIS row
 * (RentalApplication::fieldValidationRules()), so autosave just fills and
 * saves the same row the applicant is already working on.
 *
 * This ONE column is purely a UX signal, not a data field: when the
 * applicant returns to the link (hard refresh, closed tab, came back later),
 * the public show() view uses it to tell "there is a restored draft" from
 * "this is the first time this link has been opened" and to show when it was
 * last saved. Deliberately NOT reused from `updated_at` (touched by agent
 * edits, reopens, and other non-applicant writes too — an unreliable signal
 * for "the applicant's own autosave fired") or inferred purely from `status`
 * (in_progress already flips on the first autosave, per this same change,
 * but carries no timestamp for "last saved 2 minutes ago").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('draft_saved_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('draft_saved_at');
        });
    }
};
