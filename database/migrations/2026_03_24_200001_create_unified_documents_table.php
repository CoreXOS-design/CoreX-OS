<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('disk', 20)->default('local'); // local or public
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->string('source_type', 20)->default('upload'); // upload, esign, pdf_splitter
            $table->unsignedBigInteger('source_id')->nullable(); // polymorphic ref
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('document_type_id')->references('id')->on('document_types')->onDelete('set null');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('document_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('party_role', 50)->nullable(); // seller, buyer, landlord, tenant
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->unique(['document_id', 'contact_id', 'party_role']);
        });

        Schema::create('document_properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('cascade');
            $table->unique(['document_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_properties');
        Schema::dropIfExists('document_contacts');
        Schema::dropIfExists('documents');
    }
};
