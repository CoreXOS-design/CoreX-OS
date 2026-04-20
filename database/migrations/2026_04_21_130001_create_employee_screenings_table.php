<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('screening_type', ['pre_employment', 'periodic', 'tfs_list_update', 'triggered'])
                  ->default('periodic');
            $table->enum('risk_tier', ['high', 'medium', 'low'])->default('medium');

            $table->enum('status', ['in_progress', 'completed', 'flagged', 'cancelled'])
                  ->default('in_progress');

            $table->date('initiated_on');
            $table->date('completed_on')->nullable();
            $table->date('next_due_on')->nullable();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('overall_result', ['pass', 'concerns_flagged', 'fail'])->nullable();
            $table->text('summary_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'user_id', 'status']);
            $table->index(['status', 'next_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_screenings');
    }
};
