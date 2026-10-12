<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-450 — GLOBAL reference rows the Title Deed Register needs, provisioned by a migration backfill (BUILD_STANDARD §8,
 * "reference data travels with the deploy"). Idempotent: checks first, including soft-deleted rows, so a re-run never
 * resurrects a deliberately retired row.
 *
 *  - document_types: "Title deed (copy)" — the type the scan is filed under.
 *  - notification_event_types: `title_deed.collected` — the responsible agent is told when a deed is collected.
 *
 * (The permission keys come from config/corex-permissions.php through `corex:sync-permissions --merge-defaults`, which
 *  `deploy:sync-reference-data` already runs.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('document_types')->where('slug', 'title_deed_copy')->exists()) {
            DB::table('document_types')->insert([
                'slug' => 'title_deed_copy',
                'label' => 'Title deed (copy)',
                'grouping' => 'shared',
                'sort_order' => (int) (DB::table('document_types')->max('sort_order') ?? 0) + 1,
                'is_active' => true,
            ]);
        }

        if (! DB::table('notification_event_types')->where('key', 'title_deed.collected')->exists()) {
            DB::table('notification_event_types')->insert([
                'key'             => 'title_deed.collected',
                'pillar'          => 'property',
                'group_label'     => 'Documents',
                'label'           => 'Title deed collected',
                'description'     => 'A title deed you are responsible for has been collected from the office.',
                'default_enabled' => 1,
                'threshold_unit'  => 'none',
                'supports_in_app' => 1,
                'supports_email'  => 1,
                'supports_push'   => 0,
                'is_adapter'      => 0,
                'sort_order'      => 910,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_event_types')->where('key', 'title_deed.collected')->delete();
        DB::table('document_types')->where('slug', 'title_deed_copy')->delete();
    }
};
