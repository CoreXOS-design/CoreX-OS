<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-486 (21 Oct 2026) - an applicant who presses Submit and is then sent to the identity screen has NOT yet submitted
 * as far as the agency is concerned. Their answers are saved, but the application stays "in progress" (no `returned`
 * status, no `submitted_at`) until the code is confirmed. `identity_hold_at` is when the answers were put on hold behind
 * that screen; the application is released (status + submitted_at set) by the identity-verified step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('identity_hold_at')->nullable()->after('identity_gate_unreachable');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('identity_hold_at');
        });
    }
};
