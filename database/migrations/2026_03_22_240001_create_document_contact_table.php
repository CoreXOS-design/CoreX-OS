<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_contact', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('party_role', 50)->nullable(); // seller, buyer, landlord, tenant, etc.
            $table->string('document_type', 50)->nullable(); // fica, mandate, lease, disclosure, etc.
            $table->boolean('is_signed')->default(false);
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')->on('docuperfect_documents')
                ->onDelete('cascade');

            $table->foreign('contact_id')
                ->references('id')->on('contacts')
                ->onDelete('cascade');

            $table->unique(['document_id', 'contact_id', 'party_role']);
            $table->index(['contact_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_contact');
    }
};
