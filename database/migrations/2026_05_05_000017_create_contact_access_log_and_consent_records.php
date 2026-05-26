<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('action_type', ['view', 'edit', 'export', 'share', 'delete', 'merge']);
            $table->timestamp('accessed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 50)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'accessed_at']);
            $table->index(['agency_id', 'accessed_at']);
        });

        Schema::create('contact_consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->enum('consent_type', [
                'fica_processing', 'marketing_communications', 'data_sharing',
                'channel_email', 'channel_sms', 'channel_whatsapp', 'channel_call',
            ]);
            $table->timestamp('given_at');
            $table->foreignId('given_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('method', ['verbal', 'written', 'electronic', 'signed_document']);
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'consent_type']);
            $table->index(['agency_id', 'consent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_consent_records');
        Schema::dropIfExists('contact_access_log');
    }
};
