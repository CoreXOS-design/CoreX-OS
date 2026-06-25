<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-wide default ordering for the Properties list.
 *
 * Agency settings live as columns on the `agencies` table (existing pattern —
 * see presentations_*, outreach_live_deal_statuses, etc.).
 *
 *  - properties_sort_mode: 'created' (newest first — current behaviour) or
 *    'status_priority' (order by the admin-defined status sequence below).
 *  - properties_status_priority: ordered JSON list of status names. Properties
 *    whose status is not in the list sort last, then by newest within each rank.
 *
 * Spec: .ai/specs/listings.md (Properties list ordering).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'properties_sort_mode')) {
                $table->string('properties_sort_mode', 32)
                    ->default('created')
                    ->after('split_branches_enabled');
            }
            if (!Schema::hasColumn('agencies', 'properties_status_priority')) {
                $table->json('properties_status_priority')
                    ->nullable()
                    ->after('properties_sort_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['properties_status_priority', 'properties_sort_mode'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
