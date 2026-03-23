<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esign_consent_log', function (Blueprint $table) {
            // Add missing columns per spec
            $table->unsignedBigInteger('document_id')->nullable()->after('flow_id');
            $table->unsignedBigInteger('signature_request_id')->nullable()->after('document_id');
            $table->boolean('id_verified')->default(false)->after('id_number_entered');

            // Add foreign keys (nullable — not all signing flows use wizard)
            $table->foreign('document_id')
                ->references('id')->on('docuperfect_documents')
                ->nullOnDelete();
            $table->foreign('signature_request_id')
                ->references('id')->on('signature_requests')
                ->nullOnDelete();

            // Index for lookups
            $table->index('signature_request_id');
            $table->index('document_id');
        });

        // Make existing FKs nullable (they were required but shouldn't be for basic signing flow)
        Schema::table('esign_consent_log', function (Blueprint $table) {
            $table->unsignedBigInteger('flow_id')->nullable()->change();
            $table->unsignedBigInteger('signing_party_id')->nullable()->change();
            $table->unsignedBigInteger('contact_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('esign_consent_log', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['signature_request_id']);
            $table->dropColumn(['document_id', 'signature_request_id', 'id_verified']);
        });
    }
};
