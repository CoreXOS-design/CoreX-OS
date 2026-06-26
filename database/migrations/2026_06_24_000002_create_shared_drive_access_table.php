<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_drive_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('drive_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('drive_id')->references('id')->on('shared_drives')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['drive_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_drive_access');
    }
};
