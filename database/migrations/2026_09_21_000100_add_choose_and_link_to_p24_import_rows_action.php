<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-423 — importer.md §15. Two new agent-row actions:
 *   choose — email cannot identify one person (blank / shared Team Inbox / repeated in the
 *            file); the admin must pick who this is, or skip.
 *   link   — the admin picked an existing person; the P24 ids go onto them, no email match.
 * Existing values and the default are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE p24_import_rows MODIFY `action` ENUM('create','update','skip','choose','link') NOT NULL DEFAULT 'create'");
    }

    public function down(): void
    {
        // Map the new values back onto the nearest old ones before narrowing the enum.
        DB::table('p24_import_rows')->where('action', 'choose')->update(['action' => 'skip']);
        DB::table('p24_import_rows')->where('action', 'link')->update(['action' => 'update']);
        DB::statement("ALTER TABLE p24_import_rows MODIFY `action` ENUM('create','update','skip') NOT NULL DEFAULT 'create'");
    }
};
