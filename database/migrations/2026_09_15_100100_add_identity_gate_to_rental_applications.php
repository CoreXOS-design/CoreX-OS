<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submission identity gate, 2026-09-13 — see
 * .ai/specs/rental-applications.md, "Submission identity gate" section.
 *
 * identity_verified_at mirrors exactly how FICA state is tracked on this
 * model (a single nullable timestamp, not a new status enum value) —
 * Johan's own instruction: "reusing the existing shape means the agent
 * learns one pattern, not two."
 *
 * identity_gate_unreachable is the agency-facing flag for Johan's ruling
 * that a genuinely unreachable applicant (no email, no ID number — both
 * legitimately optional per-agency) is let through, never blocked, and
 * chased by the agent instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('identity_verified_at')->nullable()->after('submitted_at');
            $table->boolean('identity_gate_unreachable')->default(false)->after('identity_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn(['identity_verified_at', 'identity_gate_unreachable']);
        });
    }
};
