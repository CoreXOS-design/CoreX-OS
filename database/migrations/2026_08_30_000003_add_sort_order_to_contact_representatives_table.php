<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-30: "they are added and in whichever order they got
 * added is what the company document starts to render... so we have to
 * build sorting onto the representatives on the contacts as well."
 * Contact::representatives() has no ORDER BY today — the DB returns
 * rows in whatever order it chooses. This column is the permanent,
 * company-level order an agent sets on the contact's own
 * Representatives panel; e-sign's own per-document reorder (already
 * shipped) starts from whatever this says and layers on top of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_representatives', function ($table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_primary');
        });

        // Backfill: preserve whatever order each entity's representatives
        // happen to return in today (id ascending, a stable proxy for
        // insertion order) so no existing company's document visibly
        // reorders itself the moment this column exists — only a
        // deliberate agent action changes anything from here.
        $rows = DB::table('contact_representatives')
            ->whereNull('deleted_at')
            ->orderBy('entity_contact_id')
            ->orderBy('id')
            ->get(['id', 'entity_contact_id']);

        $counters = [];
        foreach ($rows as $row) {
            $next = $counters[$row->entity_contact_id] ?? 0;
            DB::table('contact_representatives')->where('id', $row->id)->update(['sort_order' => $next]);
            $counters[$row->entity_contact_id] = $next + 1;
        }
    }

    public function down(): void
    {
        Schema::table('contact_representatives', function ($table) {
            $table->dropColumn('sort_order');
        });
    }
};
