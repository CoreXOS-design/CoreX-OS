<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->enum('category', [
                'annual', 'sick', 'family_responsibility', 'parental',
                'study', 'unpaid', 'special', 'other',
            ]);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_uif_claimable')->default(false);
            $table->boolean('requires_documentation')->default(false);
            $table->string('documentation_label')->nullable();
            $table->smallInteger('documentation_threshold_days')->unsigned()->nullable();
            $table->decimal('entitlement_days_per_cycle', 5, 2)->default(0);
            $table->decimal('entitlement_days_per_cycle_six_day', 5, 2)->default(0);
            $table->smallInteger('cycle_months')->unsigned()->default(12);
            $table->enum('accrual_method', [
                'full_at_start', 'accrual_per_day_worked',
                'accrual_first_six_months', 'none',
            ]);
            $table->smallInteger('accrual_rate_per_days')->unsigned()->default(17);
            $table->boolean('accrual_starts_at_employment_date')->default(true);
            $table->boolean('requires_pre_approval')->default(true);
            $table->smallInteger('min_advance_notice_days')->unsigned()->default(0);
            $table->boolean('allows_negative_balance')->default(false);
            $table->boolean('carries_over_to_next_cycle')->default(true);
            $table->smallInteger('forfeit_after_months')->unsigned()->nullable();
            $table->boolean('payout_on_termination')->default(false);
            $table->boolean('affects_payroll')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active']);
            $table->unique(['agency_id', 'code', 'deleted_at'], 'leave_types_agency_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
