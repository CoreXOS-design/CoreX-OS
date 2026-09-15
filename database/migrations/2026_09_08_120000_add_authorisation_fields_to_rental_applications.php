<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — authoriser flow. Johan, verbatim: "the auth is again a system
 * setting like co and ro which an agency can set up. and we should allow
 * multi select like co and ro from users." Mirrors
 * whistleblow_approver_user_ids exactly (same shape: a JSON array of user
 * ids on `agencies`, not a dated appointment-history table like
 * fica_officer_appointments — there is no legal appointment-letter
 * requirement here, just "who currently may authorise").
 *
 * submitted_for_approval_at on rental_applications is the agent's "handed
 * off to the authoriser" marker — deliberately NOT a new status value
 * (agreed with cc4: one status model, not two). The application stays
 * `under_assessment`; this timestamp being set is what distinguishes
 * "agent still working" from "awaiting the authoriser's decision."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('rental_application_authoriser_user_ids')->nullable()->after('whistleblow_approver_user_ids');
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('submitted_for_approval_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('rental_application_authoriser_user_ids');
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('submitted_for_approval_at');
        });
    }
};
