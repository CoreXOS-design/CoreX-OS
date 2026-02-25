<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_pack_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pack_instance_id');
            $table->unsignedBigInteger('knowledge_document_id');
            $table->string('slot_label');
            $table->timestamps();

            $table->foreign('knowledge_document_id')
                  ->references('id')->on('knowledge_documents')
                  ->onDelete('cascade');
            $table->index('pack_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_pack_attachments');
    }
};
