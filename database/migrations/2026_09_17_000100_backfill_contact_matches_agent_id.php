<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prod-promotion audit 2026-09-16, H2 — Core Matches reassignment was inert.
 *
 * Migration 2026_09_14_150100 added contact_matches.agent_id (the OWNING
 * agent, the one field reassignTo() moves) and backfilled it from
 * created_by_user_id for every row that existed at that moment. No
 * creation path stamped agent_id afterwards, so every match created on
 * Staging/prod between that migration and the model-level default
 * (ContactMatch::boot() creating hook, same commit as this file) has
 * agent_id NULL: invisible to the agent_id-scoped board, blank on
 * "Assigned to", and NULL as from_agent_id on its first reassignment.
 *
 * Same rule as the original backfill — created_by_user_id is a directly
 * recorded fact, just not yet labelled as the owner. Rows that already
 * have an agent_id (including reassigned ones) are never touched.
 * Idempotent; safe to re-run. down() is deliberately a no-op: there is
 * no "un-backfill" that would not destroy real ownership data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_matches')
            || ! Schema::hasColumn('contact_matches', 'agent_id')
            || ! Schema::hasColumn('contact_matches', 'created_by_user_id')) {
            return;
        }

        DB::table('contact_matches')
            ->whereNull('agent_id')
            ->whereNotNull('created_by_user_id')
            ->update(['agent_id' => DB::raw('created_by_user_id')]);
    }

    public function down(): void
    {
        // Intentionally a no-op — see class docblock.
    }
};
