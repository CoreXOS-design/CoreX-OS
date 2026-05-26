<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('event', 64);
            $table->json('meta')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_user_id', 'created_at']);
            $table->index(['agency_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_access_logs');
    }
};
