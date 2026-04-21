<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'cancelled' to fica_submissions status enum
        DB::statement("ALTER TABLE fica_submissions MODIFY status ENUM(
            'draft','submitted','under_review','agent_approved',
            'corrections_requested','approved','rejected','cancelled'
        ) NULL DEFAULT 'draft'");

        // Resend audit log
        Schema::create('fica_resend_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fica_submission_id')->constrained('fica_submissions')->cascadeOnDelete();
            $table->foreignId('resent_by')->constrained('users');
            $table->timestamp('resent_at');
            $table->string('reason_code', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['fica_submission_id', 'resent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fica_resend_logs');

        DB::statement("ALTER TABLE fica_submissions MODIFY status ENUM(
            'draft','submitted','under_review','agent_approved',
            'corrections_requested','approved','rejected'
        ) NULL DEFAULT 'draft'");
    }
};
