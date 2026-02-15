<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();

            // Optional metadata for UX
            $table->string('title')->nullable();              // e.g. "February Targets"
            $table->string('status')->default('active');      // active|archived
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            // Keep simple: match your existing users table type
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
