<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_named_fields', function (Blueprint $table) {
            $table->enum('source_type', ['property', 'contact', 'agent', 'static', 'computed', 'manual'])
                  ->default('manual')->after('sort_order');
            $table->string('source_column', 255)->nullable()->after('source_type');
            $table->string('source_contact_type', 50)->nullable()->after('source_column');
        });

        // Contact-sourced: Lessor
        DB::table('docuperfect_named_fields')
            ->whereIn('name', ['Rental - Lessor', 'Lessor Name'])
            ->update(['source_type' => 'contact', 'source_column' => 'first_name+last_name', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessor Address')
            ->update(['source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessor ID')
            ->update(['source_type' => 'contact', 'source_column' => 'id_number', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessor Contact Number')
            ->update(['source_type' => 'contact', 'source_column' => 'phone', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessor email')
            ->update(['source_type' => 'contact', 'source_column' => 'email', 'source_contact_type' => 'Lessor']);

        // Contact-sourced: Lessee
        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessee Name')
            ->update(['source_type' => 'contact', 'source_column' => 'first_name+last_name', 'source_contact_type' => 'Lessee']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessee Address')
            ->update(['source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Lessee']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lessee ID')
            ->update(['source_type' => 'contact', 'source_column' => 'id_number', 'source_contact_type' => 'Lessee']);

        // Contact-sourced: Seller
        DB::table('docuperfect_named_fields')
            ->whereIn('name', ['Seller Name', 'Seller'])
            ->update(['source_type' => 'contact', 'source_column' => 'first_name+last_name', 'source_contact_type' => 'Seller']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Seller address')
            ->update(['source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Seller']);

        // Contact-sourced: Banking (Lessor)
        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease Account Name')
            ->update(['source_type' => 'contact', 'source_column' => 'bank_account_name', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease Bank Name')
            ->update(['source_type' => 'contact', 'source_column' => 'bank_name', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Account Number')
            ->update(['source_type' => 'contact', 'source_column' => 'bank_account_number', 'source_contact_type' => 'Lessor']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Branch Name')
            ->update(['source_type' => 'contact', 'source_column' => 'bank_branch_name', 'source_contact_type' => 'Lessor']);

        // Property-sourced
        DB::table('docuperfect_named_fields')
            ->where('name', 'Street')
            ->update(['source_type' => 'property', 'source_column' => 'address']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Property Address')
            ->update(['source_type' => 'property', 'source_column' => 'address+suburb']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Suburb')
            ->update(['source_type' => 'property', 'source_column' => 'suburb']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'District')
            ->update(['source_type' => 'property', 'source_column' => 'district']);

        DB::table('docuperfect_named_fields')
            ->whereIn('name', ['Property Number', 'Number'])
            ->update(['source_type' => 'property', 'source_column' => 'property_number']);

        DB::table('docuperfect_named_fields')
            ->whereIn('name', ['Complex', 'Rental Complex'])
            ->update(['source_type' => 'property', 'source_column' => 'complex_name']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Rental Unit Nr')
            ->update(['source_type' => 'property', 'source_column' => 'unit_number']);

        DB::table('docuperfect_named_fields')
            ->whereIn('name', ['Rental Amount', 'Amount'])
            ->update(['source_type' => 'property', 'source_column' => 'rental_amount']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Price')
            ->update(['source_type' => 'property', 'source_column' => 'price']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Expiry Date')
            ->update(['source_type' => 'property', 'source_column' => 'expiry_date']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease date')
            ->update(['source_type' => 'property', 'source_column' => 'lease_start_date']);

        // Computed
        DB::table('docuperfect_named_fields')
            ->where('name', 'Price[words]')
            ->update(['source_type' => 'computed', 'source_column' => 'price_in_words']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease Date Day')
            ->update(['source_type' => 'computed', 'source_column' => 'lease_start_day']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease Date Month')
            ->update(['source_type' => 'computed', 'source_column' => 'lease_start_month']);

        DB::table('docuperfect_named_fields')
            ->where('name', 'Lease Date Year')
            ->update(['source_type' => 'computed', 'source_column' => 'lease_start_year']);

        // Static
        DB::table('docuperfect_named_fields')
            ->where('name', 'Ray Nkonyeni')
            ->update(['source_type' => 'static', 'source_column' => 'Ray Nkonyeni']);

        // Agent
        DB::table('docuperfect_named_fields')
            ->where('name', 'Agent name')
            ->update(['source_type' => 'agent', 'source_column' => 'name']);
    }

    public function down(): void
    {
        Schema::table('docuperfect_named_fields', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_column', 'source_contact_type']);
        });
    }
};
