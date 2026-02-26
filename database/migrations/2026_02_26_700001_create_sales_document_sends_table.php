<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_name');
            $table->string('original_file_path')->nullable();
            $table->unsignedBigInteger('sent_by');
            $table->text('message')->nullable();
            $table->enum('status', ['in_progress', 'completed', 'expired'])->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('sent_by')->references('id')->on('users');
            $table->index(['status']);
            $table->index(['sent_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_sends');
    }
};
