<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 (Road to Sold, lane 3).
 *
 * rts_holds — capturing an outcome gives that agent the property for the agency's hold days; it is
 * not suggested to other agents; each new outcome restarts it; once expires_at passes the property
 * is free. ONE row per (agency, track, tracked property): a new outcome by anyone, once the hold
 * has lapsed, re-uses the row (unique ignores soft deletes — never a bare create).
 *
 * rts_property_flags — an RTS-level "never suggest" marker on the tracked property, so a property
 * with no owner contact (nothing to put a call opt-out on) is still excluded for every agent after
 * a "Do not contact" outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('track', 20)->default('sales');

            $table->foreignId('tracked_property_id')->constrained('tracked_properties')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_outcome_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('released_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'tracked_property_id'], 'rts_hold_unique');
            $table->index(['agency_id', 'track', 'user_id', 'expires_at'], 'rts_hold_by_agent');
        });

        Schema::create('rts_property_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('track', 20)->default('sales');

            $table->foreignId('tracked_property_id')->constrained('tracked_properties')->cascadeOnDelete();
            $table->string('flag', 30)->default('never_suggest');
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('outcome_id')->nullable();
            $table->string('reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'tracked_property_id', 'flag'], 'rts_flag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_property_flags');
        Schema::dropIfExists('rts_holds');
    }
};
