<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §43 — a real "Schedule inspection" action,
 * distinct from the existing immediate "Start" (RentalInspection::start()).
 * `scheduled_for` already existed (date-only) but had no write path anywhere
 * in the app — this adds the fields an actual booking needs: a time of day,
 * a duration, who is doing it (may differ from whoever books it), and a
 * free-text note. `scheduled_for` itself is left as a DATE column
 * (unchanged) — `scheduled_time` carries the time-of-day separately rather
 * than widening the existing column, so every existing read site
 * (list sort/filter, the "Scheduled" tile, print/export) keeps working
 * unchanged against the date part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->time('scheduled_time')->nullable()->after('scheduled_for');
            $table->unsignedSmallInteger('scheduled_duration_minutes')->nullable()->after('scheduled_time');
            $table->foreignId('inspector_user_id')->nullable()->after('scheduled_duration_minutes')
                ->constrained('users')->nullOnDelete();
            $table->text('schedule_note')->nullable()->after('inspector_user_id');

            $table->index(['scheduled_for', 'scheduled_time']);
            $table->index('inspector_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn(['scheduled_time', 'scheduled_duration_minutes', 'schedule_note']);
            $table->dropConstrainedForeignId('inspector_user_id');
        });
    }
};
