<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->unsignedInteger('section_index');
            $table->string('section_label');
            $table->boolean('accepted')->default(false);
            $table->boolean('rejected')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->timestamp('initialled_at')->nullable();
            $table->longText('initial_image')->nullable();
            $table->timestamps();

            $table->unique(['signature_request_id', 'section_index'], 'section_accept_req_idx_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_acceptances');
    }
};
