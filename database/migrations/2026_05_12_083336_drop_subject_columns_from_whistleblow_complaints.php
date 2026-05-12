<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whistleblow_complaints', function (Blueprint $table) {
            $table->dropColumn([
                'subject_agency_name',
                'subject_practitioner_name',
                'subject_ffc_number',
                'subject_practitioner_email',
                'subject_practitioner_phone',
                'property_portal_url',
                'portal_source',
                'portal_listing_ref',
                'seller_consents_to_named_complaint',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('whistleblow_complaints', function (Blueprint $table) {
            $table->string('subject_agency_name')->nullable()->after('tier');
            $table->string('subject_practitioner_name')->nullable()->after('subject_agency_name');
            $table->string('subject_ffc_number')->nullable()->after('subject_practitioner_name');
            $table->string('subject_practitioner_email')->nullable()->after('subject_ffc_number');
            $table->string('subject_practitioner_phone')->nullable()->after('subject_practitioner_email');
            $table->string('property_portal_url')->nullable()->after('property_address');
            $table->enum('portal_source', ['p24', 'pp', 'other'])->nullable()->after('property_portal_url');
            $table->string('portal_listing_ref')->nullable()->after('portal_source');
            $table->boolean('seller_consents_to_named_complaint')->default(false)->after('seller_statement');
        });
    }
};
