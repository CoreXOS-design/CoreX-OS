<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_screening_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_screening_id')->constrained('employee_screenings')
                  ->cascadeOnDelete();

            $table->enum('check_type', [
                'employment_history_verified',
                'qualification_verified',
                'references_checked',
                'ppra_ffc_verified',
                'criminal_record_check',
                'credit_check',
                'id_verification',
                'address_verification',
                'tfs_screening',
                'previous_aml_role_review',
                'high_risk_association_check',
            ]);

            $table->enum('result', ['clear', 'concerns', 'fail', 'not_applicable', 'pending'])
                  ->default('pending');

            $table->date('checked_on')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->foreignId('supporting_document_id')->nullable()
                  ->constrained('user_documents')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_screening_id', 'check_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_screening_checks');
    }
};
