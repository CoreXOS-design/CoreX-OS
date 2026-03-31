<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_step_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_step_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_documents');
    }
};
