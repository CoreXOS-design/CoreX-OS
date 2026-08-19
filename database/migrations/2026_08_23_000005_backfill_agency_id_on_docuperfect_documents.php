<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `docuperfect_documents.agency_id` via
 * `docuperfect_documents.owner_id` → `users.agency_id`.
 *
 * `owner_id` is a NOT NULL, FK-enforced column on docuperfect_documents
 * (see 2026_02_24_400003_create_docuperfect_documents_table.php — every
 * row has always had an owner), so this is a reliable, universal source —
 * unlike the rental_properties precedent's nullable/FK-less `created_by`,
 * every document row is expected to backfill cleanly here. Rows still left
 * NULL (owner user deleted, or an agency-less System Owner as the owner)
 * are reported — the follow-up NOT NULL migration refuses to advance if
 * any remain.
 */
return new class extends Migration {
    public function up(): void
    {
        $affected = DB::update(
            'UPDATE docuperfect_documents dd '
            . 'INNER JOIN users u ON u.id = dd.owner_id '
            . 'SET dd.agency_id = u.agency_id '
            . 'WHERE dd.agency_id IS NULL AND u.agency_id IS NOT NULL'
        );

        $stillNull = DB::table('docuperfect_documents')->whereNull('agency_id')->count();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "    -> docuperfect_documents backfill: set agency_id via owner_id/users on {$affected} row(s) (still-null: {$stillNull})" . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Reverse the backfill so the "add column" migration's down() can
        // drop the column cleanly.
        DB::table('docuperfect_documents')->update(['agency_id' => null]);
    }
};
