<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_pack_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pack_id');
            $table->integer('sort_order')->default(0);
            $table->string('label');
            $table->enum('slot_type', ['required', 'selectable', 'attachment']);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->unsignedBigInteger('knowledge_category_id')->nullable();
            $table->boolean('allow_multiple')->default(false);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->foreign('pack_id')->references('id')->on('docuperfect_packs')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('docuperfect_templates')->onDelete('set null');
            $table->foreign('document_type_id')->references('id')->on('docuperfect_document_types')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_pack_slots');
    }
};
