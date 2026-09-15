<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submission identity gate, 2026-09-13 — Johan walked the applicant link
 * himself, signed both pads, pressed submit, and landed straight in FICA
 * with no identity challenge anywhere. See
 * .ai/specs/rental-applications.md, "Submission identity gate" section.
 *
 * All nullable, same convention as every other agency-configurable knob on
 * this table: null falls through to the model's DEFAULT_* constant (or, for
 * the OTP-specific ones, through to App\Services\Otp\OtpService's own
 * config/otp.php defaults) — a row is never required to exist for an
 * agency to get sensible behaviour.
 *
 * Coordinated directly with cc6 before either migration was written — both
 * lanes extending this same table in the same window (cc6:
 * required_field_keys, marital_status_options). Separate migration files,
 * no shared column names, confirmed both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('identity_gate_enabled')->nullable()->after('return_gate_attempt_window_minutes');
            $table->unsignedTinyInteger('identity_gate_otp_length')->nullable()->after('identity_gate_enabled');
            $table->unsignedInteger('identity_gate_otp_expiry_minutes')->nullable()->after('identity_gate_otp_length');
            $table->unsignedInteger('identity_gate_attempt_max')->nullable()->after('identity_gate_otp_expiry_minutes');
            $table->unsignedInteger('identity_gate_attempt_window_minutes')->nullable()->after('identity_gate_attempt_max');
            $table->unsignedInteger('identity_gate_resend_cooldown_seconds')->nullable()->after('identity_gate_attempt_window_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn([
                'identity_gate_enabled',
                'identity_gate_otp_length',
                'identity_gate_otp_expiry_minutes',
                'identity_gate_attempt_max',
                'identity_gate_attempt_window_minutes',
                'identity_gate_resend_cooldown_seconds',
            ]);
        });
    }
};
