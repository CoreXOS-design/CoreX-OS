<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── training_docs ──────────────────────────────────────
        Schema::create('training_docs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('role_audience', 50)->default('all');        // all, super_admin, admin, branch_manager, agent, compliance_officer
            $table->string('file_path');
            $table->string('content_hash', 64);                        // sha256
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('reading_time_minutes')->default(0);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamp('last_indexed_at')->nullable();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('role_audience');
            $table->index('sort_order');
        });

        // ── training_doc_chunks ────────────────────────────────
        Schema::create('training_doc_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_id')->constrained('training_docs')->cascadeOnDelete();
            $table->unsignedSmallInteger('chunk_index');
            $table->string('heading_path', 500)->nullable();           // "Compliance Reporting > Filing a Report > Tier 1"
            $table->string('section_anchor', 200)->nullable();         // slug for deep-linking
            $table->text('content');
            $table->unsignedInteger('word_count')->default(0);
            $table->longText('embedding')->nullable();                 // JSON array of floats (text-embedding-3-small, 1536 dims)
            $table->boolean('has_embedding')->default(false);
            $table->timestamps();

            $table->index(['doc_id', 'chunk_index']);
            $table->index('has_embedding');
        });

        // ── training_doc_reads ─────────────────────────────────
        Schema::create('training_doc_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doc_id')->constrained('training_docs')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sections_completed')->nullable();            // array of section anchors
            $table->string('last_section_read', 200)->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('is_outdated_since')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'doc_id']);
            $table->index('agency_id');
        });

        // ── training_doc_bookmarks ─────────────────────────────
        Schema::create('training_doc_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doc_id')->constrained('training_docs')->cascadeOnDelete();
            $table->string('section_anchor', 200);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_doc_bookmarks');
        Schema::dropIfExists('training_doc_reads');
        Schema::dropIfExists('training_doc_chunks');
        Schema::dropIfExists('training_docs');
    }
};
