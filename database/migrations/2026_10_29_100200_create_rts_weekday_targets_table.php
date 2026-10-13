<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — Road to Sold: an agent's OWN number for a weekday (.ai/specs/road-to-sold.md §3.3).
 *
 * One row per (agency, track, agent, weekday). weekday is ISO: 1 = Monday … 7 = Sunday.
 * No row (or an archived one) means "use the agency default for that weekday", which lives in
 * rts_settings.default_weekday_targets. A row with target 0 means this agent really owes nothing
 * that day (part-timer, day off).
 *
 * Soft delete only. The unique index ignores soft deletes, so writing a weekday that was ever
 * cleared RESTORES the trashed row (BUILD_STANDARD §5a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_weekday_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('track', 20)->default('sales');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('target');
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'user_id', 'weekday'], 'rts_weekday_targets_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_weekday_targets');
    }
};
