<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('id_number', 20)->nullable()->after('cell');
            $table->string('id_document_path', 500)->nullable()->after('id_number');
            $table->date('ffc_expiry_date')->nullable()->after('ffc_number');
            $table->enum('ppra_status', ['active', 'pending', 'expired', 'suspended'])
                  ->nullable()->after('ffc_expiry_date');
            $table->string('pi_insurance_path', 500)->nullable()->after('ffc_certificate_path');
            $table->date('pi_insurance_expiry')->nullable()->after('pi_insurance_path');
            $table->string('tax_clearance_path', 500)->nullable()->after('pi_insurance_expiry');
            $table->date('tax_clearance_expiry')->nullable()->after('tax_clearance_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'tax_clearance_expiry',
                'tax_clearance_path',
                'pi_insurance_expiry',
                'pi_insurance_path',
                'ppra_status',
                'ffc_expiry_date',
                'id_document_path',
                'id_number',
            ]);
        });
    }
};
