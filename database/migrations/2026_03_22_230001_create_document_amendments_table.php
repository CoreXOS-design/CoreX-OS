<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('docuperfect_documents')->cascadeOnDelete();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amended_by_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->enum('amendment_type', ['addition', 'strikeout', 'modification'])->default('addition');
            $table->string('section_reference')->nullable();
            $table->text('original_text')->nullable();
            $table->text('new_text');
            $table->unsignedInteger('document_version_before')->default(1);
            $table->unsignedInteger('document_version_after')->default(2);
            $table->string('document_hash_before', 64)->nullable();
            $table->string('document_hash_after', 64)->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['signature_template_id', 'status']);
            $table->index(['document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_amendments');
    }
};
