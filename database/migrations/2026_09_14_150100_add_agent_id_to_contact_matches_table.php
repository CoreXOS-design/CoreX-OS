<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's ruling — the root cause he flagged: a
 * ContactMatch never recorded which agent it belongs to. Ownership was
 * only ever inferred from `created_by_user_id` (whoever clicked create),
 * with no duplicate check against contact_id and no uniqueness
 * constraint — two different agents could independently hold a match on
 * the same buyer with nothing to notice.
 *
 * `agent_id` is the new, explicit, OWNING agent — the one field
 * reassignment (Task 3) actually moves. `created_by_user_id` is left
 * untouched as the historical "who clicked create" fact.
 *
 * Backfill for existing rows: agent_id = created_by_user_id. Unlike the
 * portal_leads case, this is not a reconstruction — created_by_user_id
 * is already a directly-recorded fact for every existing row, just not
 * previously labelled as the owning agent. Reported to the conductor
 * before running, per instruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('contact_matches')->whereNull('agent_id')->update([
            'agent_id' => DB::raw('created_by_user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
