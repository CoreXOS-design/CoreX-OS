<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-410d, 2026-09-16 — Johan's ruling: "yes, can become approved subject
 * to fica verification." An authoriser is not blocked from approving an
 * applicant whose FICA is still outstanding — the approval is recorded
 * CONDITIONALLY, the obligation stays visible and attached rather than
 * quietly dropped, and it resolves itself automatically the moment
 * compliance actually verifies FICA.
 *
 * Deliberately NOT a third status value — status stays exactly 'approved'
 * so every existing tile/guard/mailer check that already keys off
 * status === 'approved' keeps working untouched (settled with cc1, whose
 * FICA Outstanding tile split reads this same column). Same nullable-
 * timestamp shape FICA's own verified_at already uses: non-null = still
 * conditional; null = plain approval (never was, or the condition has
 * since resolved and this column was cleared — see
 * ResolveConditionalApprovalOnFicaVerified, the listener on the SAME
 * FicaApproved domain event compliance approval already fires).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('approved_subject_to_fica_at')->nullable()->after('approved_rental_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('approved_subject_to_fica_at');
        });
    }
};
