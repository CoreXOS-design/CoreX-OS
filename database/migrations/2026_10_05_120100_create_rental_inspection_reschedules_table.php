<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §43 — "reschedule keeps history of old
 * date + who/when." One immutable row per reschedule, mirroring
 * contact_match_reassignments' pattern exactly (append-only, SoftDeletes
 * from the first migration per the standing no-hard-deletes rule, even
 * though an audit row is never expected to need removing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained('rental_inspections')->cascadeOnDelete();

            $table->date('old_scheduled_for')->nullable();
            $table->time('old_scheduled_time')->nullable();
            $table->foreignId('old_inspector_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('new_scheduled_for');
            $table->time('new_scheduled_time')->nullable();
            $table->foreignId('new_inspector_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['rental_inspection_id', 'created_at'], 'rir_inspection_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_reschedules');
    }
};
