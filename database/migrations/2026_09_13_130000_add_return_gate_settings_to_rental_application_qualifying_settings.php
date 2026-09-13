<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-13 (round 4) — Johan's decision, from his own words:
 * "played around that initial open is not gated but if the applicant
 * submits we should have the id number which we can update the contact
 * record with, so after initial submission we can gate on ID." First open
 * is never gated; every RETURN after the applicant has submitted at least
 * once is gated — that link now holds an ID number, payslips and bank
 * statements behind a URL that can live in an inbox indefinitely.
 *
 * Default gate is the applicant's own ID number (already captured on the
 * application) — a speed bump, not authentication, and the spec says so
 * plainly. email_otp is the agency-configurable stronger option, for
 * agencies that want the gate to actually hold, reusing CoreX's existing
 * OtpService — not a second one-time-code mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->string('return_gate_method', 20)->nullable()->after('require_fica_before_authorisation');
            $table->unsignedTinyInteger('return_gate_attempt_max')->nullable()->after('return_gate_method');
            $table->unsignedSmallInteger('return_gate_attempt_window_minutes')->nullable()->after('return_gate_attempt_max');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['return_gate_method', 'return_gate_attempt_max', 'return_gate_attempt_window_minutes']);
        });
    }
};
