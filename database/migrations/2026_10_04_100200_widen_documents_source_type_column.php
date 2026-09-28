<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §41, 2026-09-28 — found running the real distribution build against QA1
 * data: `documents.source_type` was `varchar(20)`, too short for
 * 'rental_inspection_report' (25 chars) — the filing insert failed with
 * SQLSTATE 22001 every time (confirmed live, property 5577 inspection 20).
 *
 * Widened rather than shortening the source_type string this build uses:
 * `source_type` is a SHARED, generic categorisation column read by every
 * Document consumer, and this distribution service is explicitly built
 * for MORE THAN ONE consumer (Inventory next, per Johan's ruling) — a
 * 20-char cap would silently re-trip the same wall for the next
 * consumer's own source_type value. 50 matches the width already used
 * for the same kind of column elsewhere in this codebase (e.g.
 * presentation_url_snapshots.source_type) — non-breaking, every existing
 * shorter value stays valid unchanged.
 */
return new class extends Migration
{
    // Raw SQL, not Schema::table()->change() — this codebase doesn't
    // depend on doctrine/dbal (confirmed: not in composer.json), which
    // Laravel's fluent column-modification helper requires under the hood.
    public function up(): void
    {
        DB::statement("ALTER TABLE documents MODIFY source_type VARCHAR(50) NOT NULL DEFAULT 'upload'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE documents MODIFY source_type VARCHAR(20) NOT NULL DEFAULT 'upload'");
    }
};
