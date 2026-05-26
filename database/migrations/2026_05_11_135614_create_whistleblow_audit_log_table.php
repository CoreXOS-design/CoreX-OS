<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblow_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')
                  ->constrained('whistleblow_complaints')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action');
            $table->json('action_data')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblow_audit_log');
    }
};
