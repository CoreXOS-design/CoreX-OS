<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('presentation_id');
            $table->string('section_key');
            $table->json('data_json');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('presentation_id')->references('id')->on('presentations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_sections');
    }
};
