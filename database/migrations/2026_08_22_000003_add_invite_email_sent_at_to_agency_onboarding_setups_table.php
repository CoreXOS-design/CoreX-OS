<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT — Agency admin email-only invite (.ai/specs/agency-admin-rule.md §R1a/§R1b).
 *
 * Tracks whether AgencyOnboardingSetupMail has been sent for this setup. The send
 * moved from "immediately at agency creation" to "on the Admin's first successful
 * login" — this column is the idempotency guard so a re-fired first-login check
 * (or a manual resend from the owner tracking page) never double-sends by accident
 * on the SAME trigger path, while still allowing an explicit resend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_onboarding_setups', function (Blueprint $table) {
            $table->timestamp('invite_email_sent_at')->nullable()->after('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('agency_onboarding_setups', function (Blueprint $table) {
            $table->dropColumn('invite_email_sent_at');
        });
    }
};
