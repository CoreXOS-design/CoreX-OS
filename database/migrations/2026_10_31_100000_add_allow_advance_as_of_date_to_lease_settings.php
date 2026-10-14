<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §22.1 - a QA / Staging-only testing knob: lets an agency admin run "today's lease date checks" for one lease
 * with an "as of" date, so a renewal or a move-out can be seen to happen without a scheduler and without waiting for the date. Default off;
 * the control is hidden (and the value ignored) on a production install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->boolean('allow_advance_as_of_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('allow_advance_as_of_date');
        });
    }
};
