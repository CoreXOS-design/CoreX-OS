<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-30: "after a cma scrape - look for matches on contacts to
 * flag deeds as well on id numbers." The ID-number match against Contact
 * already happens today (OwnerContactResolver / DeedsCaptureController::
 * resolveOwnerContact()) — TrackedPropertyOwner.contact_id gets set either
 * way, whether that contact was just created from this scrape or already
 * existed. There was no way to tell the two apart afterwards, which is
 * exactly what "flag" needs: an agent looking at the deeds screen must see
 * "we already know this person", not just any contact_id. Set ONLY on the
 * genuine-match branch, never on create — the flag and the real-numbers
 * count (how many owners actually matched an existing contact) both read
 * this same column, so there is one source of truth for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_property_owners', function ($table) {
            $table->timestamp('matched_contact_at')->nullable()->after('contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function ($table) {
            $table->dropColumn('matched_contact_at');
        });
    }
};
