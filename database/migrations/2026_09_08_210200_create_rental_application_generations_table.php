<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen/resubmit, 2026-09-08 — one immutable, hash-chained snapshot of an
 * applicant's answers exactly as they stood at the moment they were
 * submitted and signed. Deliberately copies
 * App\Models\Docuperfect\DocumentSealedVersion's shape (append-only, no
 * updated_at, content_hash = sha256(prev_hash . snapshot_json), chained to
 * the previous generation) rather than inventing a second immutable-record
 * pattern — Johan/the reopen-investigation report both called this out as
 * the existing, proven mechanism to reuse.
 *
 * Every submit() — the very first one AND every resubmit after a reopen —
 * writes exactly one row here, sealing the content that submission just
 * signed. "A read-only signed view must be able to show what was signed at
 * each point" reads directly off this table; the LIVE rental_applications
 * row always reflects only the CURRENT (most recent) generation's answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            // Frozen copy of every applicant-answer field
            // (RentalApplication::fieldValidationRules()) as they stood at
            // this submission — not a live reference, a point-in-time copy.
            $table->json('snapshot_json');

            $table->timestamp('submitted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->string('content_hash', 64);
            $table->string('prev_hash', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['rental_application_id', 'generation'], 'ra_generations_app_gen_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_generations');
    }
};
