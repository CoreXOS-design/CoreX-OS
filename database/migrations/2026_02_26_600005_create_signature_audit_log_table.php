<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->string('action');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('actor_email')->nullable();
            $table->string('actor_ip_address', 45)->nullable();
            $table->text('actor_user_agent')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('document_hash', 64)->nullable();
            $table->timestamp('created_at');
            // No updated_at — immutable audit log

            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->nullOnDelete();

            $table->index(['signature_template_id', 'created_at']);
            $table->index(['action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_audit_log');
    }
};
