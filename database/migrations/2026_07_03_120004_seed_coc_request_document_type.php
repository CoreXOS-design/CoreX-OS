<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-158 DR2 · WS4 (§8.3) — the COC-request document type (global reference).
 *
 * The auto-generated COC request is filed as a Document of this type. It is a
 * must-travel GLOBAL reference row, so it is provisioned by this migration
 * backfill (AT-162) rather than a seeder — seeders do not run on a git-pull
 * deploy. Idempotent: insertOrIgnore on the unique slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('document_types')->where('slug', 'coc_request')->exists();
        if (! $exists) {
            DB::table('document_types')->insert([
                'slug'       => 'coc_request',
                'label'      => 'COC Request',
                'sort_order' => 900,
                'is_active'  => 1,
                'grouping'   => 'shared',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Soft-remove only; never hard-delete a reference row other data may point at.
        DB::table('document_types')->where('slug', 'coc_request')->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }
};
