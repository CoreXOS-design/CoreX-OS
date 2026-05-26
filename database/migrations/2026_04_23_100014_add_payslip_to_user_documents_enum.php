<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cross-driver enum widen. Column created via $table->enum() in
    // 2026_04_21_000002_create_user_documents_table.
    public function up(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->enum('document_type', ['ffc_certificate', 'id_copy', 'pi_insurance', 'tax_clearance', 'profile_photo', 'qualification', 'proof_of_address', 'bank_confirmation', 'police_clearance', 'credit_check_report', 'reference_letter', 'other', 'payslip'])
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->enum('document_type', ['ffc_certificate', 'id_copy', 'pi_insurance', 'tax_clearance', 'profile_photo', 'qualification', 'proof_of_address', 'bank_confirmation', 'police_clearance', 'credit_check_report', 'reference_letter', 'other'])
                ->change();
        });
    }
};
