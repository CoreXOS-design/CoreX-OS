<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('p24_import_runs')->cascadeOnDelete();
            $table->enum('row_type', ['agent', 'listing', 'image']);
            $table->string('external_id')->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->json('mapped_json')->nullable();
            $table->enum('action', ['create', 'update', 'skip'])->default('create');
            $table->enum('status', ['pending', 'confirmed', 'excluded', 'error'])->default('pending');
            $table->foreignId('resolved_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('errors_json')->nullable();
            $table->json('image_urls_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['run_id', 'row_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_import_rows');
    }
};
