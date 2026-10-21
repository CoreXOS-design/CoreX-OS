<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for `daily-points:correct-calendar-credits` (agency-tracker-rehome.md 5.6 defect 1; Johan, 21 Oct 2026).
 *
 * One row per entry the command corrected: which credit, whose, which month, the old and the new count and the
 * points each was worth. Append-only - nothing ever updates or deletes a row - and the unique key
 * (entry_id, correction_key) is what makes the command safe to run twice: an entry is corrected once per correction.
 * Entries are never deleted either, so entry_id always resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity_entry_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entry_id');
            $table->string('correction_key', 60);
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('activity_definition_id');
            $table->date('activity_date');
            $table->string('period', 7);
            $table->decimal('weight', 10, 2);
            $table->integer('old_value');
            $table->integer('new_value');
            $table->decimal('old_points', 14, 2);
            $table->decimal('new_points', 14, 2);
            $table->string('reason', 255);
            $table->string('ran_on', 120)->nullable();
            $table->timestamp('corrected_at')->useCurrent();

            $table->unique(['entry_id', 'correction_key'], 'dae_corrections_once');
            $table->index(['agency_id', 'period'], 'dae_corrections_period_idx');
            $table->index('user_id', 'dae_corrections_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity_entry_corrections');
    }
};
