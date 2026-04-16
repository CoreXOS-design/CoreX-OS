<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy hardening: add agency_id to every pillar table that was
 * missing it, backfill from the owning relationship, then index for fast
 * scoped lookups.
 *
 * Leak context: staging was showing contacts, deals, presentations and
 * documents across agency boundaries because these tables had no agency
 * column at all, so the global scope had nothing to constrain on.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['contacts', 'deals', 'presentations', 'documents'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('agency_id')->nullable();
                });
            }
        }

        $this->backfill();

        foreach (['contacts', 'deals', 'presentations', 'documents'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->index('agency_id', "{$table}_agency_id_index");
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['contacts', 'deals', 'presentations', 'documents'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'agency_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                try { $t->dropIndex("{$table}_agency_id_index"); } catch (\Throwable $e) {}
                $t->dropColumn('agency_id');
            });
        }
    }

    private function backfill(): void
    {
        // contacts: via created_by_user_id → users.agency_id, then via branch
        DB::statement("
            UPDATE contacts
            SET agency_id = (
                SELECT u.agency_id FROM users u
                WHERE u.id = contacts.created_by_user_id
            )
            WHERE agency_id IS NULL
        ");
        DB::statement("
            UPDATE contacts
            SET agency_id = (
                SELECT b.agency_id FROM users u
                JOIN branches b ON b.id = u.branch_id
                WHERE u.id = contacts.created_by_user_id
            )
            WHERE agency_id IS NULL
        ");

        // deals: via deals.branch_id → branches.agency_id
        DB::statement("
            UPDATE deals
            SET agency_id = (
                SELECT b.agency_id FROM branches b WHERE b.id = deals.branch_id
            )
            WHERE agency_id IS NULL
        ");

        // presentations: via presentations.branch_id → branches.agency_id
        DB::statement("
            UPDATE presentations
            SET agency_id = (
                SELECT b.agency_id FROM branches b WHERE b.id = presentations.branch_id
            )
            WHERE agency_id IS NULL
        ");
        // presentations: via creator user as fallback
        if (Schema::hasColumn('presentations', 'created_by_user_id')) {
            DB::statement("
                UPDATE presentations
                SET agency_id = (
                    SELECT u.agency_id FROM users u WHERE u.id = presentations.created_by_user_id
                )
                WHERE agency_id IS NULL
            ");
        }

        // documents: via uploaded_by → users.agency_id
        DB::statement("
            UPDATE documents
            SET agency_id = (
                SELECT u.agency_id FROM users u WHERE u.id = documents.uploaded_by
            )
            WHERE agency_id IS NULL
        ");

        // Final safety net: assign any remaining orphans to the first agency.
        // Leaving them NULL would make them visible across all agencies; for
        // pre-existing production data that is strictly worse than assigning
        // them to the historical tenant.
        $firstAgencyId = DB::table('agencies')->orderBy('id')->value('id');
        if ($firstAgencyId) {
            foreach (['contacts', 'deals', 'presentations', 'documents'] as $table) {
                DB::table($table)->whereNull('agency_id')->update(['agency_id' => $firstAgencyId]);
            }
        }
    }
};
