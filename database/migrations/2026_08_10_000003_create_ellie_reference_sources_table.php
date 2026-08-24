<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ellie External Reference Sources — a small, admin-curated allowlist of external
 * pages Ellie may search when CoreX's own knowledge base and pillar data don't
 * have an answer (e.g. a bank's current interest-rate page).
 *
 * Deliberately global, no `agency_id` — same category as the SA-legislation
 * knowledge base, which is also platform-managed rather than per-agency.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ellie_reference_sources', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('title')->nullable();
            $table->foreignId('added_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_fetch_status', 20)->default('pending'); // pending | ok | error
            $table->text('fetch_error')->nullable();
            $table->string('content_hash', 64)->nullable(); // sha256 of extracted text
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('last_fetch_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ellie_reference_sources');
    }
};
