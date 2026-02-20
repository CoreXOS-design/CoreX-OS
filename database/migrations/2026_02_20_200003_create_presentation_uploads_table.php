<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_uploads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('presentation_id');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('type');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->longText('text_extracted')->nullable();
            $table->json('extraction_json')->nullable();
            $table->enum('extraction_status', ['pending', 'ok', 'failed'])->default('pending');
            $table->timestamps();

            $table->foreign('presentation_id')->references('id')->on('presentations')->onDelete('cascade');
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_uploads');
    }
};
