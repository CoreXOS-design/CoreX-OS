<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental application/lease audit fixes (A5, 2026-10-19) - whether an accepted notice ends the tenancy on its move-out
 * date by itself, or only once a completed out-inspection confirms the property is empty. Default ON: rental-renewals.md
 * §22 says "a tenancy ends on its move-out date - the out-inspection only confirms it is vacant".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->boolean('end_tenancy_on_move_out_date')->default(true)->after('auto_restore_status_on_lease_ended');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('end_tenancy_on_move_out_date');
        });
    }
};
