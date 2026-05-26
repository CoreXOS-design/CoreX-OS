<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained('leave_applications')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->enum('document_role', [
                'medical_certificate', 'supporting',
                'signed_application_form', 'other',
            ]);
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_application_documents');
    }
};
