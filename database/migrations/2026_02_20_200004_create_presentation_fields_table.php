<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('presentation_id');
            $table->string('field_key');
            $table->text('extracted_value')->nullable();
            $table->text('override_value')->nullable();
            $table->text('final_value')->nullable();
            $table->unsignedBigInteger('source_upload_id')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();

            $table->foreign('presentation_id')->references('id')->on('presentations')->onDelete('cascade');
            $table->foreign('source_upload_id')->references('id')->on('presentation_uploads')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_fields');
    }
};
