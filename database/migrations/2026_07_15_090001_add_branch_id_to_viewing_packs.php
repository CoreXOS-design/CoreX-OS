<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-112 — branch tier for viewing-pack role-visibility.
 *
 * A pack is owned by an agent (`agent_id`) and lives under an agency
 * (`agency_id` + AgencyScope). AT-112 adds ROLE-level visibility WITHIN an
 * agency — agent sees own, branch manager sees branch, admin sees all — which
 * needs a branch tier, exactly as Presentation / Deal / Contact carry one.
 *
 * Nullable + nullOnDelete, mirroring every other BelongsToBranch table: a pack
 * created with no branch context (console, legacy) is agency-visible, never
 * FK-broken. Backfilled from the owning agent's branch so existing packs land
 * in the right branch tier the moment Split Branches is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewing_packs', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('agent_id')
                ->constrained('branches')->nullOnDelete();
            $table->index(['agency_id', 'branch_id']);
        });

        // Backfill from the owning agent's branch so existing packs are correctly
        // tiered before Split Branches is ever enabled.
        DB::statement('
            UPDATE viewing_packs vp
            JOIN users u ON u.id = vp.agent_id
            SET vp.branch_id = u.branch_id
            WHERE vp.branch_id IS NULL AND u.branch_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('viewing_packs', function (Blueprint $table) {
            $table->dropIndex(['agency_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
