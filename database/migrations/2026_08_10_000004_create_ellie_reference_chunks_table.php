<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extracted, chunked, embedded text from each approved Ellie reference source.
 * Mirrors training_doc_chunks (2026_05_12_160000_create_training_help_tables.php)
 * — same embedding shape (JSON-encoded float array, self-hosted BGE, 384 dims).
 *
 * Spec: .ai/specs/ellie-reference-sources.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ellie_reference_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('ellie_reference_sources')->cascadeOnDelete();
            $table->unsignedSmallInteger('chunk_index');
            $table->text('content');
            $table->longText('embedding')->nullable(); // JSON array of floats
            $table->boolean('has_embedding')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_id', 'chunk_index']);
            $table->index('has_embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ellie_reference_chunks');
    }
};
