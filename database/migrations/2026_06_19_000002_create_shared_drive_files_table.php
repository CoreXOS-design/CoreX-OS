<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_drive_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('folder_id')->references('id')->on('shared_drive_folders')->nullOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['agency_id', 'folder_id']);
            $table->index(['agency_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_drive_files');
    }
};
