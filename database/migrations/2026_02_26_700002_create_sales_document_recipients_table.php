<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_send_id')->constrained()->cascadeOnDelete();
            $table->integer('signing_order');
            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->string('recipient_role')->default('client');

            // Token-based access
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at')->nullable();

            // Status tracking
            $table->enum('status', [
                'waiting',
                'sent',
                'downloaded',
                'returned_pending_approval',
                'approved',
                'expired',
            ])->default('waiting');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            // Returned document
            $table->string('returned_file_path')->nullable();
            $table->enum('return_method', ['upload', 'email'])->nullable();

            // Reminders
            $table->integer('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();

            $table->timestamps();

            $table->index(['sales_document_send_id', 'signing_order']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_recipients');
    }
};
