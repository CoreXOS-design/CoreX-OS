<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->text('url');
            $table->longText('snapshot_text')->nullable();
            $table->char('content_hash', 64)->nullable(); // sha256 hex
            $table->timestamp('fetched_at')->nullable();
            $table->longText('ai_summary_text')->nullable();
            $table->string('ai_summary_model', 100)->nullable();
            $table->timestamp('ai_summary_created_at')->nullable();
            $table->json('tags_json')->nullable();
            $table->timestamps();

            $table->index('presentation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_articles');
    }
};
