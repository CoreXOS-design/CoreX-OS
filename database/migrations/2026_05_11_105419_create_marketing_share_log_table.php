<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_share_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('agency_id')->constrained();
            $table->string('channel', 50);
            $table->string('recipient_context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_share_log');
    }
};
