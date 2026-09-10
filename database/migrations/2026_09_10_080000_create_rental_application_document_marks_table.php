<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-401 — governance rule: an agent's highlight/note is evidence, and an
 * authoriser must not be able to alter or remove it. That rule can only be
 * enforced PER MARK if a mark is a row — with every mark living in one
 * shared JSON blob per document (rental_application_document_highlights.
 * marks_json), the protection depended entirely on the save path's merge
 * logic being correct on every single save, forever, with no way to catch
 * or recover from a bug in it (exactly what happened on application 66 —
 * an authoriser's own save silently dropped an agent's mark, no trace, no
 * undo). Marks become real rows here; the parent
 * rental_application_document_highlights row stays as the document-level
 * record (marks_version for the optimistic-concurrency check, and
 * highlighted_file_path for the flattened burned-in image artifact) —
 * this table is its child.
 *
 * Also future-proofs the OCR auto-highlighting work landing on this same
 * surface (cc5's lane): `source` and `confidence` exist from day one so a
 * machine-generated mark fits without another migration, and every mark
 * already carries its own provenance (author_user_id/author_role for
 * human marks; source='ocr' + confidence for machine ones, author_* left
 * null) and its own soft-delete — individually removable/distinguishable,
 * never bundled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_document_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // The client-generated stable id every mark has always carried
            // (generateMarkId() in document-highlighter-script.blade.php) —
            // preserved as-is so the existing client/server contract (marks
            // matched by this id across saves) needs no change. Unique per
            // document — a mark's identity, not reused.
            $table->string('mark_uid', 64);

            $table->enum('type', ['highlight', 'note']);
            $table->unsignedInteger('page'); // 0-based page index

            // Highlight-only.
            $table->json('points')->nullable();
            $table->unsignedSmallInteger('width')->nullable();

            // Note-only.
            $table->decimal('x', 10, 3)->nullable();
            $table->decimal('y', 10, 3)->nullable();
            $table->text('text')->nullable();

            // Shared — which highlighter/category drew it (colour resolves
            // live against this, never copied — "the same pen, refilled
            // with different ink").
            $table->foreignId('highlighter_id')->nullable()
                ->constrained('rental_application_highlighters')->nullOnDelete();

            // Provenance. Null author_* is the legacy/"unattributed" case
            // that already existed pre-ownership-scheme — kept, not
            // backfilled, so nothing is guessed at.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 100)->nullable();
            $table->enum('author_role', ['agent', 'authoriser'])->nullable();

            // OCR-readiness — unused by anything human-drawn today.
            $table->enum('source', ['human', 'ocr'])->default('human');
            $table->decimal('confidence', 5, 4)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['document_id', 'mark_uid']);
            $table->index(['document_id', 'page']);
            $table->index(['document_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_document_marks');
    }
};
