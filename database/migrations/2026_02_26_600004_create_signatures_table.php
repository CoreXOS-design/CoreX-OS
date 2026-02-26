<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_marker_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->string('signer_name');
            $table->string('signer_email');
            $table->string('signer_ip_address', 45);
            $table->text('signer_user_agent')->nullable();
            $table->longText('signature_data');
            $table->enum('signature_type', ['drawn', 'typed'])->default('drawn');
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->nullOnDelete();
            $table->foreign('signer_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['signature_template_id', 'signed_at']);
            $table->index(['signature_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
