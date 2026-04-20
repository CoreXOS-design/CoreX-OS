<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('document_type', [
                'ffc_certificate',
                'id_copy',
                'pi_insurance',
                'tax_clearance',
                'profile_photo',
                'qualification',
                'proof_of_address',
                'bank_confirmation',
                'other',
            ]);

            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->unsignedInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();

            $table->enum('status', ['pending', 'verified', 'rejected', 'expired'])
                  ->default('pending');

            $table->date('expiry_date')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'document_type', 'status']);
            $table->index(['status', 'agency_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_documents');
    }
};
