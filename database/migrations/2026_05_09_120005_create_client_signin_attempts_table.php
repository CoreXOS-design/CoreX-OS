<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_signin_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('identifier');
            $table->boolean('matched')->default(false);
            $table->unsignedTinyInteger('agency_count')->default(0);
            $table->string('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('identifier');
            $table->index(['matched', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_signin_attempts');
    }
};
