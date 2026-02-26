<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->string('party_role');
            $table->integer('signing_order')->default(1);
            $table->string('signer_name');
            $table->string('signer_email');
            $table->string('signer_id_number', 20)->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->enum('status', [
                'waiting', 'pending', 'viewed', 'partially_signed',
                'completed', 'expired', 'declined',
            ])->default('waiting');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->text('message')->nullable();
            // Wet ink columns
            $table->enum('signing_method', ['electronic', 'wet_ink'])->nullable();
            $table->string('wet_ink_upload_path')->nullable();
            $table->enum('wet_ink_status', [
                'pending_upload', 'uploaded_pending_review', 'approved', 'rejected',
            ])->nullable();
            $table->text('wet_ink_rejection_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('sent_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['signature_template_id', 'status']);
            $table->index(['party_role']);
            $table->index(['status', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
