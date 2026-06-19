<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_drive_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('shared_drive_folders')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['agency_id', 'parent_id']);
            $table->index(['agency_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_drive_folders');
    }
};
