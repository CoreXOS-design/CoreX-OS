<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9c-2 — Information Officer appointments per POPIA s55.
 *
 * Mirrors the existing fica_officer_appointments table verbatim (columns,
 * naming, soft-delete + audit pattern). Roles:
 *   - primary_information_officer  → POPIA s55 designated IO (one active per agency)
 *   - deputy_information_officer   → support / delegate IOs (multiple allowed)
 *
 * Single-active-primary enforcement is handled by the model's `creating`
 * boot hook (same pattern as FicaOfficerAppointment) — when a new primary
 * is appointed, the current active primary's `ended_on` is auto-stamped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('information_officer_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('role', ['primary_information_officer', 'deputy_information_officer']);

            $table->string('full_name', 200);
            $table->string('id_number', 20)->nullable();
            $table->string('cell', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('title', 100)->default('Information Officer');

            $table->date('appointed_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('appointment_letter_path', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'role', 'ended_on']);
            $table->index(['user_id', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('information_officer_appointments');
    }
};
