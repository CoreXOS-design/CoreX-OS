<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — six-colour mark ownership, 2026-09-08. Johan approved the design
 * from the earlier ownership investigation: a per-save optimistic-concurrency
 * check so a genuine collision (two people saving the same document's marks
 * around the same time) is REJECTED and made visible, rather than one save
 * silently clobbering the other. `marks_version` increments by one on every
 * successful save; the client must send back the version it loaded
 * (`base_version`) and the save is refused if it has since moved on.
 *
 * Per-mark author/category/id live INSIDE marks_json (already a flexible
 * JSON column) — no new columns needed for those; this migration only adds
 * the row-level version counter, since that is the one piece that needs to
 * be atomically comparable/incrementable at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_document_highlights', function (Blueprint $table) {
            $table->unsignedInteger('marks_version')->default(0)->after('marks_json');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_document_highlights', function (Blueprint $table) {
            $table->dropColumn('marks_version');
        });
    }
};
