<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tool_history_entries.ref carried a bare global UNIQUE(ref) from before this
 * table had agency_id at all (2026_05_23_050700 added agency_id later and
 * never revisited the index) — same bug class already fixed once for
 * properties.external_id (2026_08_14_162800). ToolsController::generateToolRef()
 * computes the next per-agency sequence number correctly (its query goes
 * through Eloquent, so AgencyScope already limits the max() to the caller's
 * own agency) — but the DB-level uniqueness was never agency-scoped, so any
 * agency other than whichever one first claimed a given {prefix}{year}-{n}
 * ref (in practice, HFC, agency 1, the oldest tenant) got a 1062 on their
 * own first-ever entry for that type+year. Confirmed live 2026-08-20:
 * agency 17 (Demo Agency Test) 500'd on every "Print Commission Summary"
 * click because HF-2026-CALC-0001 already belonged to agency 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_history_entries', function (Blueprint $table) {
            $table->dropUnique('tool_history_entries_ref_unique');
            $table->unique(['agency_id', 'ref'], 'tool_history_entries_agency_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tool_history_entries', function (Blueprint $table) {
            $table->dropUnique('tool_history_entries_agency_ref_unique');
            $table->unique('ref');
        });
    }
};
