<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('web_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_pack_id')->constrained('web_packs')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('docuperfect_templates')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_pack_items');
    }
};
