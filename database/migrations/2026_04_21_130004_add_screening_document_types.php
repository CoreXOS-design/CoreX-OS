<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_documents MODIFY COLUMN document_type ENUM(
            'ffc_certificate','id_copy','pi_insurance','tax_clearance',
            'profile_photo','qualification','proof_of_address','bank_confirmation',
            'police_clearance','credit_check_report','reference_letter',
            'other'
        ) NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_documents MODIFY COLUMN document_type ENUM(
            'ffc_certificate','id_copy','pi_insurance','tax_clearance',
            'profile_photo','qualification','proof_of_address','bank_confirmation',
            'other'
        ) NOT NULL DEFAULT 'other'");
    }
};
