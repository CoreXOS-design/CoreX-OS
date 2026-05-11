<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblow_complaint_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')
                  ->constrained('whistleblow_complaints')
                  ->cascadeOnDelete();
            $table->enum('evidence_type', [
                'screenshot', 'portal_html', 'seller_statement_pdf',
                'photo', 'audio_recording', 'document_upload', 'other',
            ]);
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('size_bytes')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblow_complaint_evidence');
    }
};
