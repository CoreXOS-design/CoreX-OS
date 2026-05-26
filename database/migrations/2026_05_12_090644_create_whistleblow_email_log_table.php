<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblow_email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')
                  ->constrained('whistleblow_complaints')
                  ->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->string('email_type')->default('ppra_submission');
            $table->string('subject');
            $table->json('recipients_to');
            $table->json('recipients_cc')->nullable();
            $table->json('recipients_bcc')->nullable();
            $table->longText('rendered_html');
            $table->longText('rendered_text')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users');
            $table->string('mail_message_id')->nullable();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblow_email_log');
    }
};
