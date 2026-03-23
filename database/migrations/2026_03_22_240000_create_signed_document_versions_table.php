<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->integer('version_number')->default(1);
            $table->string('file_path');
            $table->string('file_type', 10); // pdf, jpg, png
            $table->string('uploaded_by_name')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('agent_approved')->default(false);
            $table->timestamp('agent_approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')->on('docuperfect_documents')
                ->onDelete('cascade');

            $table->foreign('signature_request_id')
                ->references('id')->on('signature_requests')
                ->onDelete('set null');

            $table->foreign('approved_by')
                ->references('id')->on('users')
                ->onDelete('set null');

            $table->index(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_document_versions');
    }
};
