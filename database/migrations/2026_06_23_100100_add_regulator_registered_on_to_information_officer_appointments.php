<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9c (AT-16) — finding #2 follow-up.
 *
 * The Information Officer appointment already records appointed_on (the
 * internal appointment date). POPIA s55 also requires the IO to be registered
 * WITH the Information Regulator — a distinct event with its own date. This
 * column captures that regulator-registration date so the privacy policy can
 * evidence the s55 registration, not merely the internal appointment.
 *
 * Nullable: an IO may be appointed internally before the Regulator
 * registration has been completed/confirmed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('information_officer_appointments', function (Blueprint $t) {
            $t->date('regulator_registered_on')->nullable()->after('appointed_on');
        });
    }

    public function down(): void
    {
        Schema::table('information_officer_appointments', function (Blueprint $t) {
            $t->dropColumn('regulator_registered_on');
        });
    }
};
