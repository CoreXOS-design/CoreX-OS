<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 §B.9 leftovers — a reliable "a person has worked on this template" marker.
 *
 * property_ad_templates.human_edited_at is set whenever a person saves a template through the Ad Builder (create or update).
 * It is NOT set by the AI Template Builder's own save, by archive, or by restore. "Match our existing templates" uses it to
 * tell a polished, person-touched AI template from an untouched one.
 *
 * Existing rows: before this column the only hint was the old updated_at heuristic (an AI template whose updated_at is more
 * than 5 seconds after its created_at). That rule is applied ONCE here, to the rows that exist now, and recorded in the new
 * column - so after this migration there is exactly one source of truth and no date cut-off to maintain. (Ad Builder-made
 * templates need no backfill: they are ai_generated = false, which already counts as made by a person.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_ad_templates', function (Blueprint $table) {
            $table->timestamp('human_edited_at')->nullable()->after('ai_idea');
        });

        DB::table('property_ad_templates')
            ->where('ai_generated', true)
            ->whereNull('human_edited_at')
            ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, created_at, updated_at)) > 5')
            ->update(['human_edited_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('property_ad_templates', function (Blueprint $table) {
            $table->dropColumn('human_edited_at');
        });
    }
};
