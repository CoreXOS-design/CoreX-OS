<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_type_id')->nullable()->after('size');
            $table->unsignedBigInteger('property_id')->nullable()->after('document_type_id');
            $table->string('source_type', 20)->default('upload')->after('property_id');

            $table->foreign('document_type_id')->references('id')->on('document_types')->onDelete('set null');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('set null');
        });

        Schema::table('property_files', function (Blueprint $table) {
            $table->unsignedBigInteger('document_type_id')->nullable()->after('mime_type');
            $table->unsignedBigInteger('contact_id')->nullable()->after('document_type_id');
            $table->string('source_type', 20)->default('upload')->after('contact_id');

            $table->foreign('document_type_id')->references('id')->on('document_types')->onDelete('set null');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('contact_documents', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['property_id']);
            $table->dropColumn(['document_type_id', 'property_id', 'source_type']);
        });

        Schema::table('property_files', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['contact_id']);
            $table->dropColumn(['document_type_id', 'contact_id', 'source_type']);
        });
    }
};
