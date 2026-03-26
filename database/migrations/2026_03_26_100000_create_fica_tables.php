<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fica_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->dateTime('token_expires_at');
            $table->enum('entity_type', ['natural', 'company', 'trust', 'partnership'])->default('natural');
            $table->json('form_data')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'corrections_requested', 'approved', 'rejected'])->default('draft');
            $table->tinyInteger('risk_rating')->nullable();
            $table->json('verification_method')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->string('pdf_path')->nullable();
            $table->longText('signature_data')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('token');
            $table->index('status');
            $table->index('contact_id');
        });

        Schema::create('fica_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fica_submission_id')->constrained('fica_submissions')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->enum('status', ['uploaded', 'accepted', 'rejected'])->default('uploaded');
            $table->string('rejection_reason')->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('fica_submission_id');
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fica_documents');
        Schema::dropIfExists('fica_submissions');
    }
};
