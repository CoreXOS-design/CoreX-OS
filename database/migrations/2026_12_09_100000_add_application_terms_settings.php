<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application terms (rental-applications.md "Application terms: fees, deposit banking, summary, documents"):
 * the agency's own figures and wording printed in the "Amounts payable" / "Deposit banking details" / "Tenant
 * information summary" / "Documents to accompany" sections of the rental application. Every column is nullable
 * and neutral: blank means the section / row is simply not printed. No agency-specific values here - an agency
 * fills them in Settings or the Setup Wizard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'renewal_fee')) {
                $table->decimal('renewal_fee', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'rent_before_occupation_text')) {
                $table->text('rent_before_occupation_text')->nullable();
            }
            foreach (['deposit_bank_account_name' => 191, 'deposit_bank_name' => 100, 'deposit_bank_branch_code' => 50,
                'deposit_bank_account_type' => 50, 'deposit_bank_account_number' => 50] as $col => $len) {
                if (! Schema::hasColumn('lease_settings', $col)) {
                    $table->string($col, $len)->nullable();
                }
            }
            if (! Schema::hasColumn('lease_settings', 'deposit_reference_rule')) {
                $table->string('deposit_reference_rule', 30)->nullable();
            }
        });

        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_application_qualifying_settings', 'application_fee')) {
                $table->decimal('application_fee', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_application_qualifying_settings', 'tenant_info_summary')) {
                $table->text('tenant_info_summary')->nullable();
            }
            if (! Schema::hasColumn('rental_application_qualifying_settings', 'documents_to_accompany')) {
                $table->text('documents_to_accompany')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['renewal_fee', 'rent_before_occupation_text', 'deposit_bank_account_name', 'deposit_bank_name',
                'deposit_bank_branch_code', 'deposit_bank_account_type', 'deposit_bank_account_number', 'deposit_reference_rule'] as $col) {
                if (Schema::hasColumn('lease_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            foreach (['application_fee', 'tenant_info_summary', 'documents_to_accompany'] as $col) {
                if (Schema::hasColumn('rental_application_qualifying_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
