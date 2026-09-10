<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — Johan changed the flow: approval no longer auto-emails the
 * applicant. status stays 'approved' (the authoriser's credit decision is
 * final and unambiguous); this column answers the separate, agent-side
 * question of whether the approval email has actually gone out yet.
 * NULL = approved, agent hasn't sent it. Non-null = the agent has sent it.
 * Spec: .ai/specs/rental-applications.md — "agent sends, not auto-send".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('applicant_notified_at')->nullable()->after('approved_rental_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('applicant_notified_at');
        });
    }
};
